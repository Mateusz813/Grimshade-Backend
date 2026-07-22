<?php

declare(strict_types=1);

namespace App\Domain\Character;

class AttributeSystem
{
    public const ATTRIBUTE_POINT_PCT = 0.1;

    public const ATTRIBUTE_LEVEL_INTERVAL = 10;

    public const ATTRIBUTE_DEF_CAP_PCT = [
        'Knight' => 10,
        'Cleric' => 8,
        'Archer' => 6,
        'Rogue' => 6,
        'Bard' => 6,
        'Necromancer' => 4,
        'Mage' => 3,
    ];

    public static function getAttributePointsForLevel(int|float $highestLevel): int
    {
        return intdiv((int) max(1, floor($highestLevel ?: 1)), self::ATTRIBUTE_LEVEL_INTERVAL);
    }

    public static function getMaxDefensePoints(?string $characterClass): int
    {
        $cap = self::ATTRIBUTE_DEF_CAP_PCT[$characterClass ?? ''] ?? 5;

        return (int) round($cap / self::ATTRIBUTE_POINT_PCT);
    }

    /**
     * @param  array<string, mixed>  $allocation
     * @return array{attack: float, hp: float, defense: float}
     */
    public static function getAttributeMultipliers(array $allocation, ?string $characterClass): array
    {
        $pct = self::ATTRIBUTE_POINT_PCT / 100;
        $atkPoints = max(0, (int) ($allocation['attackPoints'] ?? 0));
        $hpPoints = max(0, (int) ($allocation['hpPoints'] ?? 0));
        $defPoints = min(
            max(0, (int) ($allocation['defensePoints'] ?? 0)),
            self::getMaxDefensePoints($characterClass),
        );

        return [
            'attack' => 1 + $atkPoints * $pct,
            'hp' => 1 + $hpPoints * $pct,
            'defense' => 1 + $defPoints * $pct,
        ];
    }
}
