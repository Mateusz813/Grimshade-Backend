<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Combat\CombatElixirs;
use App\Domain\Content\ContentRepository;
use App\Domain\Progression\LevelSystem;
use App\Domain\Progression\TaskRewards;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ProgressionController extends Controller
{
    public function claimTask(Request $request, ContentRepository $content, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');
        $taskId = (string) $request->route('taskId');

        $payload = DB::transaction(function () use ($character, $taskId, $content, $state): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $activeTasks = $blob['tasks']['activeTasks'] ?? [];

            $idx = null;
            foreach ($activeTasks as $i => $t) {
                if (($t['id'] ?? null) === $taskId) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                abort(Response::HTTP_NOT_FOUND, 'Task nie istnieje (lub już odebrany).');
            }

            $task = $activeTasks[$idx];
            if ((int) ($task['progress'] ?? 0) < (int) ($task['killCount'] ?? 0)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Task jeszcze nieukończony.');
            }

            $monster = collect($content->get('monsters'))->firstWhere('id', $task['monsterId'] ?? null);
            $rewards = $monster !== null
                ? (new TaskRewards($content->get('monsters')))->computeTaskRewards($monster, (int) $task['killCount'])
                : ['rewardGold' => (int) ($task['rewardGold'] ?? 0), 'rewardXp' => (int) ($task['rewardXp'] ?? 0)];

            $nowMs = (int) round(microtime(true) * 1000);
            $xpMult = CombatElixirs::getXpBoostMultiplier(
                CombatElixirs::activeBuffEffects($blob, (string) $fresh->id, $nowMs),
            );
            $rewards['rewardXp'] = (int) floor((int) $rewards['rewardXp'] * $xpMult);

            $lvl = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, (int) $rewards['rewardXp']);
            $fresh->level = $lvl['newLevel'];
            $fresh->xp = $lvl['remainingXp'];
            $fresh->stat_points += $lvl['statPointsGained'];
            $fresh->highest_level = max((int) $fresh->highest_level, $lvl['newLevel']);

            array_splice($activeTasks, $idx, 1);
            $completed = [
                'id' => 'completed_'.$taskId,
                'taskId' => $task['id'],
                'monsterName' => $task['monsterName'] ?? '',
                'killCount' => (int) $task['killCount'],
                'rewardGold' => (int) $rewards['rewardGold'],
                'rewardXp' => (int) $rewards['rewardXp'],
                'completedAt' => now()->toIso8601String(),
            ];
            $blob['tasks']['activeTasks'] = array_values($activeTasks);
            $blob['tasks']['activeTask'] = $activeTasks[0] ?? null;
            $blob['tasks']['completedTasks'] = array_slice(
                [$completed, ...($blob['tasks']['completedTasks'] ?? [])], 0, 20
            );
            $save->state = $blob;

            $state->addGold($save, (int) $rewards['rewardGold']);

            $state->persist($save);
            $fresh->save();

            return [
                'rewards' => $rewards,
                'levelsGained' => $lvl['levelsGained'],
                'newLevel' => $lvl['newLevel'],
                'gold' => $state->gold($save),
                'character' => (new CharacterResource($fresh))->resolve(),
                'state' => $save->state,
            ];
        });

        return response()->json($payload);
    }
}
