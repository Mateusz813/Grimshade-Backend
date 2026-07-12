<?php

declare(strict_types=1);

namespace App\Domain\Items;

final class StoneSystem
{
    public const STONE_CONVERSION_CHAIN = [
        'common_stone' => 'rare_stone',
        'rare_stone' => 'epic_stone',
        'epic_stone' => 'legendary_stone',
        'legendary_stone' => 'mythic_stone',
        'mythic_stone' => 'heroic_stone',
    ];

    public const STONE_CONVERSION_COST = 100;

    public const STONE_CONVERSION_GOLD = 1000;

    public static function higherTier(string $stoneType): ?string
    {
        return self::STONE_CONVERSION_CHAIN[$stoneType] ?? null;
    }
}
