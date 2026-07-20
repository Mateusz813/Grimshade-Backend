<?php

declare(strict_types=1);

namespace App\Domain\Combat;

final class CombatElixirs
{
    public const ALWAYS_DRAIN = [
        'hp_boost_500',
        'mp_boost_500',
        'atk_boost_50',
        'def_boost_50',
        'hp_pct_25',
        'mp_pct_25',
        'attack_speed',
    ];

    private const ATK_TIERS_HIGH_FIRST = ['atk_dmg_100', 'atk_dmg_50', 'atk_dmg_25'];

    private const SPELL_TIERS_HIGH_FIRST = ['spell_dmg_100', 'spell_dmg_50', 'spell_dmg_25'];

    public static function getAtkDamageMultiplier(array $activeEffects): float
    {
        if (self::hasBuff($activeEffects, 'atk_dmg_100')) {
            return 1.25;
        }
        if (self::hasBuff($activeEffects, 'atk_dmg_50')) {
            return 1.15;
        }
        if (self::hasBuff($activeEffects, 'atk_dmg_25')) {
            return 1.08;
        }

        return 1.0;
    }

    public static function getSpellDamageMultiplier(array $activeEffects): float
    {
        if (self::hasBuff($activeEffects, 'spell_dmg_100')) {
            return 1.25;
        }
        if (self::hasBuff($activeEffects, 'spell_dmg_50')) {
            return 1.15;
        }
        if (self::hasBuff($activeEffects, 'spell_dmg_25')) {
            return 1.08;
        }

        return 1.0;
    }

    public static function getXpBoostMultiplier(array $activeEffects): float
    {
        $base = self::hasBuff($activeEffects, 'xp_boost_100')
            ? 2.0
            : (self::hasBuff($activeEffects, 'xp_boost') ? 1.5 : 1.0);
        $premium = self::hasBuff($activeEffects, 'premium_xp_boost') ? 2.0 : 1.0;

        return $base * $premium;
    }

    public static function getSkillXpBoostMultiplier(array $activeEffects): float
    {
        return self::hasBuff($activeEffects, 'skill_xp_boost_100')
            ? 2.0
            : (self::hasBuff($activeEffects, 'skill_xp_boost') ? 1.5 : 1.0);
    }

    public static function activeBuffEffects(array $blob, ?string $characterId, int|float $nowMs): array
    {
        $out = [];
        foreach (($blob['buffs']['allBuffs'] ?? []) as $buff) {
            if (! is_array($buff)) {
                continue;
            }
            $effect = $buff['effect'] ?? null;
            if (! is_string($effect) || $effect === '') {
                continue;
            }
            if ($characterId !== null && isset($buff['characterId']) && (string) $buff['characterId'] !== $characterId) {
                continue;
            }
            if (self::isBuffActive($buff, $nowMs)) {
                $out[] = $effect;
            }
        }

        return $out;
    }

    private static function isBuffActive(array $buff, int|float $nowMs): bool
    {
        if ((int) ($buff['charges'] ?? 0) > 0) {
            return true;
        }
        $mode = $buff['timerMode'] ?? 'realtime';
        if ($mode === 'game') {
            return (float) ($buff['gameMsRemaining'] ?? 0) > 0;
        }
        if ($mode === 'pausable') {
            return (float) ($buff['remainingMs'] ?? 0) > 0;
        }

        return (float) ($buff['expiresAt'] ?? 0) > $nowMs;
    }

    public static function getElixirHpBonus(array $activeEffects): int
    {
        return self::hasBuff($activeEffects, 'hp_boost_500') ? 500 : 0;
    }

    public static function getElixirMpBonus(array $activeEffects): int
    {
        return self::hasBuff($activeEffects, 'mp_boost_500') ? 500 : 0;
    }

    public static function getElixirHpPctMultiplier(array $activeEffects): float
    {
        return self::hasBuff($activeEffects, 'hp_pct_25') ? 1.25 : 1.0;
    }

    public static function getElixirMpPctMultiplier(array $activeEffects): float
    {
        return self::hasBuff($activeEffects, 'mp_pct_25') ? 1.25 : 1.0;
    }

    public static function getElixirAtkBonus(array $activeEffects): int
    {
        return self::hasBuff($activeEffects, 'atk_boost_50') ? 50 : 0;
    }

    public static function getElixirDefBonus(array $activeEffects): int
    {
        return self::hasBuff($activeEffects, 'def_boost_50') ? 50 : 0;
    }

    public static function getElixirAttackSpeedMultiplier(array $activeEffects): float
    {
        return self::hasBuff($activeEffects, 'attack_speed') ? 1.20 : 1.0;
    }

    public static function tickCombatElixirs(array $buffs, int|float $ms): array
    {
        foreach (self::ALWAYS_DRAIN as $effect) {
            if (self::hasRemaining($buffs, $effect)) {
                self::consumePausableTime($buffs, $effect, $ms);
            }
        }

        foreach ([self::ATK_TIERS_HIGH_FIRST, self::SPELL_TIERS_HIGH_FIRST] as $group) {
            foreach ($group as $effect) {
                if (self::hasRemaining($buffs, $effect)) {
                    self::consumePausableTime($buffs, $effect, $ms);
                    break;
                }
            }
        }

        return $buffs;
    }

    private static function hasBuff(array $activeEffects, string $effect): bool
    {
        return in_array($effect, $activeEffects, true);
    }

    private static function hasRemaining(array $buffs, string $effect): bool
    {
        return ($buffs[$effect] ?? 0) > 0;
    }

    private static function consumePausableTime(array &$buffs, string $effect, int|float $ms): int|float
    {
        $remaining = $buffs[$effect] ?? 0;
        if ($remaining <= 0) {
            return 0;
        }

        $consumed = min($ms, $remaining);
        $newRemaining = $remaining - $consumed;
        if ($newRemaining <= 0) {
            unset($buffs[$effect]);
        } else {
            $buffs[$effect] = $newRemaining;
        }

        return $consumed;
    }
}
