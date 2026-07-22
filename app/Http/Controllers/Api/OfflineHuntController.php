<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Combat\CombatElixirs;
use App\Domain\Content\ContentRepository;
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
    /**
     * Rozpoczyna polowanie offline PO STRONIE SERWERA.
     *
     * Wcześniej start istniał wyłącznie w blobie klienta i docierał na serwer dopiero
     * debounce'owanym commitem — kto ustawił polowanie i od razu zamknął apkę, tracił je
     * w całości (`settle` nie widział `offlineHunt.isActive` i zwracał `settled: false`).
     * Teraz zapis jest transakcyjny i natychmiastowy, więc zamknięcie apki nic nie zmienia.
     */
    public function start(Request $request, ContentRepository $content, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:200'],
            'monsterId' => ['required', 'string', 'max:120'],
            'skillId' => ['required', 'string', 'max:120'],
        ]);

        $cacheKey = "offlineHunt.start.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $monster = null;
        foreach ($content->get('monsters') as $row) {
            if ((string) ($row['id'] ?? '') === $data['monsterId']) {
                $monster = $row;
                break;
            }
        }

        if ($monster === null) {
            return response()->json(['message' => "Nieznany potwor: {$data['monsterId']}"], 422);
        }

        if ((int) ($monster['level'] ?? 0) > (int) $character->level) {
            return response()->json([
                'message' => 'Potwor jest powyzej poziomu postaci',
            ], 422);
        }

        $payload = DB::transaction(function () use ($character, $state, $monster, $data): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = is_array($save->state) ? $save->state : [];
            $blob['offlineHunt'] = [
                'isActive' => true,
                'startedAt' => now()->toIso8601String(),
                'targetMonster' => $monster,
                'trainedSkillId' => $data['skillId'],
                '_entryOwner' => (string) $fresh->id,
            ];

            $save->state = $blob;
            $state->persist($save);

            return [
                'started' => true,
                'offlineHunt' => $blob['offlineHunt'],
                'state' => $blob,
                'updated_at' => optional($save->updated_at)->toIso8601String(),
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

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
