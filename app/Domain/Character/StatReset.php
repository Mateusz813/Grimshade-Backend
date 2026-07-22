<?php

declare(strict_types=1);

namespace App\Domain\Character;

use App\Domain\Progression\LevelSystem;

final class StatReset
{
    public const CLASS_BASE_STATS = [
        'Knight' => ['attack' => 12, 'defense' => 8, 'max_hp' => 150, 'max_mp' => 40],
        'Mage' => ['attack' => 9, 'defense' => 3, 'max_hp' => 90, 'max_mp' => 200],
        'Cleric' => ['attack' => 8, 'defense' => 6, 'max_hp' => 115, 'max_mp' => 155],
        'Archer' => ['attack' => 11, 'defense' => 4, 'max_hp' => 110, 'max_mp' => 80],
        'Rogue' => ['attack' => 10, 'defense' => 4, 'max_hp' => 100, 'max_mp' => 75],
        'Necromancer' => ['attack' => 9, 'defense' => 3, 'max_hp' => 88, 'max_mp' => 200],
        'Bard' => ['attack' => 9, 'defense' => 4, 'max_hp' => 105, 'max_mp' => 125],
    ];

    public static function compute(
        string $characterClass,
        int $currentHp,
        int $currentMp,
        int $highestLevel,
    ): ?array {
        $base = self::CLASS_BASE_STATS[$characterClass] ?? null;
        if ($base === null) {
            return null;
        }

        $hpPerLevel = LevelSystem::BASE_HP_PER_LEVEL[$characterClass] ?? 4;
        $mpPerLevel = LevelSystem::BASE_MP_PER_LEVEL[$characterClass] ?? 3;

        $levelsGained = max(0, $highestLevel - 1);
        $totalEarned = AttributeSystem::getAttributePointsForLevel($highestLevel);

        $resetMaxHp = $base['max_hp'] + $levelsGained * $hpPerLevel;
        $resetMaxMp = $base['max_mp'] + $levelsGained * $mpPerLevel;

        return [
            'attack' => $base['attack'],
            'defense' => $base['defense'],
            'max_hp' => $resetMaxHp,
            'max_mp' => $resetMaxMp,
            'hp' => min($currentHp, $resetMaxHp),
            'mp' => min($currentMp, $resetMaxMp),
            'stat_points' => $totalEarned,
        ];
    }
}
