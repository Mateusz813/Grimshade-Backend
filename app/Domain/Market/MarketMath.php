<?php

declare(strict_types=1);

namespace App\Domain\Market;

/**
 * Port czystego podzbioru src/systems/marketSystem.ts: walidacja ceny/ilości,
 * podatek marketowy, typy stackowalne. (generateListingId — Date/rand; sort/
 * filter — UI — pominięte.)
 */
final class MarketMath
{
    public static function isValidPrice(int|float $price): bool
    {
        return self::isInteger($price) && $price >= 1 && $price <= 999_999_999;
    }

    public static function isValidQuantity(int|float $qty, int $max = 999_999): bool
    {
        return self::isInteger($qty) && $qty >= 1 && $qty <= $max;
    }

    /** 5% ceny, podłoga. */
    public static function calculateMarketTax(int|float $price): int
    {
        return (int) floor($price * 0.05);
    }

    public static function isStackKind(string $kind): bool
    {
        return in_array($kind, ['potion', 'elixir', 'stone', 'arena_points', 'spell_chest'], true);
    }

    /** Odpowiednik Number.isInteger — wartość całkowita i skończona. */
    private static function isInteger(int|float $n): bool
    {
        if (is_int($n)) {
            return true;
        }

        return is_finite($n) && floor($n) === (float) $n;
    }
}
