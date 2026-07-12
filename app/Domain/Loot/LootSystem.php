<?php

declare(strict_types=1);

namespace App\Domain\Loot;

use App\Domain\Support\Rng\RngInterface;

final class LootSystem
{
    public const RARITY_ORDER = ['common', 'rare', 'epic', 'legendary', 'mythic', 'heroic'];

    public const MONSTER_RARITY_CHANCES = [
        'normal' => 0.90, 'strong' => 0.07, 'epic' => 0.015, 'legendary' => 0.01, 'boss' => 0.005,
    ];

    public const MONSTER_RARITY_DROP_MAP = [
        'normal' => 'common', 'strong' => 'rare', 'epic' => 'epic', 'legendary' => 'legendary', 'boss' => 'mythic',
    ];

    public const MONSTER_RARITY_STONE_MAP = [
        'normal' => 'common_stone', 'strong' => 'rare_stone', 'epic' => 'epic_stone',
        'legendary' => 'legendary_stone', 'boss' => 'mythic_stone',
    ];

    private const BASE_STONE_DROP_CHANCE = [
        'normal' => 0.10, 'strong' => 0.07, 'epic' => 0.04, 'legendary' => 0.02, 'boss' => 0.01,
    ];

    private const POTION_FLAT_DROP_CHANCE = 0.004;

    private const POTION_PCT_DROP_CHANCE = 0.001;

    private const POTION_MEGA_DROP_CHANCE = 0.004;

    private const SELL_MULT = ['common' => 5, 'rare' => 20, 'epic' => 60, 'legendary' => 150, 'mythic' => 400, 'heroic' => 800];

    private const BASE_PRICE = ['common' => 10, 'rare' => 50, 'epic' => 200, 'legendary' => 500, 'mythic' => 2000, 'heroic' => 5000];

    public static function scaleHeroicDropRate(float $baseRate, int|float $monsterLevel): float
    {
        if ($baseRate <= 0) {
            return 0.0;
        }
        if ($monsterLevel <= 100) {
            return $baseRate;
        }
        $scaleFactor = max(0.20, 1.0 - ($monsterLevel - 100) * 0.00089);

        return $baseRate * $scaleFactor;
    }

    public static function getGeneratedSellPrice(string $rarity, int|float $level): int
    {
        return (int) floor((self::SELL_MULT[$rarity] ?? 5) * $level + (self::BASE_PRICE[$rarity] ?? 10));
    }

    public static function getMaxRarityForLevel(int|float $monsterLevel): string
    {
        if ($monsterLevel <= 30) {
            return 'common';
        }
        if ($monsterLevel <= 60) {
            return 'rare';
        }

        return 'epic';
    }

    public static function getEffectiveRarityChances(?array $masteryBonuses = null): array
    {
        $b = $masteryBonuses ?? ['strong' => 0, 'epic' => 0, 'legendary' => 0, 'mythic' => 0, 'heroic' => 0];
        $strongBonus = $b['strong'] / 100;
        $epicBonus = $b['epic'] / 100;
        $legendaryBonus = $b['legendary'] / 100;
        $bossBonus = $b['mythic'] / 100;

        $strongTotal = self::MONSTER_RARITY_CHANCES['strong'] + $strongBonus;
        $epicTotal = self::MONSTER_RARITY_CHANCES['epic'] + $epicBonus;
        $legendaryTotal = self::MONSTER_RARITY_CHANCES['legendary'] + $legendaryBonus;
        $bossTotal = self::MONSTER_RARITY_CHANCES['boss'] + $bossBonus;
        $normalTotal = max(0, 1 - $strongTotal - $epicTotal - $legendaryTotal - $bossTotal);
        $normalBonus = -($strongBonus + $epicBonus + $legendaryBonus + $bossBonus);

        return [
            'normal' => ['base' => self::MONSTER_RARITY_CHANCES['normal'], 'bonus' => $normalBonus, 'total' => $normalTotal],
            'strong' => ['base' => self::MONSTER_RARITY_CHANCES['strong'], 'bonus' => $strongBonus, 'total' => $strongTotal],
            'epic' => ['base' => self::MONSTER_RARITY_CHANCES['epic'], 'bonus' => $epicBonus, 'total' => $epicTotal],
            'legendary' => ['base' => self::MONSTER_RARITY_CHANCES['legendary'], 'bonus' => $legendaryBonus, 'total' => $legendaryTotal],
            'boss' => ['base' => self::MONSTER_RARITY_CHANCES['boss'], 'bonus' => $bossBonus, 'total' => $bossTotal],
        ];
    }

    public const ROLL_COUNTS = [
        'normal' => 2, 'strong' => 3, 'epic' => 4, 'legendary' => 5, 'boss' => 6,
    ];

    public const BASE_DROP_CHANCES = [
        'normal' => 0.08, 'strong' => 0.12, 'epic' => 0.15, 'legendary' => 0.20, 'boss' => 0.30,
    ];

    public static function rollLoot(
        RngInterface $rng,
        int $monsterLevel,
        string $monsterRarity,
        float $heroicDropRate,
        ItemGenerator $items,
    ): array {
        $numRolls = self::ROLL_COUNTS[$monsterRarity] ?? self::ROLL_COUNTS['normal'];
        $dropChance = self::BASE_DROP_CHANCES[$monsterRarity] ?? self::BASE_DROP_CHANCES['normal'];
        $scaledHeroicRate = self::scaleHeroicDropRate($heroicDropRate, $monsterLevel);

        $drops = [];
        for ($i = 0; $i < $numRolls; $i++) {
            if ($rng->nextFloat() < $dropChance) {
                $rarity = self::rollRarity($rng, $monsterRarity, $scaledHeroicRate);
                $item = $items->generateRandomItem($monsterLevel, $rarity);
                if ($item !== null) {
                    $drops[] = $item;
                }
            }
        }

        return array_slice($drops, 0, 5);
    }

