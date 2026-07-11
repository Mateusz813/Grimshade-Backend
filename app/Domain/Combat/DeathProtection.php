<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/**
 * Port 1:1 src/systems/deathProtection.ts (frontend). Ochrona przed śmiercią/
 * ucieczką (spec 2026-06-21).
 *
 * Dowolny przedmiot ochronny — eliksir ochrony ('death_protection') LUB amulet
 * straty ('amulet_of_loss') — w pełni chroni gracza: na śmierci ORAZ ucieczce
 * nie traci NICZEGO (level, XP, skill XP, ekwipunek), a dokładnie JEDEN
 * przedmiot ochronny jest zużywany. Priorytet: eliksir ochrony, potem amulet.
 *
 * PARYTET: w TS obie funkcje czytają/mutują Zustand `inventoryStore`. Tu są
 * czyste funkcje biorące mapę `consumables` jawnie (reguła getterów store'a),
 * a `consumeDeathProtection` zwraca NOWĄ mapę po zużyciu zamiast side effectu.
 * Golden-vectory (tests/Golden/fixtures/deathProtection.json) generowane z TS
 * przez realny store (setState → wywołanie) i odtwarzane tu bajt-w-bajt.
 *
 * Uwaga: inventoryStore.useConsumable ma bramkę poziomu tylko dla potionów
 * HP/MP (isHpMpPotionId) — przedmiotów ochronnych NIE dotyczy, więc port nie
 * zależy od poziomu postaci (czysty licznik).
 */
final class DeathProtection
{
    public const DEATH_PROTECTION_ID = 'death_protection';

    public const AMULET_OF_LOSS_ID = 'amulet_of_loss';

    /**
     * Sprawdzenie bez zużycia — czy gracz trzyma jakąkolwiek ochronę?
     *
     * @param  array<string, int|float>  $consumables
     */
    public static function hasDeathProtection(array $consumables): bool
    {
        return ($consumables[self::DEATH_PROTECTION_ID] ?? 0) > 0
            || ($consumables[self::AMULET_OF_LOSS_ID] ?? 0) > 0;
    }

    /**
     * Zużywa JEDEN przedmiot ochronny jeśli dostępny. Priorytet: eliksir ochrony,
     * potem amulet straty. Gdy `isProtected` = true, wołający MUSI pominąć CAŁĄ
     * karę (level + XP + skill XP + item loss) dla śmierci ORAZ ucieczki.
     *
     * @param  array<string, int|float>  $consumables
     * @return array{isProtected:bool, consumedId:string|null, consumables:array<string,int|float>}
     */
    public static function consumeDeathProtection(array $consumables): array
    {
        if (self::tryUseConsumable($consumables, self::DEATH_PROTECTION_ID)) {
            return [
                'isProtected' => true,
                'consumedId' => self::DEATH_PROTECTION_ID,
                'consumables' => $consumables,
            ];
        }

        if (self::tryUseConsumable($consumables, self::AMULET_OF_LOSS_ID)) {
            return [
                'isProtected' => true,
                'consumedId' => self::AMULET_OF_LOSS_ID,
                'consumables' => $consumables,
            ];
        }

        return [
            'isProtected' => false,
            'consumedId' => null,
            'consumables' => $consumables,
        ];
    }

    /**
     * Odpowiednik inventoryStore.useConsumable dla przedmiotów ochronnych: jeśli
     * licznik > 0, zdejmuje 1 (podłoga 0, jak Math.max(0, count - 1)) i zwraca
     * true; inaczej false bez zmiany mapy. Mutuje $consumables przez referencję.
     *
     * @param  array<string, int|float>  $consumables
     */
    private static function tryUseConsumable(array &$consumables, string $id): bool
    {
        $count = $consumables[$id] ?? 0;
        if ($count <= 0) {
            return false;
        }

        $consumables[$id] = max(0, ($consumables[$id] ?? 0) - 1);

        return true;
    }
}
