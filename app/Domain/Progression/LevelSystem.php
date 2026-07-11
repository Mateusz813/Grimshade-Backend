<?php

declare(strict_types=1);

namespace App\Domain\Progression;

/**
 * Port 1:1 src/systems/levelSystem.ts (frontend). Czyste formuły progresji:
 * krzywa XP, level-upy, kara za śmierć/ucieczkę. Bez RNG, bez zależności.
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/levelSystem.json (generowane
 * z TS) są tu odtwarzane bajt-w-bajt (LevelSystemTest). Zmiana którejkolwiek
 * formuły w TS regeneruje fixture i wymusza aktualizację tu.
 *
 * Kotwice XP jako LITERAŁY = wartości które wyprodukował JS (Math.floor), żeby
 * uniknąć rozjazdu libm pow na granicy (np. 100^1.5) między platformami.
 */
final class LevelSystem
{
    /** Śmierć zabiera 25% zbankowanego XP każdego trenowanego skilla (flat). */
    public const DEATH_SKILL_XP_LOSS_PCT = 25;

    /** Ucieczka = 2.5% (10% kary śmierci). */
    public const FLEE_SKILL_XP_LOSS_PCT = 2.5;

    /** Postacie ≤ tego poziomu nie tracą itemów na śmierci. */
    public const ITEM_LOSS_GRACE_MAX_LEVEL = 50;

    /** @var list<array{level:int, xp:int}> */
    private const XP_ANCHORS = [
        ['level' => 100, 'xp' => 300_000],      // floor(300 * 100^1.5)
        ['level' => 200, 'xp' => 7_327_500],
        ['level' => 400, 'xp' => 31_875_000],
        ['level' => 600, 'xp' => 100_680_000],
        ['level' => 800, 'xp' => 696_750_000],
        ['level' => 1000, 'xp' => 897_150_000],
    ];

    /** @var array<string, int> */
    public const BASE_HP_PER_LEVEL = [
        'Knight' => 8, 'Mage' => 3, 'Cleric' => 5, 'Archer' => 4,
        'Rogue' => 4, 'Necromancer' => 3, 'Bard' => 4,
    ];

    /** @var array<string, int> */
    public const BASE_MP_PER_LEVEL = [
        'Knight' => 2, 'Mage' => 8, 'Cleric' => 6, 'Archer' => 3,
        'Rogue' => 3, 'Necromancer' => 9, 'Bard' => 5,
    ];

    private static function legacyXp(int $level): int
    {
        return (int) max(300, floor(300 * ($level ** 1.5)));
    }

    private static function interpolateAnchors(int $level): int
    {
        $anchors = self::XP_ANCHORS;
        if ($level <= $anchors[0]['level']) {
            return $anchors[0]['xp'];
        }

        $last = $anchors[count($anchors) - 1];
        if ($level >= $last['level']) {
            return $last['xp'];
        }

        for ($i = 1; $i < count($anchors); $i++) {
            $a = $anchors[$i - 1];
            $b = $anchors[$i];
            if ($level <= $b['level']) {
                $t = ($level - $a['level']) / ($b['level'] - $a['level']);

                return (int) floor($a['xp'] + ($b['xp'] - $a['xp']) * $t);
            }
        }

        return $last['xp'];
    }

    /** XP potrzebne, by awansować z `level` na `level + 1`. */
    public static function xpToNextLevel(int $level): int
    {
        if ($level <= 0) {
            return 300;
        }
        if ($level < self::XP_ANCHORS[0]['level']) {
            return self::legacyXp($level);
        }

        $last = self::XP_ANCHORS[count(self::XP_ANCHORS) - 1];
        if ($level >= $last['level']) {
            // Powyżej 1000: każdy poziom o 10% droższy niż poprzedni.
            $overflow = $level - $last['level'];

            return (int) floor($last['xp'] * (1.10 ** $overflow));
        }

        return self::interpolateAnchors($level);
    }

    /** Suma XP od poziomu 1 do osiągnięcia `level`. */
    public static function totalXpForLevel(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }

        $total = 0;
        for ($l = 1; $l < $level; $l++) {
            $total += self::xpToNextLevel($l);
        }

