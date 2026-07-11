<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Combat\HuntResolver;
use App\Domain\Content\ContentRepository;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Loot\LootSystem;
use App\Domain\Support\Rng\RngInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveCombatRequest;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorytatywne rozstrzygnięcie walki solo-hunt. Serwer:
 *  - bierze tożsamość z tokenu, postać ze swojej bazy (nie z body),
 *  - waliduje level gate,
 *  - symuluje walkę + rolluje nagrody WŁASNYM RNG,
 *  - zapisuje autorytatywnie: level/xp/hp/stat_points → characters,
 *    GOLD + dropy (kamienie/potiony) → blob game_saves (inventory.gold to
 *    PRAWDZIWA waluta gry — characters.gold jest szczątkowe),
 *  - idempotencja po requestId.
 */
final class CombatController extends Controller
{
    public function resolve(
        ResolveCombatRequest $request,
        ContentRepository $content,
        RngInterface $rng,
        CharacterStateService $state,
    ): JsonResponse {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $requestId = (string) $request->validated('requestId');
        $cacheKey = "combat.resolve.{$character->id}.{$requestId}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $monsterId = (string) $request->validated('monsterId');
        $monster = collect($content->get('monsters'))->firstWhere('id', $monsterId);
        if ($monster === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego potwora.');
        }
        if ((int) $monster['level'] > (int) $character->level) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za niski poziom postaci na tego potwora.');
        }

        $payload = DB::transaction(function () use ($character, $monster, $rng, $state, $content): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $result = (new HuntResolver($rng))->resolve([
                'attack' => $fresh->attack,
                'defense' => $fresh->defense,
                'hp' => $fresh->hp,
                'max_hp' => $fresh->max_hp,
                'crit_chance' => (float) $fresh->crit_chance,
                'crit_damage' => (float) $fresh->crit_damage,
                'level' => $fresh->level,
                'xp' => $fresh->xp,
            ], $monster);

            $fresh->hp = (int) $result['playerHp'];
            $drops = ['stones' => null, 'potions' => [], 'items' => []];

            if ($result['won']) {
                $fresh->level = (int) $result['newLevel'];
                $fresh->xp = (int) $result['remainingXp'];
                $fresh->stat_points += (int) $result['statPointsGained'];
                $fresh->highest_level = max((int) $fresh->highest_level, (int) $result['newLevel']);

                // Gold → blob (prawdziwa waluta gry), NIE characters.gold.
                $state->addGold($save, (int) $result['goldGained']);

                // Dropy kamieni/potek — serwerowy roll, kolejność jak w engine.
                $stone = LootSystem::rollStoneDrop($rng, (int) $monster['level'], $result['monsterRarity']);
                if ($stone !== null) {
                    $state->addStones($save, $stone['type'], $stone['count']);
                    $drops['stones'] = $stone;
                }
                foreach (LootSystem::rollPotionDrop($rng, (int) $monster['level']) as $potion) {
                    $state->addConsumable($save, $potion['potionId'], $potion['count']);
                    $drops['potions'][] = $potion;
                }

                // Dropy itemów (serwerowa generacja — ItemGenerator).
                $generator = new ItemGenerator($content->get('itemTemplates'), $rng);
                foreach (LootSystem::rollLoot($rng, (int) $monster['level'], $result['monsterRarity'], 0.0, $generator) as $item) {
                    $state->addBagItem($save, $item);
                    $drops['items'][] = $item;
                }

                $state->persist($save);
            }
            $fresh->save();

            return [
                'result' => [...$result, 'drops' => $drops],
                'character' => (new CharacterResource($fresh))->resolve(),
                'gold' => $state->gold($save),
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }
}
