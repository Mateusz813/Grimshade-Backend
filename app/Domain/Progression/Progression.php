<?php

declare(strict_types=1);

namespace App\Domain\Progression;

/**
 * Port logiki bramkowania z src/systems/progression.ts (getMonsterUnlockStatus).
 * Autorytet: „czy postać MOŻE walczyć z tym potworem".
 *  1. Level gate: monster.level <= characterLevel.
 *  2. Mastery gate: poprzedni (po poziomie) potwór musi mieć mastery >= 1.
 *
 * Portujemy podzbiór AUTORYTATYWNY: {unlocked, lockKind, requiredMonsterId}.
 * Stringi UI (shortLabel/reason po polsku) zostają na froncie — nie są autorytetem.
 */
final class Progression
{
    public const MASTERY_UNLOCK_THRESHOLD = 1;

    /**
     * @param  array{id:string, level:int}  $monster
     * @param  list<array{id:string, level:int}>  $allMonsters
     * @param  array<string, array{level:int}>  $masteries  monsterId → {level}
     * @return array{unlocked:bool, lockKind:?string, requiredMonsterId:?string}
     */
    public static function getUnlockState(array $monster, array $allMonsters, int $characterLevel, array $masteries): array
    {
        // Reguła 1: bramka poziomu.
        if ($monster['level'] > $characterLevel) {
            return ['unlocked' => false, 'lockKind' => 'level', 'requiredMonsterId' => null];
        }

        // Reguła 2: bramka mastery na poprzednim potworze.
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

    /**
     * @param  array{id:string, level:int}  $target
     * @param  list<array{id:string, level:int}>  $sortedMonsters
     * @return array{id:string, level:int}|null
     */
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
