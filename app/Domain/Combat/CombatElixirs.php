<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/**
 * Port 1:1 src/systems/combatElixirs.ts (frontend). Czyste mnożniki / bonusy
 * eliksirów bojowych (Faza 9) + drenaż ich pausable-timerów. Bez RNG, bez
 * Date.now — deterministyczna matematyka.
 *
 * MODEL STANU (reguła 4 — gettery czytające Zustand buffStore przeniesione na
 * czyste funkcje z jawnym stanem):
 *  - Gettery biorą listę AKTYWNYCH efektów. Wszystkie eliksiry bojowe to buffy
 *    `pausable` (hasBuff == remainingMs > 0), więc „aktywny" == obecny na liście.
 *    `hasBuff(effect)` w TS -> `in_array($effect, $activeEffects)`.
 *  - tickCombatElixirs bierze mapę `effect => remainingMs` (tylko pausable) i
 *    liczbę ms; zwraca NOWĄ mapę przeżywających buffów po drenażu (buff usuwany
 *    gdy remainingMs spadnie do 0 — jak `consumePausableTime` w buffStore).
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/combatElixirs.json (generowane
 * z TS przez ustawienie stanu buffStore) są tu odtwarzane 1:1 (CombatElixirsTest).
 *
 * SEMANTYKA LICZB JS: JS nie rozróżnia int/float; wartości „2.0" itp. porównywane
 * są luźno (toEqual) — tu zwracamy je jako float dla czytelności mnożników.
 */
final class CombatElixirs
{
    /** Eliksiry bojowe zawsze drenowane co tick (bez reguł tierów). */
    public const ALWAYS_DRAIN = [
        'hp_boost_500',
        'mp_boost_500',
        'atk_boost_50',
        'def_boost_50',
        'hp_pct_25',
        'mp_pct_25',
        'attack_speed',
    ];

    /**
     * Grupy tierów — co tick drenuje TYLKO najwyższy aktywny tier, żeby niższe
     * zachowały pełen czas aż wyższy się skończy („no stacking, top tier first").
     *
     * @var list<string>
     */
    private const ATK_TIERS_HIGH_FIRST = ['atk_dmg_100', 'atk_dmg_50', 'atk_dmg_25'];

    /** @var list<string> */
    private const SPELL_TIERS_HIGH_FIRST = ['spell_dmg_100', 'spell_dmg_50', 'spell_dmg_25'];

    // -- Gettery mnożników / bonusów -----------------------------------------

    /** Mnożnik obrażeń ataku fizycznego gracza (1.0 = bez zmian). */
    public static function getAtkDamageMultiplier(array $activeEffects): float
    {
        if (self::hasBuff($activeEffects, 'atk_dmg_100')) {
            return 2.0;
        }
        if (self::hasBuff($activeEffects, 'atk_dmg_50')) {
            return 1.5;
        }
        if (self::hasBuff($activeEffects, 'atk_dmg_25')) {
            return 1.25;
        }

        return 1.0;
    }

    /** Mnożnik obrażeń zaklęć / skilli (1.0 = bez zmian). */
    public static function getSpellDamageMultiplier(array $activeEffects): float
    {
        if (self::hasBuff($activeEffects, 'spell_dmg_100')) {
            return 2.0;
        }
        if (self::hasBuff($activeEffects, 'spell_dmg_50')) {
            return 1.5;
        }
        if (self::hasBuff($activeEffects, 'spell_dmg_25')) {
            return 1.25;
        }

        return 1.0;
    }

    /** Płaski bonus do efektywnego Max HP gdy eliksir aktywny. */
    public static function getElixirHpBonus(array $activeEffects): int
    {
        return self::hasBuff($activeEffects, 'hp_boost_500') ? 500 : 0;
    }

    /** Płaski bonus do efektywnego Max MP gdy eliksir aktywny. */
    public static function getElixirMpBonus(array $activeEffects): int
    {
        return self::hasBuff($activeEffects, 'mp_boost_500') ? 500 : 0;
    }

