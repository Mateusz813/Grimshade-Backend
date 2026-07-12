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

final class BossController extends Controller
{
    private const MAX_DAILY_ATTEMPTS = 3;

    public function resolve(
        Request $request,
        ContentRepository $content,
        RngInterface $rng,
        CharacterStateService $state,
    ): JsonResponse {
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
        ]);
        $requestId = $data['requestId'];

        $cacheKey = "boss.resolve.{$character->id}.{$requestId}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $bossId = (string) $request->route('bossId');
        $boss = collect($content->get('bosses'))->firstWhere('id', $bossId);
        if ($boss === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego bossa.');
        }

        if ((int) $character->level < (int) $boss['level']) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za niski poziom postaci na tego bossa.');
        }

        $maxAttempts = (int) ($boss['dailyAttempts'] ?? self::MAX_DAILY_ATTEMPTS);

        $payload = DB::transaction(function () use ($character, $boss, $bossId, $maxAttempts, $rng, $state, $content): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $today = now()->toDateString();
            $entry = $save->state['bosses']['dailyAttempts'][$bossId] ?? null;
            $usedToday = (is_array($entry) && ($entry['date'] ?? null) === $today)
                ? (int) ($entry['used'] ?? 0)
                : 0;
            if ($usedToday >= $maxAttempts) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Wyczerpany dzienny limit prób na tego bossa.');
            }

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

                $lvl = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, (int) $result['xp']);
                $fresh->level = $lvl['newLevel'];
                $fresh->xp = $lvl['remainingXp'];
                $fresh->stat_points += $lvl['statPointsGained'];
                $fresh->highest_level = max((int) $fresh->highest_level, $lvl['newLevel']);
                $fresh->hp = (int) $result['playerHpLeft'];
                $levelsGained = $lvl['levelsGained'];
                $newLevel = $lvl['newLevel'];
                $fresh->save();

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
