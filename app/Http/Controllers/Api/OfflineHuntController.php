<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

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

/**
 * Autorytatywne rozliczenie polowania offline. Serwer:
 *  - tożsamość bierze z tokenu, postać/stan z BAZY (nie z body),
 *  - liczy CZAS WŁASNYM zegarem: elapsed = now() - startedAt (kotwica z bloba
 *    state.offlineHunt.startedAt, w razie braku z game_saves.offline_entered_at),
 *    cap 12h (OfflineHuntSystem::OFFLINE_HUNT_MAX_SECONDS),
 *  - wylicza kills = floor(cappedSeconds * speedMult / BASE) (mastery skaluje
 *    tempo), a nagrody DETERMINISTYCZNIE przez OfflineHuntSystem::aggregateClaimRewards
 *    (odpowiednik previewOfflineHunt — bez RNG, bez ufania buffom klienta:
 *    xpBuffMult/premiumXpMult = 1),
 *  - zapisuje autorytatywnie: XP/level/stat_points → characters (processXpGain),
 *    GOLD → blob game_saves (inventory.gold to PRAWDZIWA waluta gry),
 *  - ANTY-DUPLIKACJA: zatrzymuje polowanie (mirror stopHunt) + zeruje
 *    offline_entered_at, plus idempotencja Cache po (character->id + requestId).
 *
 * Rarity-variance i dropy (itemy/potiony/kamienie) żywego claimOfflineHunt są
 * świadomie POMINIĘTE — serwerowy grant to deterministyczny preview-equivalent
 * (autorytet bez rozjazdu RNG). Postęp task/quest/mastery-kills poza zakresem
 * tego endpointu.
 */
final class OfflineHuntController extends Controller
{
    public function settle(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:200'],
        ]);
        $requestId = (string) $data['requestId'];

        $cacheKey = "offlineHunt.settle.{$character->id}.{$requestId}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        /** @var array{0: array<string, mixed>, 1: bool} $result */
        $result = DB::transaction(function () use ($character, $state): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $slice = $blob['offlineHunt'] ?? null;

            $isActive = (bool) ($slice['isActive'] ?? false);
            $monster = $slice['targetMonster'] ?? null;
            // Kotwica czasu: startedAt z bloba, w razie braku znacznik kolumny.
            $anchor = ($slice['startedAt'] ?? null) ?? $save->offline_entered_at;

            // Brak aktywnego polowania / danych → nic do rozliczenia (idempotentne,
            // bez mutacji, bez cache — no-op jest z natury bezpieczny do powtórki).
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

            // Czas z SERWERA (nie z body): elapsed = now - startedAt, cap 12h.
            $elapsedSeconds = max(0, now()->getTimestamp() - Carbon::parse($anchor)->getTimestamp());
            $cappedSeconds = min($elapsedSeconds, OfflineHuntSystem::OFFLINE_HUNT_MAX_SECONDS);
            $speedMultiplier = OfflineHuntSystem::getOfflineHuntSpeedMultiplier($masteryLevel);
            $kills = (int) floor($cappedSeconds * $speedMultiplier / OfflineHuntSystem::OFFLINE_HUNT_BASE_SECONDS_PER_KILL);

            // ANTY-DUPLIKACJA: zawsze zatrzymaj polowanie + wyczyść znacznik offline,
            // nawet gdy kills=0 (mirror stopHunt() z frontu — nic do zebrania, hunt kończy się).
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

            // Nagrody deterministyczne (preview-equivalent): wszystkie zabójstwa jako
            // 'normal', bez RNG i bez ufania buffom klienta (mnożniki = 1). Mastery
            // skaluje XP/gold przez aggregateClaimRewards (masteryLevel).
            $rewards = OfflineHuntSystem::aggregateClaimRewards([
                'monsterXp' => $monsterXp,
                'goldMin' => $goldMin, 'goldMax' => $goldMax,
                'masteryLevel' => $masteryLevel,
                'xpBuffMult' => 1.0, 'premiumXpMult' => 1.0,
                'killsByRarity' => ['normal' => $kills, 'strong' => 0, 'epic' => 0, 'legendary' => 0, 'boss' => 0],
            ]);
            $xpGained = (int) $rewards['xpGained'];
            $goldGained = (int) $rewards['goldGained'];

            // XP → postać (może wywołać wiele level-upów naraz).
            $lvl = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, $xpGained);
            $fresh->level = $lvl['newLevel'];
            $fresh->xp = $lvl['remainingXp'];
            $fresh->stat_points += $lvl['statPointsGained'];
            $fresh->highest_level = max((int) $fresh->highest_level, $lvl['newLevel']);

            // Blob (z wyczyszczonym markerem) PRZED addGold (bug kolejności reguła 4).
            $save->state = $blob;
            $save->offline_entered_at = null;

            // Gold → blob (PO ostatnim $save->state = $blob).
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

        // Cache TYLKO realne rozliczenia (grant) — no-opy są idempotentne z natury.
        if ($granted) {
            Cache::put($cacheKey, $payload, now()->addHour());
        }

        return response()->json($payload);
    }
}
