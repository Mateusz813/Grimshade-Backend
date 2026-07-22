<?php

declare(strict_types=1);

namespace App\Domain\Combat;

final class CombatMath
{
    public const DEF_K = 1.0;

    public const DEF_CAP = 0.75;

    public const DEF_BASE = 25;

    public const CRIT_MULT_MIN = 1.5;

    public const CRIT_MULT_MAX = 2.5;

    public const DMG_COMPRESS_K = 2.3;

    public const DMG_COMPRESS_P = 0.80;

    public static function compressPlayerDamage(int|float $mitigatedDamage): float
    {
        return self::DMG_COMPRESS_K * ($mitigatedDamage <= 0 ? 0.0 : ($mitigatedDamage ** self::DMG_COMPRESS_P));
    }

    public const MONSTER_STAT_MULTIPLIERS = [
        'normal' => ['hp' => 1.0, 'atk' => 1.0, 'def' => 1.0, 'xp' => 1.0, 'gold' => 1.0],
        'strong' => ['hp' => 1.5, 'atk' => 1.4, 'def' => 1.3, 'xp' => 1.8, 'gold' => 2.0],
        'epic' => ['hp' => 2.5, 'atk' => 2.2, 'def' => 1.5, 'xp' => 3.0, 'gold' => 4.0],
        'legendary' => ['hp' => 4.0, 'atk' => 3.2, 'def' => 1.8, 'xp' => 5.0, 'gold' => 8.0],
        'boss' => ['hp' => 8.0, 'atk' => 5.0, 'def' => 2.0, 'xp' => 10.0, 'gold' => 15.0],
    ];

    private static function safeN(int|float|null $v, float $fallback = 0.0): float
    {
        $n = $v ?? $fallback;
        $n = (float) $n;

        return is_finite($n) ? $n : $fallback;
    }

    public static function defMitigation(int|float $enemyDef, int|float $attackerLevel): float
    {
        $def = max(0, self::safeN($enemyDef));
        $lvl = max(1, self::safeN($attackerLevel, 1));
        if ($def <= 0) {
            return 0.0;
        }

        return min(self::DEF_CAP, $def / ($def + self::DEF_K * $lvl + self::DEF_BASE));
    }

    public static function mitigateDamage(int|float $rawDamage, int|float $enemyDef, int|float $attackerLevel, bool $playerSource = false): int
    {
        $m = self::safeN($rawDamage) * (1 - self::defMitigation($enemyDef, $attackerLevel));

        return (int) max(1, floor($playerSource ? self::compressPlayerDamage($m) : $m));
    }

    public static function rollCritMultiplier(?float $roll = null): float
    {
        $r = $roll ?? (mt_rand() / mt_getrandmax());

        return self::CRIT_MULT_MIN + min(1.0, max(0.0, $r)) * (self::CRIT_MULT_MAX - self::CRIT_MULT_MIN);
    }

    public static function calculateDamage(array $params): array
    {
        $baseAtk = self::safeN($params['baseAtk'] ?? null);
        $weaponAtk = self::safeN($params['weaponAtk'] ?? null);
        $skillBonus = self::safeN($params['skillBonus'] ?? null);
        $classMod = self::safeN($params['classModifier'] ?? null, 1);
        $enemyDef = self::safeN($params['enemyDefense'] ?? null);
        $critChance = self::safeN($params['critChance'] ?? null, 0.05);
        $maxCrit = self::safeN($params['maxCritChance'] ?? null, 1.0);

        $effectiveCritChance = min($critChance, $maxCrit);

        $baseDamage = ($baseAtk + $weaponAtk + $skillBonus) * $classMod;
        $mitigated = $baseDamage * (1 - self::defMitigation($enemyDef, $params['attackerLevel'] ?? 1));
        $finalDamage = ($params['playerSource'] ?? false) ? self::compressPlayerDamage($mitigated) : max(1, $mitigated);

        $isCrit = $params['isCrit'] ?? (mt_rand() / mt_getrandmax() < $effectiveCritChance);
        if ($isCrit) {
            $finalDamage *= self::rollCritMultiplier(isset($params['critRoll']) ? (float) $params['critRoll'] : null);
        }

        $dmgMult = self::safeN($params['damageMultiplier'] ?? null, 1);
        if ($dmgMult !== 1.0) {
            $finalDamage *= $dmgMult;
        }

        return [
            'damage' => (int) max(1, floor($mitigated)),
            'isCrit' => (bool) $isCrit,
            'finalDamage' => (int) max(1, floor($finalDamage)),
        ];
    }

    public static function calculateDualWieldDamage(array $params): array
    {
        $hit1Params = $params;
        $hit1Params['weaponAtk'] = floor(self::safeN($params['weaponAtk'] ?? null) * 0.6);

        $hit2Params = $params;
        $hit2Params['weaponAtk'] = floor(self::safeN($params['offHandAtk'] ?? null) * 0.6);

        $hit1 = self::calculateDamage($hit1Params);
        $hit2 = self::calculateDamage($hit2Params);

        return [
            'hit1' => $hit1,
            'hit2' => $hit2,
            'totalDamage' => $hit1['finalDamage'] + $hit2['finalDamage'],
        ];
    }

