<?php

declare(strict_types=1);

namespace App\Domain\Combat;

use App\Domain\Progression\LevelSystem;

final class CombatLeavePenalty
{
    public static function computeLeavePenalty(int $currentLevel, int $currentXp, ?int $highestLevel): array
    {
        $penalty = LevelSystem::applyDeathPenalty($currentLevel, $currentXp);

        $currentHighest = $highestLevel ?? $currentLevel;
        $preservedHighest = max($currentHighest, $currentLevel);

        return [
            'oldLevel' => $currentLevel,
            'newLevel' => $penalty['newLevel'],
            'newXp' => $penalty['newXp'],
            'levelsLost' => $penalty['levelsLost'],
            'xpPercent' => $penalty['xpPercent'],
            'skillXpLossPercent' => $penalty['skillXpLossPercent'],
            'preservedHighest' => $preservedHighest,
            'protectionUsed' => false,
        ];
    }
}
