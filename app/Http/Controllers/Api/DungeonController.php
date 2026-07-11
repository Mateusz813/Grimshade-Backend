<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Content\ContentRepository;
use App\Domain\Dungeon\DungeonSystem;
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
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorytatywne rozstrzygnięcie fal lochu. Serwer:
 *  - bierze tożsamość z tokenu, postać ze swojej bazy (nie z body),
 *  - waliduje min-level lochu (DungeonSystem::getDungeonMinLevel),
 *  - egzekwuje dzienny limit prób (blob game_saves state.dungeons.dailyAttempts),
 *  - symuluje CAŁY loch WŁASNYM RNG (DungeonSystem::resolveDungeon: skalowanie fal
 *    wg tieru lochu + mnożniki typu, deterministyczna pętla resolveWave, dropy
 *    itemów/gold rollowane serwerowo),
 *  - zapisuje autorytatywnie: xp/level/stat_points/hp → characters; GOLD + loot →
 *    blob game_saves (inventory.gold = PRAWDZIWA waluta); slice dungeons
 *    (dailyAttempts/clearedDungeonIds/lastResult) → blob,
 *  - idempotencja po requestId.
 *
 * Semantyka 1:1 z frontem (dungeonStore + dungeonSystem.resolveDungeon): atrybut
 * dzienny liczony PER UKOŃCZENIE (setDungeonCompleted woła się tylko przy sukcesie),
 * limit MAX_DAILY_ATTEMPTS = 5. Porażka (nie wszystkie fale) NIE konsumuje próby.
 */
final class DungeonController extends Controller
{
    /** Domyślny dzienny limit ukończeń lochu (dungeonStore.MAX_DAILY_ATTEMPTS). */
    private const MAX_DAILY_ATTEMPTS = 5;

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

        $cacheKey = "dungeon.resolve.{$character->id}.{$requestId}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        // 2 parametry trasy — {dungeonId} czytamy jawnie (Laravel gubi wiązanie).
        $dungeonId = (string) $request->route('dungeonId');
        $dungeon = collect($content->get('dungeons'))->firstWhere('id', $dungeonId);
        if ($dungeon === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego lochu.');
        }

        // Min-level (canEnterDungeon: level postaci >= minLevel lochu).
        $minLevel = DungeonSystem::getDungeonMinLevel($dungeon);
        if ((int) $character->level < $minLevel) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za niski poziom postaci na ten loch.');
        }

        $maxAttempts = (int) ($dungeon['dailyAttempts'] ?? self::MAX_DAILY_ATTEMPTS);

        $payload = DB::transaction(function () use ($character, $dungeon, $dungeonId, $maxAttempts, $rng, $state, $content): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            // Dzienny limit — liczony z zablokowanego blobu (race-safe).
            $today = now()->toDateString();
            $entry = $save->state['dungeons']['dailyAttempts'][$dungeonId] ?? null;
            $usedToday = (is_array($entry) && ($entry['date'] ?? null) === $today)
                ? (int) ($entry['used'] ?? 0)
                : 0;
            if ($usedToday >= $maxAttempts) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Wyczerpany dzienny limit prób na ten loch.');
            }

            // Pełna symulacja lochu (skalowanie fal + resolveWave + dropy/gold roll).
            $generator = new ItemGenerator($content->get('itemTemplates'), $rng);
            $sim = DungeonSystem::resolveDungeon(
                $dungeon,
                [
                    'attack' => (int) $fresh->attack,
                    'defense' => (int) $fresh->defense,
                    'max_hp' => (int) $fresh->max_hp,
                    'level' => (int) $fresh->level,
                ],
                $content->get('monsters'),
                $rng,
                $generator,
            );
            $result = $sim['result'];

            $levelsGained = 0;
            $newLevel = (int) $fresh->level;
            $grantedItems = [];

            if ($result['success']) {
                // Loot: materializacja dropów lochu jako itemy do torby (uuid serwerowy).
                foreach ($result['items'] as $drop) {
                    $grantedItems[] = [
                        'uuid' => (string) Str::uuid(),
                        'itemId' => $drop['itemId'],
                        'rarity' => $drop['rarity'],
                        'bonuses' => $drop['bonuses'],
                        'itemLevel' => (int) $drop['itemLevel'],
                        'upgradeLevel' => 0,
                    ];
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

                // Slice dungeons (dailyAttempts/clearedDungeonIds/lastResult) → blob.
                // clearedDungeonIds = Record<id,true> (jak dungeonStore, nie lista).
                $blob = $save->state;
                $blob['dungeons']['dailyAttempts'][$dungeonId] = ['used' => $usedToday + 1, 'date' => $today];
                $blob['dungeons']['clearedDungeonIds'][$dungeonId] = true;
                $blob['dungeons']['lastResult'] = [
                    'dungeonId' => $dungeonId,
                    'success' => true,
                    'wavesCleared' => (int) $result['wavesCleared'],
                    'playerHpLeft' => (int) $result['playerHpLeft'],
                    'gold' => (int) $result['gold'],
                    'xp' => (int) $result['xp'],
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
                    'success' => (bool) $result['success'],
                    'wavesCleared' => (int) $result['wavesCleared'],
                    'playerHpLeft' => (int) $result['playerHpLeft'],
                    'gold' => (int) $result['gold'],
                    'xp' => (int) $result['xp'],
                    'items' => $grantedItems,
                ],
                'waveResults' => $sim['waveResults'],
                'character' => (new CharacterResource($fresh))->resolve(),
                'gold' => $state->gold($save),
                'levelsGained' => $levelsGained,
                'newLevel' => $newLevel,
                'attemptsUsed' => $result['success'] ? $usedToday + 1 : $usedToday,
                'attemptsMax' => $maxAttempts,
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }
}
