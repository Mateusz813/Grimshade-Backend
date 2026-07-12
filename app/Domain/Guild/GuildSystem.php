<?php

declare(strict_types=1);

namespace App\Domain\Guild;

use App\Domain\Support\Rng\RngInterface;
use DateTimeImmutable;
use DateTimeZone;

final class GuildSystem
{
    public const GUILD_INITIAL_MEMBER_CAP = 20;

    public const GUILD_CREATE_COST_GOLD = 1_000_000;

    public const GUILD_MAX_LEVEL = INF;

    public const GUILD_BOSS_MAX_TIER = 50;

    public const GUILD_TREASURY_SLOTS = 1000;

    public const GUILD_BOSS_HEROIC_MAX_CHANCE = 0.01;

    public const GUILD_BOSS_BLOCK_PCT = 0.10;

    public static function clampGuildBossTier(int|float $tier): int
    {
        if (! is_finite($tier) || $tier < 1) {
            return 1;
        }
        if ($tier > self::GUILD_BOSS_MAX_TIER) {
            return self::GUILD_BOSS_MAX_TIER;
        }

        return (int) floor($tier);
    }

    public static function getGuildBossMaxHp(int|float $tier): int
    {
        $tBoss = max(1, $tier);

        return (int) floor(2_000_000 * (1.25 ** ($tBoss - 1)));
    }

    public static function guildXpToNextLevel(int $level): int
    {
        if ($level <= 0) {
            return 0;
        }
        $tierForLevel = self::clampGuildBossTier($level);

        return (int) floor($level * self::getGuildBossMaxHp($tierForLevel));
    }

    public static function guildXpForLevel(int $level): int
    {
        $total = 0;
        for ($l = 1; $l < $level; $l++) {
            $total += self::guildXpToNextLevel($l);
        }

        return $total;
    }

    public static function guildMemberCap(int $level): int
    {
        return self::GUILD_INITIAL_MEMBER_CAP + max(0, $level - 1);
    }

    public static function applyGuildXp(int $currentLevel, int $currentXp, int $gain): array
    {
        $level = $currentLevel;
        $xp = $currentXp + max(0, $gain);
        $leveled = false;
        while ($xp >= self::guildXpToNextLevel($level)) {
            $xp -= self::guildXpToNextLevel($level);
            $level += 1;
            $leveled = true;
        }

        return ['level' => $level, 'xp' => $xp, 'leveledUp' => $leveled];
    }

    public static function computeGuildBossDamage(int|float $characterAttack, int|float $characterLevel, int|float $tier): int
    {
        $tBoss = max(1, $tier);
        $base = max(1, $characterAttack) * (1 + $characterLevel / 120);
        $scaled = $base * (1 + ($tBoss - 1) * 0.05);
        $cap = (int) floor(self::getGuildBossMaxHp($tier) * 0.05);

        return (int) max(1, min($cap, (int) floor($scaled)));
    }

    public static function contributionMultiplier(int|float $damageDealt, int|float $bossMaxHp): float
    {
        if ($bossMaxHp <= 0) {
            return 0.0;
        }
        $share = min(1, $damageDealt / $bossMaxHp);

        return max(0.05, 0.1 + $share * 1.9);
    }

    public static function getCurrentWeekStartIso(int $epochMs): string
    {
        $dt = self::utcFromMs($epochMs);
        $dow = (int) $dt->format('w');
        $isoDow = $dow === 0 ? 7 : $dow;

        return $dt->modify('-'.($isoDow - 1).' days')->format('Y-m-d');
    }

    public static function isGuildBossClaimDay(int $epochMs): bool
    {
        return (int) self::utcFromMs($epochMs)->format('w') === 0;
    }

    public static function getTodayIso(int $epochMs): string
    {
        return self::utcFromMs($epochMs)->format('Y-m-d');
    }