    public static function rollMonsterRarity(RngInterface $rng, bool $isSkipMode = false, ?array $masteryBonuses = null): string
    {
        if ($isSkipMode) {
            return 'normal';
        }

        $bonuses = $masteryBonuses ?? ['strong' => 0, 'epic' => 0, 'legendary' => 0, 'mythic' => 0, 'heroic' => 0];
        $strongChance = self::MONSTER_RARITY_CHANCES['strong'] + $bonuses['strong'] / 100;
        $epicChance = self::MONSTER_RARITY_CHANCES['epic'] + $bonuses['epic'] / 100;
        $legendaryChance = self::MONSTER_RARITY_CHANCES['legendary'] + $bonuses['legendary'] / 100;
        $bossChance = self::MONSTER_RARITY_CHANCES['boss'] + $bonuses['mythic'] / 100;
        $normalChance = max(0.01, 1 - $strongChance - $epicChance - $legendaryChance - $bossChance);

        $roll = $rng->nextFloat();
        $cumulative = 0;
        $chances = [
            ['normal', $normalChance], ['strong', $strongChance], ['epic', $epicChance],
            ['legendary', $legendaryChance], ['boss', $bossChance],
        ];
        foreach ($chances as [$rarity, $chance]) {
            $cumulative += $chance;
            if ($roll < $cumulative) {
                return $rarity;
            }
        }

        return 'normal';
    }

    public static function rollRarity(RngInterface $rng, string $monsterRarity, float $heroicDropRate = 0): string
    {
        if ($monsterRarity === 'boss' && $heroicDropRate > 0 && $rng->nextFloat() < $heroicDropRate) {
            return 'heroic';
        }

        $maxRarity = self::MONSTER_RARITY_DROP_MAP[$monsterRarity];
        $maxIndex = array_search($maxRarity, self::RARITY_ORDER, true);

        $thresholds = [0.55, 0.25, 0.12, 0.05, 0.025, 0.005];
        $roll = $rng->nextFloat();
        $cumulative = 0;
        for ($i = 0; $i <= $maxIndex; $i++) {
            $cumulative += $thresholds[$i];
            if ($roll < $cumulative) {
                return self::RARITY_ORDER[$i];
            }
        }

        return self::RARITY_ORDER[$maxIndex];
    }

    public static function rollStoneDrop(RngInterface $rng, int|float $monsterLevel, string $monsterRarity): ?array
    {
        $chance = self::BASE_STONE_DROP_CHANCE[$monsterRarity] ?? self::BASE_STONE_DROP_CHANCE['normal'];
        if ($rng->nextFloat() < $chance) {
            return ['type' => self::MONSTER_RARITY_STONE_MAP[$monsterRarity], 'count' => 1];
        }

        return null;
    }

    public static function calculateGoldDrop(RngInterface $rng, array $goldRange, int|float $partySize = 1): int
    {
        [$min, $max] = $goldRange;
        $base = $min + (int) floor($rng->nextFloat() * ($max - $min + 1));
        $multiplier = 1 + ($partySize - 1) * 0.15;

        return (int) floor($base * $multiplier);
    }

    public static function rollPotionDrop(RngInterface $rng, int|float $monsterLevel): array
    {
        $drops = [];

        if ($monsterLevel >= 600) {
            [$hp, $mp, $mainChance] = ['hp_potion_divine', 'mp_potion_divine', self::POTION_PCT_DROP_CHANCE];
        } elseif ($monsterLevel >= 400) {
            [$hp, $mp, $mainChance] = ['hp_potion_ultimate', 'mp_potion_ultimate', self::POTION_PCT_DROP_CHANCE];
        } elseif ($monsterLevel >= 200) {
            [$hp, $mp, $mainChance] = ['hp_potion_super', 'mp_potion_super', self::POTION_PCT_DROP_CHANCE];
        } elseif ($monsterLevel >= 100) {
            [$hp, $mp, $mainChance] = ['hp_potion_great', 'mp_potion_great', self::POTION_PCT_DROP_CHANCE];
        } elseif ($monsterLevel >= 50) {
            [$hp, $mp, $mainChance] = ['hp_potion_lg', 'mp_potion_lg', self::POTION_FLAT_DROP_CHANCE];
        } elseif ($monsterLevel >= 20) {
            [$hp, $mp, $mainChance] = ['hp_potion_md', 'mp_potion_md', self::POTION_FLAT_DROP_CHANCE];
        } else {
            [$hp, $mp, $mainChance] = ['hp_potion_sm', 'mp_potion_sm', self::POTION_FLAT_DROP_CHANCE];
        }

        if ($rng->nextFloat() < $mainChance) {
            $drops[] = ['potionId' => $hp, 'count' => 1];
        }
        if ($rng->nextFloat() < $mainChance) {
            $drops[] = ['potionId' => $mp, 'count' => 1];
        }

        if ($monsterLevel >= 100) {
            if ($rng->nextFloat() < self::POTION_MEGA_DROP_CHANCE) {
                $drops[] = ['potionId' => 'hp_potion_mega', 'count' => 1];
            }
            if ($rng->nextFloat() < self::POTION_MEGA_DROP_CHANCE) {
                $drops[] = ['potionId' => 'mp_potion_mega', 'count' => 1];
            }
        }

        return $drops;
    }
}
