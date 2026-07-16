<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Combat\CombatElixirs;
use App\Domain\OfflineHunt\OfflineHuntSystem;
use App\Domain\Progression\LevelSystem;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class OfflineHuntController extends Controller
{
    public function settle(Request $request, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:200'],
        ]);
        $requestId = (string) $data['requestId'];

        $cacheKey = "offlineHunt.settle.{$character->id}.{$requestId}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $result = DB::transaction(function () use ($character, $state): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $slice = $blob['offlineHunt'] ?? null;

            $isActive = (bool) ($slice['isActive'] ?? false);
            $monster = $slice['targetMonster'] ?? null;
            $anchor = ($slice['startedAt'] ?? null) ?? $save->offline_entered_at;

            if (! $isActive || $monster === null || $anchor === null) {
                return [[
                    'settled' => false, 'kills' => 0, 'xpGained' => 0, 'goldGained' => 0,
                    'gold' => $state->gold($save),
                ], false];
            }

            $monsterId = (string) ($monster['id'] ?? '');
            $monsterXp = (int) ($monster['xp'] ?? 0);
            $goldRange = $monster['gold'] ?? [0, 0];
            $goldMin = (int) ($goldRange[0] ?? 0);
            $goldMax = (int) ($goldRange[1] ?? 0);
            $masteryLevel = (int) ($blob['mastery']['masteries'][$monsterId]['level'] ?? 0);

            $elapsedSeconds = max(0, now()->getTimestamp() - Carbon::parse($anchor)->getTimestamp());
            $cappedSeconds = min($elapsedSeconds, OfflineHuntSystem::OFFLINE_HUNT_MAX_SECONDS);
            $speedMultiplier = OfflineHuntSystem::getOfflineHuntSpeedMultiplier($masteryLevel);
            $kills = (int) floor($cappedSeconds * $speedMultiplier / OfflineHuntSystem::OFFLINE_HUNT_BASE_SECONDS_PER_KILL);

            $blob['offlineHunt'] = [
                'isActive' => false, 'startedAt' => null,
                'targetMonster' => null, 'trainedSkillId' => null,
            ];

            if ($kills <= 0) {
                $save->state = $blob;
                $save->offline_entered_at = null;
                $state->persist($save);

                return [[
                    'settled' => false,
                    'elapsedSeconds' => $elapsedSeconds, 'cappedSeconds' => $cappedSeconds,
                    'kills' => 0, 'xpGained' => 0, 'goldGained' => 0,
                    'gold' => $state->gold($save),
                ], false];
            }

            $nowMs = (int) round(microtime(true) * 1000);
            $xpBuffMult = CombatElixirs::getXpBoostMultiplier(
                CombatElixirs::activeBuffEffects($blob, (string) $fresh->id, $nowMs),
            );

            $rewards = OfflineHuntSystem::aggregateClaimRewards([
                'monsterXp' => $monsterXp,
                'goldMin' => $goldMin, 'goldMax' => $goldMax,
                'masteryLevel' => $masteryLevel,
                'xpBuffMult' => $xpBuffMult, 'premiumXpMult' => 1.0,
                'killsByRarity' => ['normal' => $kills, 'strong' => 0, 'epic' => 0, 'legendary' => 0, 'boss' => 0],
            ]);
            $xpGained = (int) $rewards['xpGained'];
            $goldGained = (int) $rewards['goldGained'];

            $lvl = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, $xpGained);
            $fresh->level = $lvl['newLevel'];
            $fresh->xp = $lvl['remainingXp'];
            $fresh->stat_points += $lvl['statPointsGained'];
            $fresh->highest_level = max((int) $fresh->highest_level, $lvl['newLevel']);

            $save->state = $blob;
            $save->offline_entered_at = null;

            $state->addGold($save, $goldGained);

            $state->persist($save);
            $fresh->save();

            return [[
                'settled' => true,
                'elapsedSeconds' => $elapsedSeconds,
                'cappedSeconds' => $cappedSeconds,
                'kills' => $kills,
                'xpGained' => $xpGained,
                'goldGained' => $goldGained,
                'levelsGained' => $lvl['levelsGained'],
                'newLevel' => $lvl['newLevel'],
                'gold' => $state->gold($save),
            ], true];
        });

        [$payload, $granted] = $result;

        if ($granted) {
            Cache::put($cacheKey, $payload, now()->addHour());
        }

        return response()->json($payload);
    }
}
