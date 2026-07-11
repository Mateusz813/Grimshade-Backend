<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Combat\CombatMath;
use App\Domain\Content\ContentRepository;
use App\Domain\Support\Rng\RngInterface;
use App\Domain\Transform\TransformSystem;
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
 * Autorytatywna progresja transformacji. Serwer:
 *  - bierze tożsamość z tokenu, postać ze swojej bazy (nie z body),
 *  - waliduje próg poziomu (isLevelSufficient) i KOLEJNOŚĆ (wszystkie
 *    wcześniejsze transformacje muszą być ukończone) — semantyka 1:1 z
 *    transformStore.startTransformQuest,
 *  - symuluje walkę z transform-bossem WŁASNYM RNG (mini-resolver na
 *    CombatMath), staty bossa = TransformSystem::generateTransformBossMonster
 *    (scaleMonsterStats) + TRANSFORM_BOSS_MULTIPLIER (applyTransformBossStats),
 *  - na wygraną zapisuje pendingClaimTransformId → blob (slice transforms),
 *  - claim dopisuje completedTransforms + deterministyczne nagrody
 *    (consumables → blob; permanentBonuses aplikują się LIVE z listy
 *    completedTransforms, więc NIE wypiekamy ich w kolumny — jak front
 *    po Point 7, patrz transformStore.handleCompleteTransform),
 *  - idempotencja: resolve po requestId (Cache), claim naturalna
 *    (pendingClaimTransformId znika po odbiorze → drugi claim = 404).
 *
 * Blob slice `transforms` (blob game_saves): completedTransforms[],
 * currentTransformQuest{}, pendingClaimTransformId — dokładnie te pola co
 * useTransformStore (persist per-postać przez characterScope).
 */
final class TransformController extends Controller
{
    /** Twardy limit rund mini-resolvera (jak HuntResolver). */
    private const MAX_ROUNDS = 500;

    /**
     * Rozstrzygnięcie walki z transform-bossem. Wygrana ustawia pending claim.
     */
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

        // 2 parametry trasy — {transformId} czytamy jawnie (Laravel gubi wiązanie).
        $transformId = (int) $request->route('transformId');

        $cacheKey = "transform.resolve.{$character->id}.{$requestId}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $system = new TransformSystem($content->get('transforms'), $content->get('monsters'));
        $transform = $system->getTransformById($transformId);
        if ($transform === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiej transformacji.');
        }

        // Próg poziomu (isLevelSufficient: level postaci >= level transformacji).
        if (! $system->isLevelSufficient((int) $character->level, $transformId)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za niski poziom postaci na tę transformację.');
        }

        $payload = DB::transaction(function () use ($character, $transformId, $transform, $system, $rng, $state): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $completed = $blob['transforms']['completedTransforms'] ?? [];

            // Już ukończona — nic do rozstrzygania.
            if (in_array($transformId, $completed, true)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Ta transformacja jest już ukończona.');
            }

            // Kolejność wymuszona: wszystkie wcześniejsze muszą być ukończone.
            for ($i = 1; $i < $transformId; $i++) {
                if (! in_array($i, $completed, true)) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Musisz najpierw ukończyć wcześniejsze transformacje.');
                }
            }

            // Boss questu: scaleMonsterStats(level) + TRANSFORM_BOSS_MULTIPLIER.
            $boss = TransformSystem::applyTransformBossStats(
                $system->generateTransformBossMonster((int) $transform['level']),
            );

            $fight = $this->simulateFight($rng, $fresh, $boss);

            // HP postaci autorytatywnie z symulacji (i przy wygranej, i przy porażce).
            $fresh->hp = (int) $fight['playerHp'];
            $fresh->save();

            if ($fight['won']) {
                // Blob: pending claim + stan questu (all-defeated, jak front).
                $blob['transforms']['pendingClaimTransformId'] = $transformId;
                $blob['transforms']['currentTransformQuest'] = [
                    'transformId' => $transformId,
                    'monstersDefeated' => [$boss['id']],
                    'totalMonsters' => $system->getTransformMonsterCount($transformId),
                    'inProgress' => true,
                ];
                $save->state = $blob;
                $state->persist($save);
            }

