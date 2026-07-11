<?php

declare(strict_types=1);

namespace App\Domain\Items;

/**
 * Port ekonomicznego podzbioru src/systems/itemSystem.ts: koszty/mnożniki
 * ulepszeń, kara gear-gap, refund, cena sprzedaży. (Ikony/kolory/etykiety oraz
 * helpery zależne od parsowania id itemów pominięte — nie są autorytetem.)
 */
final class ItemEconomy
{
    /** @var array<string, int> */
    public const RARITY_BONUS_SLOTS = [
        'common' => 0, 'rare' => 1, 'epic' => 1, 'legendary' => 2, 'mythic' => 3, 'heroic' => 5,
    ];

    /** @var array<string, string> */
    public const STONE_FOR_RARITY = [
        'common' => 'common_stone', 'rare' => 'rare_stone', 'epic' => 'epic_stone',
        'legendary' => 'legendary_stone', 'mythic' => 'mythic_stone', 'heroic' => 'heroic_stone',
    ];

    /** @var array<string, float> */
    private const RARITY_SELL_MULTIPLIER = [
        'common' => 0.20, 'rare' => 0.35, 'epic' => 0.50, 'legendary' => 0.65, 'mythic' => 0.80, 'heroic' => 1.00,
    ];

    /** @var array<int, array{stones:int, gold:int, successRate:int|float}> */
    private const ENHANCEMENT_TABLE = [
        1 => ['stones' => 1, 'gold' => 100, 'successRate' => 100],
        2 => ['stones' => 1, 'gold' => 500, 'successRate' => 80],
        3 => ['stones' => 2, 'gold' => 2000, 'successRate' => 60],
        4 => ['stones' => 3, 'gold' => 5000, 'successRate' => 45],
        5 => ['stones' => 5, 'gold' => 15000, 'successRate' => 30],
        6 => ['stones' => 8, 'gold' => 50000, 'successRate' => 20],
        7 => ['stones' => 12, 'gold' => 150000, 'successRate' => 15],
        8 => ['stones' => 20, 'gold' => 500000, 'successRate' => 10],
        9 => ['stones' => 35, 'gold' => 1500000, 'successRate' => 5],
        10 => ['stones' => 50, 'gold' => 5000000, 'successRate' => 2],
        11 => ['stones' => 65, 'gold' => 8000000, 'successRate' => 1.5],
        12 => ['stones' => 85, 'gold' => 12000000, 'successRate' => 1],
        13 => ['stones' => 110, 'gold' => 18000000, 'successRate' => 0.7],
        14 => ['stones' => 140, 'gold' => 25000000, 'successRate' => 0.5],
        15 => ['stones' => 180, 'gold' => 35000000, 'successRate' => 0.3],
        16 => ['stones' => 230, 'gold' => 50000000, 'successRate' => 0.2],
        17 => ['stones' => 290, 'gold' => 70000000, 'successRate' => 0.12],
        18 => ['stones' => 370, 'gold' => 100000000, 'successRate' => 0.07],
        19 => ['stones' => 460, 'gold' => 150000000, 'successRate' => 0.03],
        20 => ['stones' => 580, 'gold' => 200000000, 'successRate' => 0.01],
    ];

    public static function getRequiredStoneType(string $itemRarity): string
    {
        return self::STONE_FOR_RARITY[$itemRarity] ?? 'common_stone';
    }

    /**
     * @return array{stones:int, gold:int, successRate:int|float, stoneType:string}
     */
    public static function getEnhancementCost(int $targetLevel, string $itemRarity = 'common'): array
    {
        $stoneType = self::getRequiredStoneType($itemRarity);

        if ($targetLevel <= 20) {
            $entry = self::ENHANCEMENT_TABLE[$targetLevel] ?? ['stones' => 1, 'gold' => 100, 'successRate' => 100];

            return [...$entry, 'stoneType' => $stoneType];
        }

        $prev = self::ENHANCEMENT_TABLE[20];
        $above = $targetLevel - 20;

        return [
            'stones' => (int) ceil($prev['stones'] * (1.3 ** $above)),
            'gold' => (int) ceil($prev['gold'] * (1.5 ** $above)),
            'successRate' => max(0.001, $prev['successRate'] * (0.5 ** $above)),
            'stoneType' => $stoneType,
        ];
    }