    private static function utcFromMs(int $epochMs): DateTimeImmutable
    {
        $seconds = intdiv($epochMs, 1000);

        return (new DateTimeImmutable('@'.$seconds))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function rollGuildBossRewards(int $tier, int $level, float $contribution, RngInterface $rng): array
    {
        $out = [];

        $goldBase = 1_000_000 * $tier * $contribution * (1 + $level / 50);
        $goldAmount = (int) floor($goldBase * (0.8 + $rng->nextFloat() * 0.4));
        if ($goldAmount > 0) {
            $out[] = ['kind' => 'gold', 'icon' => 'money-bag', 'label' => self::formatGoldShort($goldAmount).' golda', 'gold' => $goldAmount];
        }

        $xpAmount = (int) floor(50_000 * $tier * $contribution * (1 + $level / 30));
        if ($xpAmount > 0) {
            $out[] = ['kind' => 'xp', 'icon' => 'star', 'label' => '+'.self::formatPlInt($xpAmount).' XP', 'xp' => $xpAmount];
        }

        $commonStones = (int) max(1, floor(5 * $tier * $contribution));
        $out[] = ['kind' => 'stones', 'icon' => 'rock', 'label' => '+'.$commonStones.'× Kamień zwykły', 'stoneType' => 'common_stone', 'amount' => $commonStones];

        if ($rng->nextFloat() < min(0.8, 0.3 + $tier * 0.05)) {
            $rareStones = (int) max(1, floor(2 * $tier * $contribution));
            $out[] = ['kind' => 'stones', 'icon' => 'gem-stone', 'label' => '+'.$rareStones.'× Kamień rzadki', 'stoneType' => 'rare_stone', 'amount' => $rareStones];
        }

        if ($rng->nextFloat() < min(0.4, 0.1 + $tier * 0.03)) {
            $epicStones = (int) max(1, floor(1 * $tier * $contribution));
            $out[] = ['kind' => 'stones', 'icon' => 'large-blue-diamond', 'label' => '+'.$epicStones.'× Kamień epicki', 'stoneType' => 'epic_stone', 'amount' => $epicStones];
        }

        $potionCount = (int) max(1, floor(3 * $contribution));
        $out[] = ['kind' => 'potion', 'icon' => 'test-tube', 'label' => '+'.$potionCount.'× Mała mikstura HP + MP', 'consumables' => ['hp_potion_small' => $potionCount, 'mp_potion_small' => $potionCount]];

        $itemChance = min(0.95, 0.4 + $tier * 0.04);
        if ($rng->nextFloat() < $itemChance) {
            $r = $rng->nextFloat();
            $rarity = 'common';
            $heroicChance = min(self::GUILD_BOSS_HEROIC_MAX_CHANCE, $contribution * 0.01);
            if ($r < $heroicChance) {
                $rarity = 'heroic';
            } elseif ($r < 0.05) {
                $rarity = 'legendary';
            } elseif ($r < 0.2) {
                $rarity = 'epic';
            } elseif ($r < 0.5) {
                $rarity = 'rare';
            }
            $out[] = ['kind' => 'item', 'icon' => 'wrapped-gift', 'label' => 'Przedmiot '.strtoupper($rarity).' (lvl '.$level.')', 'rarity' => $rarity];
        }

        return $out;
    }

    private static function formatGoldShort(int $gold): string
    {
        $g = max(0, $gold);
        if ($g >= 10_000_000) {
            return self::formatTwoDecimals($g / 10_000_000).' sc';
        }
        if ($g >= 100_000) {
            return self::formatTwoDecimals($g / 100_000).' cc';
        }
        if ($g >= 1_000) {
            return self::formatTwoDecimals($g / 1_000).' k';
        }

        return $g.' gp';
    }

    private static function formatTwoDecimals(float $n): string
    {
        $truncated = floor($n * 100) / 100;

        return str_replace('.', ',', number_format($truncated, 2, '.', ''));
    }

    private static function formatPlInt(int $n): string
    {
        return number_format($n, 0, ',', "\u{00A0}");
    }
}