            return [
                'result' => [
                    'won' => (bool) $fight['won'],
                    'rounds' => (int) $fight['rounds'],
                    'playerHp' => (int) $fight['playerHp'],
                    'boss' => [
                        'id' => $boss['id'],
                        'level' => (int) $boss['level'],
                        'hp' => (int) $boss['hp'],
                        'attack' => (int) $boss['attack'],
                        'defense' => (int) $boss['defense'],
                    ],
                ],
                'transformId' => $transformId,
                'pendingClaimTransformId' => $fight['won']
                    ? $transformId
                    : ($blob['transforms']['pendingClaimTransformId'] ?? null),
                'character' => (new CharacterResource($fresh))->resolve(),
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * Odbiór nagród transformacji: dopisuje completedTransforms + deterministyczne
     * consumables → blob. permanentBonuses zwracane do wglądu (aplikują się LIVE).
     * Idempotencja naturalna: pendingClaimTransformId znika → drugi claim = 404.
     */
    public function claim(
        Request $request,
        ContentRepository $content,
        CharacterStateService $state,
    ): JsonResponse {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $payload = DB::transaction(function () use ($character, $content, $state): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $pending = $blob['transforms']['pendingClaimTransformId'] ?? null;
            if ($pending === null) {
                abort(Response::HTTP_NOT_FOUND, 'Brak nagrody transformacji do odebrania.');
            }
            $transformId = (int) $pending;

            $system = new TransformSystem($content->get('transforms'), $content->get('monsters'));
            if ($system->getTransformById($transformId) === null) {
                abort(Response::HTTP_NOT_FOUND, 'Nie ma takiej transformacji.');
            }

            // Deterministyczne nagrody: consumables + permanentBonuses (klasowe/tier).
            $rewards = $system->calculateTransformRewardsDeterministic($transformId, (string) $fresh->class);

            // Dopisz do completedTransforms (dedupe, kolejność zachowana).
            $completed = $blob['transforms']['completedTransforms'] ?? [];
            if (! in_array($transformId, $completed, true)) {
                $completed[] = $transformId;
            }
            $blob['transforms']['completedTransforms'] = array_values($completed);
            $blob['transforms']['pendingClaimTransformId'] = null;
            $blob['transforms']['currentTransformQuest'] = null;
            $save->state = $blob;

            // Consumables → blob PO ostatnim $save->state = $blob (bug kolejności).
            foreach ($rewards['consumables'] as $consumable) {
                $state->addConsumable($save, (string) $consumable['id'], (int) $consumable['count']);
            }
            $state->persist($save);

            return [
                'transformId' => $transformId,
                'completedTransforms' => $blob['transforms']['completedTransforms'],
                'rewards' => [
                    'consumables' => $rewards['consumables'],
                    'permanentBonuses' => $rewards['permanentBonuses'],
                ],
                'consumables' => $save->state['inventory']['consumables'] ?? [],
            ];
        });

        return response()->json($payload);
    }

    /**
     * Mini-resolver walki tura-po-turze (gracz → boss) na CombatMath. Czysty
     * model jak HuntResolver: crit rollowany serwerowym RNG, mitygacja
     * max(1, dmg - obrona). Boss BEZ rarity (staty już przeskalowane bossem).
     *
     * @param  array<string, mixed>  $boss
     * @return array{won:bool, rounds:int, playerHp:int}
     */
    private function simulateFight(RngInterface $rng, Character $char, array $boss): array
    {
        $monsterHp = (int) $boss['hp'];
        $playerHp = $char->hp > 0 ? (int) $char->hp : (int) $char->max_hp;
        $range = CombatMath::getMonsterAttackRange($boss);
        $rounds = 0;
        $won = false;

        while ($rounds < self::MAX_ROUNDS) {
            $rounds++;

            $isCrit = $rng->nextFloat() < min((float) $char->crit_chance, 0.5);
            $hit = CombatMath::calculateDamage([
                'baseAtk' => $char->attack,
                'weaponAtk' => 0,
                'skillBonus' => 0,
                'classModifier' => 1,
                'enemyDefense' => $boss['defense'],
                'isCrit' => $isCrit,
                'isBlocked' => false,
                'isDodged' => false,
                'critDmg' => (float) $char->crit_damage,
            ]);
            $monsterHp -= $hit['finalDamage'];
            if ($monsterHp <= 0) {
                $won = true;
                break;
            }

            $monsterRoll = $range['min'] + (int) floor($rng->nextFloat() * ($range['max'] - $range['min'] + 1));
            $playerHp -= (int) max(1, $monsterRoll - $char->defense);
            if ($playerHp <= 0) {
                $playerHp = 0;
                break;
            }
        }

        return ['won' => $won, 'rounds' => $rounds, 'playerHp' => max(0, $playerHp)];
    }
}