    /** +U → ×(1 + 0.10·U). */
    public static function getEnhancementMultiplier(int|float $upgradeLevel): float
    {
        if ($upgradeLevel <= 0) {
            return 1.0;
        }

        return 1 + $upgradeLevel * 0.10;
    }

    public static function getUpgradedBaseStat(int|float $baseValue, int|float $upgradeLevel): int|float
    {
        if ($baseValue <= 0 || $upgradeLevel <= 0) {
            return $baseValue;
        }
        $multiplied = self::jsRound($baseValue * self::getEnhancementMultiplier($upgradeLevel));
        $flatFloor = $baseValue + $upgradeLevel;

        return max($multiplied, $flatFloor);
    }

    public static function getEnhancedBaseStats(int|float $baseValue, int|float $upgradeLevel): int|float
    {
        return self::getUpgradedBaseStat($baseValue, $upgradeLevel);
    }

    /** dmg × (gearLvl/contentLvl)², podłoga 0.05, gdy pod-gearowany. */
    public static function getGearGapMultiplier(int|float $gearLevel, int|float $contentLevel): float
    {
        if ($contentLevel <= 0 || $gearLevel >= $contentLevel) {
            return 1.0;
        }

        return max(0.05, ($gearLevel / $contentLevel) ** 2);
    }

    /**
     * @return array{gold:int, stones:int, stoneType:string}
     */
    public static function getEnhancementRefund(int|float $enhanceLevel, string $itemRarity = 'common'): array
    {
        if (! $enhanceLevel || $enhanceLevel <= 0) {
            return ['gold' => 0, 'stones' => 0, 'stoneType' => ''];
        }
        $totalGold = 0;
        $totalStones = 0;
        $stoneType = '';
        for ($lvl = 1; $lvl <= $enhanceLevel; $lvl++) {
            $cost = self::getEnhancementCost($lvl, $itemRarity);
            $totalGold += $cost['gold'];
            $totalStones += $cost['stones'];
            $stoneType = $cost['stoneType'];
        }

        return ['gold' => $totalGold, 'stones' => $totalStones, 'stoneType' => $stoneType];
    }

    /**
     * @param  array{rarity:string, itemLevel?:int, upgradeLevel?:int}  $item
     * @param  array{basePrice:int|float}|null  $baseData
     */
    public static function getSellPrice(array $item, ?array $baseData = null): int
    {
        $basePrice = 0;
        if ($baseData !== null && ($baseData['basePrice'] ?? 0) > 0) {
            $mult = self::RARITY_SELL_MULTIPLIER[$item['rarity']] ?? 0.2;
            $priceFromBase = (int) floor($baseData['basePrice'] * $mult);
            $basePrice = $priceFromBase > 0 ? $priceFromBase : 0;
        }
        if ($basePrice <= 0) {
            $level = ($item['itemLevel'] ?? 0) ?: 1;
            $basePrice = self::sellPriceByRarity($item['rarity'], $level);
        }

        $refund = self::getEnhancementRefund($item['upgradeLevel'] ?? 0, $item['rarity']);

        return $basePrice + $refund['gold'];
    }

    private static function sellPriceByRarity(string $rarity, int|float $lvl): int
    {
        return match ($rarity) {
            'common' => (int) floor($lvl * 5 + 10),
            'rare' => (int) floor($lvl * 20 + 50),
            'epic' => (int) floor($lvl * 60 + 200),
            'legendary' => (int) floor($lvl * 150 + 500),
            'mythic' => (int) floor($lvl * 400 + 2000),
            'heroic' => (int) floor($lvl * 800 + 5000),
            default => (int) max(1, $lvl * 5 + 10),
        };
    }

    /** Math.round z JS (half-up dla nieujemnych). */
    private static function jsRound(float $x): int
    {
        return (int) floor($x + 0.5);
    }
}
