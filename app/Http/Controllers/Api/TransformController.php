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

final class TransformController extends Controller
{
    private const MAX_ROUNDS = 500;

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

        if (! $system->isLevelSufficient((int) $character->level, $transformId)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za niski poziom postaci na tę transformację.');
        }

        $payload = DB::transaction(function () use ($character, $transformId, $transform, $system, $rng, $state): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $completed = $blob['transforms']['completedTransforms'] ?? [];

            if (in_array($transformId, $completed, true)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Ta transformacja jest już ukończona.');
            }

            for ($i = 1; $i < $transformId; $i++) {
                if (! in_array($i, $completed, true)) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Musisz najpierw ukończyć wcześniejsze transformacje.');
                }
            }

            $boss = TransformSystem::applyTransformBossStats(
                $system->generateTransformBossMonster((int) $transform['level']),
            );

            $fight = $this->simulateFight($rng, $fresh, $boss);

            $fresh->hp = (int) $fight['playerHp'];
            $fresh->save();

            if ($fight['won']) {
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

    public function claim(
        Request $request,
        ContentRepository $content,
        CharacterStateService $state,
    ): JsonResponse {
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

            $rewards = $system->calculateTransformRewardsDeterministic($transformId, (string) $fresh->class);

            $completed = $blob['transforms']['completedTransforms'] ?? [];
            if (! in_array($transformId, $completed, true)) {
                $completed[] = $transformId;
            }
            $blob['transforms']['completedTransforms'] = array_values($completed);
            $blob['transforms']['pendingClaimTransformId'] = null;
            $blob['transforms']['currentTransformQuest'] = null;
            $save->state = $blob;

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
