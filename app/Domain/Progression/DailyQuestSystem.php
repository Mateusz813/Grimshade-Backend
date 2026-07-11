<?php

declare(strict_types=1);

namespace App\Domain\Progression;

/**
 * Port 1:1 src/systems/dailyQuestSystem.ts (frontend). Dzienne questy: klucz
 * dnia, wykrycie resetu, deterministyczny wybor questow dnia, skalowanie nagrod.
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/dailyQuestSystem.json
 * (generowane z TS) sa tu odtwarzane bajt-w-bajt (DailyQuestSystemTest).
 *
 * BRAK RngInterface — w odroznieniu od loot/boss ten system NIE uzywa
 * Math.random. `selectDailyQuests` losuje DETERMINISTYCZNIE: seed pochodzi z
 * hasha klucza dnia, a tasowanie idzie po wlasnym LCG. Dla tego samego dnia
 * i poziomu wynik jest staly → czysta funkcja → bit-parity.
 *
 * DATA sparametryzowana (zamiast new Date()): `todayKey` / `needsRefresh` /
 * `selectDailyQuests` dostaja klucz dnia (lub rok/miesiac/dzien) jako argument.
 *
 * SEMANTYKA 32-bit JS odtworzona recznie, bo iloczyn LCG (seed*1103515245)
 * przekracza 2^53 — bez replikacji float64 (fmod mod 2^32) byl by rozjazd na
 * ostatnich bitach miedzy JS (Number) a natywnym int64 PHP.
 */
final class DailyQuestSystem
{
    /** Liczba questow przydzielanych na dzien. */
    public const DAILY_QUEST_COUNT = 12;

    private const UINT32 = 4294967296; // 2^32

    private const INT32_SIGN = 2147483648; // 2^31

    /**
     * Klucz dnia "YYYY-MM-DD". Rok bez paddingu, miesiac/dzien do 2 cyfr —
     * dokladnie jak TS (`padStart(2, '0')` na getMonth()+1 i getDate()).
     */
    public static function todayKey(int $year, int $month, int $day): string
    {
        return sprintf('%d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Czy questy dnia wymagaja odswiezenia (nowy dzien). Brak zapisanej daty
     * (null / pusty string, jak falsy w TS) → zawsze true.
     */
    public static function needsRefresh(?string $lastRefreshDate, string $todayKey): bool
    {
        if ($lastRefreshDate === null || $lastRefreshDate === '') {
            return true;
        }

        return $lastRefreshDate !== $todayKey;
    }

    /**
     * Wybiera DAILY_QUEST_COUNT questow dostepnych dla poziomu gracza. Gdy
     * eligible ≤ limit — zwraca je bez tasowania (kolejnosc z pliku). Powyzej —
     * deterministyczny Fisher-Yates seedowany kluczem dnia (staly wynik w obrebie
     * tego samego dnia).
     *
     * @param  list<array<string, mixed>>  $allQuests  pelna lista z dailyQuests.json
     * @return list<array<string, mixed>>
     */
    public static function selectDailyQuests(array $allQuests, int $playerLevel, string $todayKey): array
    {
        $eligible = array_values(array_filter(
            $allQuests,
            static fn (array $q): bool => $playerLevel >= $q['minLevel'],
        ));
        if (count($eligible) <= self::DAILY_QUEST_COUNT) {
            return $eligible;
        }

        // Seed z klucza dnia — hash 32-bit signed, jak JS ((seed<<5)-seed+char)|0.
        // (seed<<5)-seed = seed*31 (przystajace mod 2^32, wiec rownowazne po |0).
        $seed = 0;
        $len = strlen($todayKey);
        for ($i = 0; $i < $len; $i++) {
            $seed = self::toInt32(31 * $seed + \ord($todayKey[$i]));
        }

        $shuffled = $eligible;
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $seed = self::advanceSeed($seed);
            $j = $seed % ($i + 1);
            [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
        }

        return array_slice($shuffled, 0, self::DAILY_QUEST_COUNT);
    }

    /**
     * Skaluje bazowe nagrody poziomem gracza:
     *   gold = floor(base.gold * (1 + lvl*0.25) * 0.6)
     *   xp   = floor(base.xp   * (1 + lvl*0.3))
     * Eliksir (jesli jest) przechodzi bez zmian.
     *
     * @param  array{gold:int|float, xp:int|float, elixir?:string}  $base
     * @return array{gold:int, xp:int, elixir?:string}
     */
    public static function scaleRewards(array $base, int $playerLevel): array
    {
        $goldMultiplier = 1 + $playerLevel * 0.25;
        $xpMultiplier = 1 + $playerLevel * 0.3;

        $result = [
            'gold' => (int) floor($base['gold'] * $goldMultiplier * 0.6),
            'xp' => (int) floor($base['xp'] * $xpMultiplier),
        ];
        // TS zawsze ustawia `elixir: base.elixir`, ale gdy undefined JSON go pomija.
        if (isset($base['elixir'])) {
            $result['elixir'] = $base['elixir'];
        }

        return $result;
    }

    /**
     * Krok LCG: seed = (seed*1103515245 + 12345) & 0x7fffffff. Iloczyn liczony w
     * float64 (jak Number w JS) — dopiero potem redukcja mod 2^32 — bo dokladny
     * int64 dalby inne dolne bity przy iloczynach > 2^53.
     */
    private static function advanceSeed(int $seed): int
    {
        $product = (float) $seed * 1103515245.0 + 12345.0;

        return self::toUint32($product) & 0x7FFFFFFF;
    }

    /** JS ToUint32: wartosc mod 2^32 w [0, 2^32). fmod dokladny na integer-valued double. */
    private static function toUint32(float $x): int
    {
        $m = fmod($x, (float) self::UINT32);
        if ($m < 0.0) {
            $m += self::UINT32;
        }

        return (int) $m;
    }

    /** JS ToInt32: to samo co ToUint32, ale zmapowane na zakres ze znakiem. */
    private static function toInt32(int|float $x): int
    {
        $u = self::toUint32((float) $x);

        return $u >= self::INT32_SIGN ? $u - self::UINT32 : $u;
    }
}