        return $total;
    }

    /** Punkty statystyk za level-up (stałe per klasa — obecnie 2 dla wszystkich). */
    public static function statPointsForLevelUp(?string $characterClass = null): int
    {
        return 2;
    }

    /**
     * Przetwarza zdobyte XP — może wywołać wiele level-upów naraz.
     *
     * @return array{newLevel:int, remainingXp:int, levelsGained:int, statPointsGained:int}
     */
    public static function processXpGain(int $currentLevel, int $currentXp, int $xpGained): array
    {
        $level = $currentLevel;
        $xp = $currentXp + $xpGained;
        $levelsGained = 0;
        $statPointsGained = 0;

        $hardSafetyCap = 10_000;
        while ($xp >= self::xpToNextLevel($level) && $level < $hardSafetyCap) {
            $xp -= self::xpToNextLevel($level);
            $level++;
            $levelsGained++;
            $statPointsGained += self::statPointsForLevelUp();
        }

        return [
            'newLevel' => $level,
            'remainingXp' => $xp,
            'levelsGained' => $levelsGained,
            'statPointsGained' => $statPointsGained,
        ];
    }

    /** Kara śmierci w „poziomach" (ciągła): max(0.20, level/100). */
    public static function getDeathLossLevels(int $level): float
    {
        return max(0.20, $level / 100);
    }

    /** Kara ucieczki = 10% kary śmierci. Nigdy nie traci itemów. */
    public static function getFleeLossLevels(int $level): float
    {
        return self::getDeathLossLevels($level) * 0.10;
    }

    /** Czy śmierć na tym poziomie ryzykuje utratą itemów (od 51 w górę). */
    public static function losesItemsOnDeath(int $level): bool
    {
        return $level > self::ITEM_LOSS_GRACE_MAX_LEVEL;
    }

    /**
     * Aplikuje ułamkową utratę `lossLevels` na pozycji (level, xp) i przelicza
     * wynikowy poziom + XP. Oś ciągła = level + xp / xpToNextLevel(level).
     *
     * @return array{newLevel:int, newXp:int, xpPercent:int, levelsLost:int, skillXpLossPercent:int|float}
     */
    private static function applyLevelLoss(
        int $currentLevel,
        int $currentXp,
        float $lossLevels,
        int|float $skillXpLossPercent,
    ): array {
        $denom = max(1, self::xpToNextLevel($currentLevel));
        $frac = max(0, min(1, $currentXp / $denom));
        $exactPos = $currentLevel + $frac;
        $newExactPos = max(1, $exactPos - max(0, $lossLevels));
        $newLevel = (int) max(1, floor($newExactPos));
        $newFrac = max(0, $newExactPos - $newLevel);
        $newXp = (int) max(0, self::jsRound($newFrac * max(1, self::xpToNextLevel($newLevel))));

        return [
            'newLevel' => $newLevel,
            'newXp' => $newXp,
            'xpPercent' => self::jsRound($newFrac * 100),
            'levelsLost' => $currentLevel - $newLevel,
            'skillXpLossPercent' => $skillXpLossPercent,
        ];
    }

    /**
     * @return array{newLevel:int, newXp:int, xpPercent:int, levelsLost:int, skillXpLossPercent:int|float}
     */
    public static function applyDeathPenalty(int $currentLevel, int $currentXp): array
    {
        return self::applyLevelLoss(
            $currentLevel,
            $currentXp,
            self::getDeathLossLevels($currentLevel),
            self::DEATH_SKILL_XP_LOSS_PCT,
        );
    }

    /**
     * @return array{newLevel:int, newXp:int, xpPercent:int, levelsLost:int, skillXpLossPercent:int|float}
     */
    public static function applyFleePenalty(int $currentLevel, int $currentXp): array
    {
        return self::applyLevelLoss(
            $currentLevel,
            $currentXp,
            self::getFleeLossLevels($currentLevel),
            self::FLEE_SKILL_XP_LOSS_PCT,
        );
    }

    /** Legacy: kara XP = 10% xpToNextLevel, odejmowana od bieżącego XP. */
    public static function applyDeathXpPenalty(int $currentXp, int $currentLevel): int
    {
        $penalty = (int) floor(self::xpToNextLevel($currentLevel) * 0.1);

        return (int) max(0, $currentXp - $penalty);
    }

    /** Postęp XP w obrębie bieżącego poziomu (0–1). */
    public static function xpProgress(int|float $currentXp, int $currentLevel): float
    {
        $needed = self::xpToNextLevel($currentLevel);

        return $needed > 0 ? min(1, $currentXp / $needed) : 0;
    }

    /** Math.round z JS (half-up dla wartości nieujemnych, które tu występują). */
    private static function jsRound(float $x): int
    {
        return (int) floor($x + 0.5);
    }
}
