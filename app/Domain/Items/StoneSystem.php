<?php

declare(strict_types=1);

namespace App\Domain\Items;

/**
 * Port łańcucha konwersji kamieni ulepszeń z src/systems/itemSystem.ts
 * (STONE_CONVERSION_CHAIN / STONE_CONVERSION_COST / STONE_CONVERSION_GOLD).
 *
 * 100 kamieni niższego tieru + 1000 golda → 1 kamień wyższego tieru.
 * heroic_stone nie ma wyższego tieru (koniec łańcucha).
 */
final class StoneSystem
{
    /** @var array<string, string> lower → higher tier */
    public const STONE_CONVERSION_CHAIN = [
        'common_stone' => 'rare_stone',
        'rare_stone' => 'epic_stone',
        'epic_stone' => 'legendary_stone',
        'legendary_stone' => 'mythic_stone',
        'mythic_stone' => 'heroic_stone',
    ];

    public const STONE_CONVERSION_COST = 100;

    public const STONE_CONVERSION_GOLD = 1000;

    /** Wyższy tier kamienia albo null, gdy brak (koniec łańcucha). */
    public static function higherTier(string $stoneType): ?string
    {
        return self::STONE_CONVERSION_CHAIN[$stoneType] ?? null;
    }
}
