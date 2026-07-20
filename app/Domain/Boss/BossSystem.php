<?php

declare(strict_types=1);

namespace App\Domain\Boss;

use App\Domain\Combat\CombatMath;
use App\Domain\Progression\LevelSystem;
use App\Domain\Support\Rng\RngInterface;

final class BossSystem
{
    public const BOSS_HP_MULTIPLIER = 3.5;

    public const BOSS_ATK_MULTIPLIER = 1.75;

    public const BOSS_DEF_MULTIPLIER = 1.3;

    public const BOSS_REWARD_MULTIPLIER = 1;

    private const DEFAULT_COOLDOWN_SECONDS = 28800;

    public static function getBossDrops(array $boss): array
    {
        return $boss['uniqueDrops'] ?? $boss['dropTable'] ?? [];
    }

    public static function getBossCooldown(array $boss): int
    {
        if (array_key_exists('cooldown', $boss) && $boss['cooldown'] !== null) {
            return (int) $boss['cooldown'];
        }

        $dailyAttempts = $boss['dailyAttempts'] ?? null;
        if ($dailyAttempts !== null && $dailyAttempts != 0) {
            return (int) floor(86400 / $dailyAttempts);
        }

        return self::DEFAULT_COOLDOWN_SECONDS;
    }

    public static function getScaledBossStats(array $boss): array
    {
        $atk = $boss['attack'];
        $baseMin = (int) floor($atk * 0.8);
        $baseMax = (int) floor($atk * 1.2);

        return [
            'hp' => (int) floor($boss['hp'] * self::BOSS_HP_MULTIPLIER),
            'attack' => (int) floor($atk * self::BOSS_ATK_MULTIPLIER),
            'attack_min' => (int) max(1, floor($baseMin * self::BOSS_ATK_MULTIPLIER)),
            'attack_max' => (int) max(1, floor($baseMax * self::BOSS_ATK_MULTIPLIER)),
            'defense' => (int) floor($boss['defense'] * self::BOSS_DEF_MULTIPLIER),
        ];
    }

    public static function getBossPhaseMultiplier(int|float $bossHpFraction): float
    {
        return $bossHpFraction < 0.3 ? 1.5 : 1.0;
    }

    public static function isBossEnraged(int|float $currentHp, int|float $maxHp): bool
    {
        return $maxHp > 0 && $currentHp / $maxHp < 0.3;
    }

    private static function bossXpPercent(int|float $level): float
    {
        return 0.005 + 0.19 / (1 + max(1, $level) / 80);
    }

    private static function bossGoldMid(int|float $level): int
    {
        return (int) floor(38 * (max(1, $level) ** 1.8));
    }

    public static function computeBossRewards(int $level): array
    {
        $mid = self::bossGoldMid($level);

        return [
            'goldMin' => (int) max(1, floor($mid * 0.6)),
            'goldMax' => (int) max(1, floor($mid * 1.6)),
            'xp' => (int) max(1, floor(LevelSystem::xpToNextLevel($level) * self::bossXpPercent($level))),
        ];
    }

    public static function getBossGoldRange(array $boss): array
    {
        $r = self::computeBossRewards((int) $boss['level']);

        return [$r['goldMin'], $r['goldMax']];
    }

    public static function getBossXp(array $boss): int
    {
        return self::computeBossRewards((int) $boss['level'])['xp'];
    }

    public static function getBossRecommendedLevel(array $boss): int
    {
        return (int) $boss['level'] + 5;
    }

    public static function canChallengeBoss(
        array $boss,
        int $characterLevel,
        ?int $lastDefeatedAtMs,
        int $nowMs,
    ): bool {
        if ($characterLevel < $boss['level']) {
            return false;
        }
        if ($lastDefeatedAtMs === null) {
            return true;
        }
        $elapsed = $nowMs - $lastDefeatedAtMs;

        return $elapsed >= self::getBossCooldown($boss) * 1000;
    }

    public static function getBossRemainingMs(array $boss, ?int $lastDefeatedAtMs, int $nowMs): int
    {
        if ($lastDefeatedAtMs === null) {
            return 0;
        }
        $elapsed = $nowMs - $lastDefeatedAtMs;

        return (int) max(0, self::getBossCooldown($boss) * 1000 - $elapsed);
    }

    public static function rollBossGold(RngInterface $rng, array $boss): int
    {
        $r = self::computeBossRewards((int) $boss['level']);

        return $r['goldMin'] + (int) floor($rng->nextFloat() * ($r['goldMax'] - $r['goldMin'] + 1));
    }

    public static function rollBossLoot(RngInterface $rng, array $boss): array
    {
        $drops = [];
        foreach (self::getBossDrops($boss) as $drop) {
            if ($rng->nextFloat() < $drop['chance']) {
                $drops[] = $drop;
            }
        }

        return $drops;
    }

    public static function resolveBoss(RngInterface $rng, array $boss, array $character): array
    {
        $scaled = self::getScaledBossStats($boss);
        $playerHp = $character['max_hp'];
        $bossHp = $scaled['hp'];
        $bossMaxHp = $scaled['hp'];
        $playerDmg = CombatMath::mitigateDamage($character['attack'], $scaled['defense'], $character['level'], true);
        $baseBossDmg = CombatMath::mitigateDamage($scaled['attack'], $character['defense'], (int) $boss['level']);
        $turns = 0;

        while ($bossHp > 0 && $playerHp > 0 && $turns < 100000) {
            $bossHp -= $playerDmg;
            if ($bossHp <= 0) {
                break;
            }

            $mult = self::getBossPhaseMultiplier($bossHp / $bossMaxHp);
            $bossDmg = max(1, (int) floor($baseBossDmg * $mult));
            $playerHp -= $bossDmg;
            $turns++;
        }

        $won = $bossHp <= 0 && $playerHp > 0;
        $drops = $won ? self::rollBossLoot($rng, $boss) : [];
        $gold = $won ? self::rollBossGold($rng, $boss) : 0;

        return [
            'won' => $won,
            'playerHpLeft' => (int) max(0, $playerHp),
            'turns' => $turns,
            'drops' => $drops,
            'gold' => $gold,
            'xp' => $won ? self::getBossXp($boss) : 0,
        ];
    }
}