    public static function calculateSkillDamageWithMlvl(int|float $baseSkillDmg, int|float $mlvl, int|float $enemyDefense, int|float $classModifier): int
    {
        $mlvlMultiplier = 1 + self::safeN($mlvl) * 0.02;
        $raw = self::safeN($baseSkillDmg) * self::safeN($classModifier, 1) * $mlvlMultiplier;

        return (int) max(1, floor($raw - self::safeN($enemyDefense)));
    }

    public static function calculateSkillDamage(int|float $baseAtk, int|float $skillMultiplier, int|float $enemyDefense, int|float $classModifier): int
    {
        $raw = self::safeN($baseAtk) * self::safeN($classModifier, 1) * self::safeN($skillMultiplier, 1);

        return (int) max(1, floor($raw - self::safeN($enemyDefense)));
    }

    public static function calculateAttackInterval(int|float $attackSpeed): int
    {
        $baseInterval = 2000;

        return (int) max(500, floor($baseInterval / max(1, self::safeN($attackSpeed, 1))));
    }

    public static function calculateDeathPenalty(int|float $currentLevel, int|float $currentXp, int|float $xpToNext, int|float $skillXp): array
    {
        $level = self::safeN($currentLevel, 1);

        if ($level <= 1) {
            return [
                'newLevel' => 1,
                'newXp' => (int) max(0, floor(self::safeN($currentXp) * 0.5)),
                'xpPercent' => 50,
                'levelsLost' => 0,
                'skillXpLoss' => (int) floor(self::safeN($skillXp) * 0.01),
            ];
        }

        if ($level <= 10) {
            $levelsLost = 1;
        } else {
            $pct = 0.03 + $level * 0.00002;
            $levelsLost = (int) max(1, floor($level * $pct));
        }
        $newLevel = (int) max(1, $level - $levelsLost);

        if ($level <= 5) {
            $xpPercent = 75;
        } elseif ($level <= 20) {
            $xpPercent = 50;
        } elseif ($level <= 50) {
            $xpPercent = 30;
        } elseif ($level <= 100) {
            $xpPercent = 15;
        } elseif ($level <= 300) {
            $xpPercent = 10;
        } else {
            $xpPercent = 5;
        }

        $newXp = (int) floor(self::safeN($xpToNext) * ($xpPercent / 100));

        $skillLossPct = min(0.03, 0.01 + $level * 0.00002);
        $skillLoss = (int) floor(self::safeN($skillXp) * $skillLossPct);

        return [
            'newLevel' => $newLevel,
            'newXp' => $newXp,
            'xpPercent' => $xpPercent,
            'levelsLost' => (int) $levelsLost,
            'skillXpLoss' => $skillLoss,
        ];
    }

    public static function applyDeathPenalty(int|float $currentXp, int|float $levelXp, int|float $skillXp): array
    {
        $xpLoss = floor(self::safeN($levelXp) * 0.1);
        $skillXpLoss = floor(self::safeN($skillXp) * 0.05);

        return [
            'newXp' => (int) max(0, self::safeN($currentXp) - $xpLoss),
            'newSkillXp' => (int) max(0, self::safeN($skillXp) - $skillXpLoss),
        ];
    }

    public static function getSpeedMultiplier(string $speed): float
    {
        return match ($speed) {
            'x1' => 1.0,
            'x2' => 2.0,
            'x4' => 4.0,
            'SKIP' => INF,
            default => 1.0,
        };
    }

    public static function getMonsterAttackRange(array $monster): array
    {
        $atk = self::safeN($monster['attack'] ?? null);
        $min = (int) max(1, floor(self::safeN($monster['attack_min'] ?? null, floor($atk * 0.8))));
        $max = (int) max($min, floor(self::safeN($monster['attack_max'] ?? null, floor($atk * 1.2))));

        return ['min' => $min, 'max' => $max];
    }

    public static function applyMonsterRarity(array $baseStats, string $rarity): array
    {
        $mult = self::MONSTER_STAT_MULTIPLIERS[$rarity];
        $atk = self::safeN($baseStats['attack'] ?? null);
        $baseMin = self::safeN($baseStats['attack_min'] ?? null, floor($atk * 0.8));
        $baseMax = self::safeN($baseStats['attack_max'] ?? null, floor($atk * 1.2));

        return [
            'hp' => (int) floor(self::safeN($baseStats['hp'] ?? null) * $mult['hp']),
            'attack' => (int) floor($atk * $mult['atk']),
            'attack_min' => (int) max(1, floor($baseMin * $mult['atk'])),
            'attack_max' => (int) max(1, floor($baseMax * $mult['atk'])),
            'defense' => (int) floor(self::safeN($baseStats['defense'] ?? null) * $mult['def']),
            'xp' => (int) floor(self::safeN($baseStats['xp'] ?? null) * $mult['xp']),
            'goldMin' => (int) floor(self::safeN($baseStats['gold'][0] ?? null) * $mult['gold']),
            'goldMax' => (int) floor(self::safeN($baseStats['gold'][1] ?? null) * $mult['gold']),
        ];
    }

    public static function getSpeedScaledCooldownMs(int|float $cooldownMs, int|float $speedMult): int
    {
        return (int) floor(max(0, $cooldownMs) / max(1, $speedMult));
    }
}
