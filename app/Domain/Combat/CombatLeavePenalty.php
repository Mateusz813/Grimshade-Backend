<?php

declare(strict_types=1);

namespace App\Domain\Combat;

use App\Domain\Progression\LevelSystem;

/**
 * Port rdzenia numerycznego src/systems/combatLeavePenalty.ts (frontend).
 *
 * Gdy gracz opuszcza walkę w trakcie (back / zmiana URL / refresh / zamknięcie
 * karty) traktujemy to jak REALNĄ śmierć: pełna kara poziomu/XP + skill XP +
 * item loss. ŚWIADOMIE pomija przedmioty ochronne (eliksir ochrony / amulet
 * straty) — potiony wybaczają prawdziwe śmierci w walce, nie „przycisk paniki"
 * przez pasek adresu (`protectionUsed` = false).
 *
 * PORTUJEMY tylko rdzeń liczbowy (linie 113-122 + 177-187 źródła): pełna kara
 * śmierci przez LevelSystem::applyDeathPenalty + zachowany najwyższy poziom.
 * POMINIĘTE (side effecty, reguła 5): deathsApi.logDeath, updateCharacter /
 * fullHealEffective, skillStore, applyDeathItemLoss, clearCombatSession, sync
 * do localStorage, keepalive PATCH do Supabase, death overlay.
 *
 * PARYTET: applyDeathPenalty jest już golden-verified (LevelSystem), a wektory
 * (tests/Golden/fixtures/deathProtection.json → computeLeavePenalty) generowane
 * z TS przez tę samą funkcję → wynik odtwarza się bajt-w-bajt.
 */
final class CombatLeavePenalty
{
    /**
     * Wylicza skutek opuszczenia walki dla postaci na pozycji (level, xp) z danym
     * `highestLevel` (null = brak zapisanego rekordu → traktowany jak bieżący).
     *
     * @return array{
     *     oldLevel:int,
     *     newLevel:int,
     *     newXp:int,
     *     levelsLost:int,
     *     xpPercent:int,
     *     skillXpLossPercent:int|float,
     *     preservedHighest:int,
     *     protectionUsed:bool
     * }
     */
    public static function computeLeavePenalty(int $currentLevel, int $currentXp, ?int $highestLevel): array
    {
        $penalty = LevelSystem::applyDeathPenalty($currentLevel, $currentXp);

        $currentHighest = $highestLevel ?? $currentLevel;
        $preservedHighest = max($currentHighest, $currentLevel);

        return [
            'oldLevel' => $currentLevel,
            'newLevel' => $penalty['newLevel'],
            'newXp' => $penalty['newXp'],
            'levelsLost' => $penalty['levelsLost'],
            'xpPercent' => $penalty['xpPercent'],
            'skillXpLossPercent' => $penalty['skillXpLossPercent'],
            'preservedHighest' => $preservedHighest,
            'protectionUsed' => false,
        ];
    }
}
