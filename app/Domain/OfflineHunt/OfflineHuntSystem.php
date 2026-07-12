<?php

declare(strict_types=1);

namespace App\Domain\OfflineHunt;

use App\Domain\Loot\LootSystem;
use App\Domain\Skills\SkillSystem;
use App\Domain\Support\Rng\RngInterface;

final class OfflineHuntSystem
{
    public const OFFLINE_HUNT_BASE_SECONDS_PER_KILL = 10;

    public const OFFLINE_HUNT_MAX_SECONDS = 12 * 60 * 60;

    public const RARITIES = ['normal', 'strong', 'epic', 'legendary', 'boss'];

    public const RARITY_XP_MULT = [
        'normal' => 1, 'strong' => 1.5, 'epic' => 2.5, 'legendary' => 4, 'boss' => 8,
    ];

    public const RARITY_GOLD_MULT = [
        'normal' => 1, 'strong' => 1.5, 'epic' => 2.5, 'legendary' => 4, 'boss' => 8,
    ];

    public const MONSTER_RARITY_TASK_KILLS = [
        'normal' => 1, 'strong' => 3, 'epic' => 10, 'legendary' => 50, 'boss' => 200,
    ];

    private const MASTERY_MAX_LEVEL = 25;

    private const MASTERY_XP_BONUS_PER_LEVEL = 0.02;

    private const MASTERY_GOLD_BONUS_PER_LEVEL = 0.02;

    public static function getOfflineHuntSpeedMultiplier(int $masteryLevel): int
    {
        if ($masteryLevel >= 20) {
            return 4;
        }
        if ($masteryLevel >= 12) {
            return 3;
        }
        if ($masteryLevel >= 5) {
            return 2;
        }

        return 1;
    }

    public static function preview(array $input): array
    {
        $nowMs = (int) $input['nowMs'];
        $startedAtMs = (int) $input['startedAtMs'];
        $masteryLevel = (int) $input['masteryLevel'];
        $monsterXp = (int) $input['monsterXp'];
        $goldMin = (int) $input['goldMin'];
        $goldMax = (int) $input['goldMax'];
        $skillLevel = (int) $input['skillLevel'];
        $trainedSkillId = (string) $input['trainedSkillId'];
        $xpBuffMult = (float) $input['xpBuffMult'];
        $premiumXpMult = (float) $input['premiumXpMult'];
        $skillXpBoostMult = (float) $input['skillXpBoostMult'];
        $offlineTrainingBoostMult = (float) $input['offlineTrainingBoostMult'];

        $elapsedSeconds = max(0, (int) floor(($nowMs - $startedAtMs) / 1000));
        $cappedSeconds = min($elapsedSeconds, self::OFFLINE_HUNT_MAX_SECONDS);

        $speedMultiplier = self::getOfflineHuntSpeedMultiplier($masteryLevel);
        $killsPerSecond = $speedMultiplier / self::OFFLINE_HUNT_BASE_SECONDS_PER_KILL;
        $kills = (int) floor($cappedSeconds * $killsPerSecond);

        $masteryXpMult = self::masteryXpMultiplier($masteryLevel);
        $masteryGoldMult = self::masteryGoldMultiplier($masteryLevel);

        $totalXpMult = $xpBuffMult * $premiumXpMult * $masteryXpMult;
        $xpPerKill = (int) floor($monsterXp * $totalXpMult);
        $xpGained = $kills * $xpPerKill;

        $goldPerKill = (int) floor((($goldMin + $goldMax) / 2) * $masteryGoldMult);
        $goldGained = $kills * $goldPerKill;

        $skillXpBaseRaw = SkillSystem::calculateOfflineSkillXp($cappedSeconds, $skillLevel, $trainedSkillId);
        $skillXpMult = $skillXpBoostMult * $offlineTrainingBoostMult * $premiumXpMult;
        $skillXpGained = (int) floor($skillXpBaseRaw * $skillXpMult);

        return [
            'elapsedSeconds' => $elapsedSeconds,
            'cappedSeconds' => $cappedSeconds,
            'kills' => $kills,
            'xpGained' => $xpGained,
            'goldGained' => $goldGained,
            'skillXpGained' => $skillXpGained,
            'speedMultiplier' => $speedMultiplier,
        ];
    }

    public static function aggregateClaimRewards(array $input): array
    {
        $monsterXp = (int) $input['monsterXp'];
        $goldMin = (int) $input['goldMin'];
        $goldMax = (int) $input['goldMax'];
        $masteryLevel = (int) $input['masteryLevel'];
        $xpBuffMult = (float) $input['xpBuffMult'];
        $premiumXpMult = (float) $input['premiumXpMult'];
        $kbr = $input['killsByRarity'];

        $xpMult = $xpBuffMult * $premiumXpMult;
        $masteryXpMult = self::masteryXpMultiplier($masteryLevel);
        $masteryGoldMult = self::masteryGoldMultiplier($masteryLevel);
        $goldBase = (int) floor(($goldMin + $goldMax) / 2);

        $xpGained = 0;
        $goldGained = 0;
        foreach (self::RARITIES as $r) {
            $n = (int) ($kbr[$r] ?? 0);
            $xpPerKill = (int) floor($monsterXp * self::RARITY_XP_MULT[$r] * $xpMult * $masteryXpMult);
            $goldPerKill = (int) floor($goldBase * self::RARITY_GOLD_MULT[$r] * $masteryGoldMult);
            $xpGained += $n * $xpPerKill;
            $goldGained += $n * $goldPerKill;
        }

        return ['xpGained' => $xpGained, 'goldGained' => $goldGained];
    }

    public static function weightedTaskKills(array $killsByRarity): int
    {
        $total = 0;
        foreach (self::MONSTER_RARITY_TASK_KILLS as $rarity => $weight) {
            $total += (int) ($killsByRarity[$rarity] ?? 0) * $weight;
        }

        return $total;
    }

    public static function rollKillsByRarity(RngInterface $rng, int $kills, ?array $masteryBonuses = null): array
    {
        $kbr = ['normal' => 0, 'strong' => 0, 'epic' => 0, 'legendary' => 0, 'boss' => 0];
        for ($i = 0; $i < $kills; $i++) {
            $rarity = LootSystem::rollMonsterRarity($rng, false, $masteryBonuses);
            $kbr[$rarity]++;
        }

        return $kbr;
    }

    private static function masteryXpMultiplier(int $masteryLevel): float
    {
        $lvl = max(0, min(self::MASTERY_MAX_LEVEL, $masteryLevel));

        return 1 + $lvl * self::MASTERY_XP_BONUS_PER_LEVEL;
    }

    private static function masteryGoldMultiplier(int $masteryLevel): float
    {
        $lvl = max(0, min(self::MASTERY_MAX_LEVEL, $masteryLevel));

        return 1 + $lvl * self::MASTERY_GOLD_BONUS_PER_LEVEL;
    }
}
