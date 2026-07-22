<?php

declare(strict_types=1);

namespace App\Domain\Progression;

use App\Domain\Character\AttributeSystem;

final class LevelSystem
{
    public const DEATH_SKILL_XP_LOSS_PCT = 25;

    public const FLEE_SKILL_XP_LOSS_PCT = 2.5;

    public const ITEM_LOSS_GRACE_MAX_LEVEL = 50;

    private const XP_ANCHORS = [
        ['level' => 100, 'xp' => 300_000],
        ['level' => 200, 'xp' => 7_327_500],
        ['level' => 400, 'xp' => 31_875_000],
        ['level' => 600, 'xp' => 100_680_000],
        ['level' => 800, 'xp' => 696_750_000],
        ['level' => 1000, 'xp' => 897_150_000],
    ];

    public const BASE_HP_PER_LEVEL = [
        'Knight' => 8, 'Mage' => 3, 'Cleric' => 5, 'Archer' => 4,
        'Rogue' => 4, 'Necromancer' => 3, 'Bard' => 4,
    ];

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
            $overflow = $level - $last['level'];

            return (int) floor($last['xp'] * (1.10 ** $overflow));
        }

        return self::interpolateAnchors($level);
    }

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

    public const ATTRIBUTE_POINTS_PER_MILESTONE = 1;

    public static function processXpGain(int $currentLevel, int $currentXp, int $xpGained): array
    {
        $level = $currentLevel;
        $xp = $currentXp + $xpGained;
        $levelsGained = 0;

        $hardSafetyCap = 10_000;
        $startLevel = $level;
        while ($xp >= self::xpToNextLevel($level) && $level < $hardSafetyCap) {
            $xp -= self::xpToNextLevel($level);
            $level++;
            $levelsGained++;
        }

        $statPointsGained = AttributeSystem::getAttributePointsForLevel($level)
            - AttributeSystem::getAttributePointsForLevel($startLevel);

        return [
            'newLevel' => $level,
            'remainingXp' => $xp,
            'levelsGained' => $levelsGained,
            'statPointsGained' => $statPointsGained,
        ];
    }

    public static function getDeathLossLevels(int $level): float
    {
        return max(0.20, $level / 100);
    }

    public static function getFleeLossLevels(int $level): float
    {
        return self::getDeathLossLevels($level) * 0.10;
    }

    public static function losesItemsOnDeath(int $level): bool
    {
        return $level > self::ITEM_LOSS_GRACE_MAX_LEVEL;
    }

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

    public static function applyDeathPenalty(int $currentLevel, int $currentXp): array
    {
        return self::applyLevelLoss(
            $currentLevel,
            $currentXp,
            self::getDeathLossLevels($currentLevel),
            self::DEATH_SKILL_XP_LOSS_PCT,
        );
    }

    public static function applyFleePenalty(int $currentLevel, int $currentXp): array
    {
        return self::applyLevelLoss(
            $currentLevel,
            $currentXp,
            self::getFleeLossLevels($currentLevel),
            self::FLEE_SKILL_XP_LOSS_PCT,
        );
    }

    public static function applyDeathXpPenalty(int $currentXp, int $currentLevel): int
    {
        $penalty = (int) floor(self::xpToNextLevel($currentLevel) * 0.1);

        return (int) max(0, $currentXp - $penalty);
    }

    public static function xpProgress(int|float $currentXp, int $currentLevel): float
    {
        $needed = self::xpToNextLevel($currentLevel);

        return $needed > 0 ? min(1, $currentXp / $needed) : 0;
    }

    private static function jsRound(float $x): int
    {
        return (int) floor($x + 0.5);
    }
}
