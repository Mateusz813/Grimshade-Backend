<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Combat\CombatElixirs;
use App\Domain\Content\ContentRepository;
use App\Domain\Progression\DailyQuestSystem;
use App\Domain\Progression\LevelSystem;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class DailyQuestController extends Controller
{
    public function refresh(Request $request, ContentRepository $content, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'date' => ['sometimes', 'string', 'date_format:Y-m-d'],
        ]);

        $now = now();
        $todayKey = $data['date'] ?? DailyQuestSystem::todayKey(
            (int) $now->year,
            (int) $now->month,
            (int) $now->day,
        );

        $payload = DB::transaction(function () use ($character, $content, $state, $todayKey): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $slice = $blob['dailyQuests'] ?? [
                'lastRefreshDate' => null,
                'activeQuests' => [],
                'todayQuestDefs' => [],
            ];

            $allQuests = $content->get('dailyQuests');
            $level = (int) $fresh->level;

            $refreshed = false;
            if (DailyQuestSystem::needsRefresh($slice['lastRefreshDate'] ?? null, $todayKey)) {
                $defs = DailyQuestSystem::selectDailyQuests($allQuests, $level, $todayKey);

                $slice = [
                    'lastRefreshDate' => $todayKey,
                    'todayQuestDefs' => array_values($defs),
                    'activeQuests' => array_map(static fn (array $q): array => [
                        'questId' => $q['id'],
                        'progress' => 0,
                        'completed' => false,
                        'claimed' => false,
                    ], $defs),
                ];

                $blob['dailyQuests'] = $slice;
                $save->state = $blob;
                $state->persist($save);
                $refreshed = true;
            } elseif (DailyQuestSystem::isSliceDegraded(
                $allQuests,
                $level,
                $todayKey,
                $slice['todayQuestDefs'] ?? [],
                $slice['activeQuests'] ?? [],
            )) {
                $repaired = DailyQuestSystem::reconcileDailyQuests(
                    $allQuests,
                    $level,
                    $todayKey,
                    $slice['activeQuests'] ?? [],
                );

                $slice = [
                    'lastRefreshDate' => $slice['lastRefreshDate'] ?? $todayKey,
                    'todayQuestDefs' => $repaired['todayQuestDefs'],
                    'activeQuests' => $repaired['activeQuests'],
                ];

                $blob['dailyQuests'] = $slice;
                $save->state = $blob;
                $state->persist($save);
                $refreshed = true;
            }

            return [
                'refreshed' => $refreshed,
                'lastRefreshDate' => $slice['lastRefreshDate'] ?? null,
                'activeQuests' => $slice['activeQuests'] ?? [],
                'todayQuestDefs' => $slice['todayQuestDefs'] ?? [],
            ];
        });

        return response()->json($payload);
    }

    public function claim(Request $request, ContentRepository $content, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');
        $questId = (string) $request->route('questId');

        $payload = DB::transaction(function () use ($character, $questId, $content, $state): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $slice = $blob['dailyQuests'] ?? null;
            $activeQuests = $slice['activeQuests'] ?? [];

            $idx = null;
            foreach ($activeQuests as $i => $aq) {
                if (($aq['questId'] ?? null) === $questId) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                abort(Response::HTTP_NOT_FOUND, 'Quest dzienny nie istnieje (odśwież questy).');
            }

            $aq = $activeQuests[$idx];
            if (! ($aq['completed'] ?? false)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Quest dzienny jeszcze nieukończony.');
            }
            if ($aq['claimed'] ?? false) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nagroda za ten quest już odebrana.');
            }

            $def = collect($slice['todayQuestDefs'] ?? [])->firstWhere('id', $questId)
                ?? collect($content->get('dailyQuests'))->firstWhere('id', $questId);
            if ($def === null || ! isset($def['rewards'])) {
                abort(Response::HTTP_NOT_FOUND, 'Brak definicji questa dziennego.');
            }

            $rewards = DailyQuestSystem::scaleRewards($def['rewards'], (int) $fresh->level);

            $nowMs = (int) round(microtime(true) * 1000);
            $xpMult = CombatElixirs::getXpBoostMultiplier(
                CombatElixirs::activeBuffEffects($blob, (string) $fresh->id, $nowMs),
            );
            $rewards['xp'] = (int) floor((int) $rewards['xp'] * $xpMult);

            $lvl = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, (int) $rewards['xp']);
            $fresh->level = $lvl['newLevel'];
            $fresh->xp = $lvl['remainingXp'];
            $fresh->stat_points += $lvl['statPointsGained'];
            $fresh->highest_level = max((int) $fresh->highest_level, $lvl['newLevel']);

            $fresh->quests_daily_done = (int) $fresh->quests_daily_done + 1;

            $activeQuests[$idx]['claimed'] = true;
            $blob['dailyQuests']['activeQuests'] = array_values($activeQuests);
            $save->state = $blob;

            $state->addGold($save, (int) $rewards['gold']);
            if (isset($rewards['elixir']) && $rewards['elixir'] !== '') {
                $state->addConsumable($save, (string) $rewards['elixir'], 1);
            }

            $state->persist($save);
            $fresh->save();

            return [
                'rewards' => $rewards,
                'levelsGained' => $lvl['levelsGained'],
                'newLevel' => $lvl['newLevel'],
                'gold' => $state->gold($save),
                'questsDailyDone' => (int) $fresh->quests_daily_done,
                'character' => (new CharacterResource($fresh))->resolve(),
                'state' => $save->state,
            ];
        });

        return response()->json($payload);
    }
}