    /** Procentowy mnożnik efektywnego Max HP (1.0 = bez zmian). */
    public static function getElixirHpPctMultiplier(array $activeEffects): float
    {
        return self::hasBuff($activeEffects, 'hp_pct_25') ? 1.25 : 1.0;
    }

    /** Procentowy mnożnik efektywnego Max MP (1.0 = bez zmian). */
    public static function getElixirMpPctMultiplier(array $activeEffects): float
    {
        return self::hasBuff($activeEffects, 'mp_pct_25') ? 1.25 : 1.0;
    }

    /** Płaski bonus do efektywnego Ataku gdy eliksir aktywny. */
    public static function getElixirAtkBonus(array $activeEffects): int
    {
        return self::hasBuff($activeEffects, 'atk_boost_50') ? 50 : 0;
    }

    /** Płaski bonus do efektywnej Obrony gdy eliksir aktywny. */
    public static function getElixirDefBonus(array $activeEffects): int
    {
        return self::hasBuff($activeEffects, 'def_boost_50') ? 50 : 0;
    }

    /** Mnożnik attack_speed (1.0 = bez zmian). Klasyczny eliksir AS = +20%. */
    public static function getElixirAttackSpeedMultiplier(array $activeEffects): float
    {
        return self::hasBuff($activeEffects, 'attack_speed') ? 1.20 : 1.0;
    }

    // -- Drenaż pausable-timerów ---------------------------------------------

    /**
     * Konsumuje `ms` ms realnego czasu walki z każdego aktywnego eliksiru:
     *  - wszystkie z ALWAYS_DRAIN drenowane niezależnie,
     *  - w grupach tierów drenuje TYLKO najwyższy aktywny tier (break po pierwszym).
     * Buff znika, gdy jego remainingMs spadnie do 0.
     *
     * @param  array<string, int|float>  $buffs  effect => remainingMs (pausable, >0)
     * @return array<string, int|float>
     */
    public static function tickCombatElixirs(array $buffs, int|float $ms): array
    {
        foreach (self::ALWAYS_DRAIN as $effect) {
            if (self::hasRemaining($buffs, $effect)) {
                self::consumePausableTime($buffs, $effect, $ms);
            }
        }

        // Grupy tierów — drenuj tylko najwyższy aktywny, żeby niższe zachowały
        // pełen czas aż wyższy się wyczerpie.
        foreach ([self::ATK_TIERS_HIGH_FIRST, self::SPELL_TIERS_HIGH_FIRST] as $group) {
            foreach ($group as $effect) {
                if (self::hasRemaining($buffs, $effect)) {
                    self::consumePausableTime($buffs, $effect, $ms);
                    break; // tylko pierwszy trafiony (= najwyższy tier) drenuje
                }
            }
        }

        return $buffs;
    }

    /** hasBuff dla getterów: efekt jest na liście aktywnych. */
    private static function hasBuff(array $activeEffects, string $effect): bool
    {
        return in_array($effect, $activeEffects, true);
    }

    /** hasBuff dla pausable: buff aktywny gdy remainingMs > 0. */
    private static function hasRemaining(array $buffs, string $effect): bool
    {
        return ($buffs[$effect] ?? 0) > 0;
    }

    /**
     * Odpowiednik buffStore.consumePausableTime: drenuje min(ms, remainingMs)
     * i usuwa buff, gdy remainingMs osiągnie 0. Mutuje `$buffs` przez referencję.
     * Zwraca faktycznie skonsumowane ms.
     *
     * @param  array<string, int|float>  $buffs
     */
    private static function consumePausableTime(array &$buffs, string $effect, int|float $ms): int|float
    {
        $remaining = $buffs[$effect] ?? 0;
        if ($remaining <= 0) {
            return 0;
        }

        $consumed = min($ms, $remaining);
        $newRemaining = $remaining - $consumed;
        if ($newRemaining <= 0) {
            unset($buffs[$effect]);
        } else {
            $buffs[$effect] = $newRemaining;
        }

        return $consumed;
    }
}
