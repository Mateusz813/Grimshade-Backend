<?php

declare(strict_types=1);

namespace App\Domain\Progression;

final class Progression
{
    public const MASTERY_UNLOCK_THRESHOLD = 1;

    public static function getUnlockState(array $monster, array $allMonsters, int $characterLevel, array $masteries): array
    {
        if ($monster['level'] > $characterLevel) {
            return ['unlocked' => false, 'lockKind' => 'level', 'requiredMonsterId' => null];
        }

        $sorted = $allMonsters;
        usort($sorted, static fn (array $a, array $b): int => $a['level'] <=> $b['level']);

        $prereq = self::findPrerequisite($monster, $sorted);
        if ($prereq === null) {
            return ['unlocked' => true, 'lockKind' => null, 'requiredMonsterId' => null];
        }

        $prereqLevel = $masteries[$prereq['id']]['level'] ?? 0;
        if ($prereqLevel < self::MASTERY_UNLOCK_THRESHOLD) {
            return ['unlocked' => false, 'lockKind' => 'mastery', 'requiredMonsterId' => $prereq['id']];
        }

        return ['unlocked' => true, 'lockKind' => null, 'requiredMonsterId' => null];
    }

    private static function findPrerequisite(array $target, array $sortedMonsters): ?array
    {
        $idx = null;
        foreach ($sortedMonsters as $i => $m) {
            if ($m['id'] === $target['id']) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null || $idx <= 0) {
            return null;
        }

        return $sortedMonsters[$idx - 1];
    }
}
