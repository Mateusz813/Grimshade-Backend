<?php

declare(strict_types=1);

namespace App\Domain\Character;

use App\Domain\Progression\LevelSystem;

final class StatReset
{
    public const CLASS_BASE_STATS = [
        'Knight' => ['attack' => 10, 'defense' => 5, 'max_hp' => 120, 'max_mp' => 30],
        'Mage' => ['attack' => 6, 'defense' => 2, 'max_hp' => 80, 'max_mp' => 200],
        'Cleric' => ['attack' => 7, 'defense' => 4, 'max_hp' => 100, 'max_mp' => 150],
        'Archer' => ['attack' => 10, 'defense' => 3, 'max_hp' => 100, 'max_mp' => 80],
        'Rogue' => ['attack' => 9, 'defense' => 3, 'max_hp' => 90, 'max_mp' => 60],
        'Necromancer' => ['attack' => 6, 'defense' => 2, 'max_hp' => 85, 'max_mp' => 180],
        'Bard' => ['attack' => 8, 'defense' => 3, 'max_hp' => 95, 'max_mp' => 120],
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
        $pointsPerLevel = LevelSystem::statPointsForLevelUp($characterClass);
        $totalEarned = $levelsGained * $pointsPerLevel;

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
