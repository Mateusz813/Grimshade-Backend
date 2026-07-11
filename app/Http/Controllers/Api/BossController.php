<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Boss\BossSystem;
use App\Domain\Content\ContentRepository;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Progression\LevelSystem;
use App\Domain\Support\Rng\RngInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorytatywne rozstrzygnięcie walki z bossem. Serwer:
 *  - bierze tożsamość z tokenu, postać ze swojej bazy (nie z body),
 *  - waliduje próg poziomu (canChallengeBoss: level postaci >= level bossa),
 *  - egzekwuje dzienny limit prób (blob game_saves state.bosses.dailyAttempts),
 *  - symuluje walkę statami bossa (BossSystem: ×3.5 HP / ×1.75 ATK / ×1.3 DEF)
 *    WŁASNYM RNG (deterministyczna pętla + loot/gold roll),
 *  - zapisuje autorytatywnie: xp/level/stat_points → characters; GOLD + loot →
 *    blob game_saves (inventory.gold = PRAWDZIWA waluta); slice bosses
 *    (dailyAttempts/clearedIds/lastResult) → blob,
 *  - idempotencja po requestId.
 *
 * Semantyka 1:1 z frontem (Boss.tsx handleBossDeath + bossStore): atrybut
 * dzienny liczony PER WYGRANĄ (setBossDefeated woła się tylko przy pokonaniu),
 * limit MAX_DAILY_ATTEMPTS = 3.
 */
final class BossController extends Controller
{
    /** Domyślny dzienny limit pokonań bossa (bossStore.MAX_DAILY_ATTEMPTS). */
    private const MAX_DAILY_ATTEMPTS = 3;

    public function resolve(
        Request $request,
        ContentRepository $content,
        RngInterface $rng,
        CharacterStateService $state,
    ): JsonResponse {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
        ]);
        $requestId = $data['requestId'];

        $cacheKey = "boss.resolve.{$character->id}.{$requestId}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        // 2 parametry trasy — {bossId} czytamy jawnie (Laravel gubi wiązanie).
        $bossId = (string) $request->route('bossId');
        $boss = collect($content->get('bosses'))->firstWhere('id', $bossId);
        if ($boss === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego bossa.');
        }

        // Próg poziomu (canChallengeBoss: level postaci musi >= level bossa).
        if ((int) $character->level < (int) $boss['level']) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za niski poziom postaci na tego bossa.');
        }

        $maxAttempts = (int) ($boss['dailyAttempts'] ?? self::MAX_DAILY_ATTEMPTS);

        $payload = DB::transaction(function () use ($character, $boss, $bossId, $maxAttempts, $rng, $state, $content): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            // Dzienny limit — liczony z zablokowanego blobu (race-safe).
            $today = now()->toDateString();
            $entry = $save->state['bosses']['dailyAttempts'][$bossId] ?? null;
            $usedToday = (is_array($entry) && ($entry['date'] ?? null) === $today)
                ? (int) ($entry['used'] ?? 0)
                : 0;
            if ($usedToday >= $maxAttempts) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Wyczerpany dzienny limit prób na tego bossa.');
            }

            // Symulacja statami bossa (skala party) + loot/gold roll wewnątrz.
            $result = BossSystem::resolveBoss($rng, $boss, [
                'attack' => $fresh->attack,
                'defense' => $fresh->defense,
                'max_hp' => $fresh->max_hp,
                'level' => $fresh->level,
            ]);

            $levelsGained = 0;
            $newLevel = (int) $fresh->level;
            $grantedItems = [];

            if ($result['won']) {
                // Loot: materializacja unikatowych dropów bossa jako itemy do torby
                // (RNG kontynuuje sekwencję po rollBossLoot/rollBossGold z resolveBoss).
                $generator = new ItemGenerator($content->get('itemTemplates'), $rng);
                foreach ($result['drops'] as $drop) {
                    $item = $generator->generateRandomItemForClass(
                        (string) $fresh->class,
                        (int) $boss['level'],
                        (string) ($drop['rarity'] ?? 'rare'),
                    );
                    if ($item !== null) {
                        $item['bossDropId'] = $drop['itemId'] ?? null;
                        $grantedItems[] = $item;
                    }
                }

                // XP → postać (level-upy, punkty statystyk, highest_level).
                $lvl = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, (int) $result['xp']);
                $fresh->level = $lvl['newLevel'];
                $fresh->xp = $lvl['remainingXp'];
                $fresh->stat_points += $lvl['statPointsGained'];
                $fresh->highest_level = max((int) $fresh->highest_level, $lvl['newLevel']);
                $fresh->hp = (int) $result['playerHpLeft'];
                $levelsGained = $lvl['levelsGained'];
                $newLevel = $lvl['newLevel'];
                $fresh->save();

                // Slice bosses (dailyAttempts/clearedIds/lastResult) → blob.
                $blob = $save->state;
                $blob['bosses']['dailyAttempts'][$bossId] = ['used' => $usedToday + 1, 'date' => $today];
                $blob['bosses']['clearedIds'] = array_values(array_unique([
                    ...($blob['bosses']['clearedIds'] ?? []),
                    $bossId,
                ]));
                $blob['bosses']['lastResult'] = [
                    'bossId' => $bossId,
                    'won' => true,
                    'playerHpLeft' => (int) $result['playerHpLeft'],
                    'turns' => (int) $result['turns'],
                    'gold' => (int) $result['gold'],
                    'xp' => (int) $result['xp'],
                    'drops' => $result['drops'],
                    'items' => $grantedItems,
                ];
                $save->state = $blob;

                // ⚠️ Serwisowe mutacje PO ostatnim $save->state = $blob (bug kolejności):
                // gold → blob, loot → bag.
                $state->addGold($save, (int) $result['gold']);
                foreach ($grantedItems as $item) {
                    $state->addBagItem($save, $item);
                }
                $state->persist($save);
            }

            return [
                'result' => [
                    'won' => (bool) $result['won'],
                    'playerHpLeft' => (int) $result['playerHpLeft'],
                    'turns' => (int) $result['turns'],
                    'gold' => (int) $result['gold'],
                    'xp' => (int) $result['xp'],
                    'drops' => $result['drops'],
                    'items' => $grantedItems,
                ],
                'character' => (new CharacterResource($fresh))->resolve(),
                'gold' => $state->gold($save),
                'levelsGained' => $levelsGained,
                'newLevel' => $newLevel,
                'attemptsUsed' => $result['won'] ? $usedToday + 1 : $usedToday,
                'attemptsMax' => $maxAttempts,
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }
}
