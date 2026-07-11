<?php

declare(strict_types=1);

namespace App\Domain\Items;

/**
 * Port 1:1 trzech plików frontu:
 *   - src/systems/potionGating.ts    (gating potek po poziomie postaci)
 *   - src/systems/potionSystem.ts    (kategoryzacja, cooldowny, pule, gettery)
 *   - src/systems/potionConversion.ts (alchemia: łańcuch konwersji + gating)
 *
 * System CZYSTY/deterministyczny — zero RNG, zero Eloquent, zero now(). Golden
 * bit-parity: tests/Golden/fixtures/potionSystem.json (generowane z TS) są tu
 * odtwarzane bajt-w-bajt (PotionSystemTest).
 *
 * POMINIĘTO (UI, nie autorytet): name_pl/name_en/description/icon/price z ELIXIRS
 * oraz inputName/inputIcon/outputName/outputIcon z konwersji. Portujemy tylko
 * logikę: id, effect, kolejność pul, gating, koszty/sortowanie konwersji oraz
 * wartości leczenia parsowane z effect-stringa (protokół tekstowy).
 *
 * ELIXIRS nie jest plikiem game-content JSON — jest zdefiniowany inline w
 * src/stores/shopStore.ts. Odwzorowany tu jako stałe (tylko id + effect potek
 * oraz pełna lista id elixirów potrzebna resolveAutoPotionElixir do wiernego
 * odtworzenia `ELIXIRS.find`).
 */
final class PotionSystem
{
    // -- potionGating ---------------------------------------------------------

    /** Poziom odblokowania per sufiks-tier id poteki. */
    public const TIER_MIN_LEVEL = [
        'sm' => 1,
        'md' => 20,
        'lg' => 50,
        'mega' => 100,
        'great' => 200,
        'super' => 350,
        'ultimate' => 500,
        'divine' => 700,
    ];

    /** Minimalny poziom potek procentowych (Wielki HP/MP = lvl 100). */
    public const PCT_POTION_MIN_LEVEL = 100;

    // -- potionSystem: kategoryzacja ------------------------------------------

    /** @var list<string> id procentowych potek HP (Great, Super, Ultimate, Divine). */
    public const PCT_HP_POTION_IDS = ['hp_potion_great', 'hp_potion_super', 'hp_potion_ultimate', 'hp_potion_divine'];

    /** @var list<string> id procentowych potek MP. */
    public const PCT_MP_POTION_IDS = ['mp_potion_great', 'mp_potion_super', 'mp_potion_ultimate', 'mp_potion_divine'];

    /** @var list<string> id płaskich (nieprocentowych) potek HP (Small, normal, Strong). */
    public const FLAT_HP_POTION_IDS = ['hp_potion_sm', 'hp_potion_md', 'hp_potion_lg'];

    /** @var list<string> id płaskich potek MP. */
    public const FLAT_MP_POTION_IDS = ['mp_potion_sm', 'mp_potion_md', 'mp_potion_lg'];

    /** Cooldown potek płaskich (ms) — 1 sekunda. */
    public const FLAT_POTION_COOLDOWN_MS = 1000;

    /** Cooldown potek procentowych (ms) — 0.5 sekundy. */
    public const PCT_POTION_COOLDOWN_MS = 500;

    /**
     * Katalog potek HP/MP w KOLEJNOŚCI z ELIXIRS (shopStore.ts). Z niego
     * pochodzą wszystkie pule (ALL_/FLAT_/PCT_) — filtrowane po `effect`.
     *
     * @var list<array{id:string, effect:string}>
     */
    public const HP_MP_POTIONS = [
        ['id' => 'hp_potion_sm', 'effect' => 'heal_hp_50'],
        ['id' => 'hp_potion_md', 'effect' => 'heal_hp_150'],
        ['id' => 'hp_potion_lg', 'effect' => 'heal_hp_400'],
        ['id' => 'hp_potion_mega', 'effect' => 'heal_hp_1000'],
        ['id' => 'hp_potion_great', 'effect' => 'heal_hp_pct_20'],
        ['id' => 'hp_potion_super', 'effect' => 'heal_hp_pct_35'],
        ['id' => 'hp_potion_ultimate', 'effect' => 'heal_hp_pct_50'],
        ['id' => 'hp_potion_divine', 'effect' => 'heal_hp_pct_100'],
        ['id' => 'mp_potion_sm', 'effect' => 'heal_mp_30'],
        ['id' => 'mp_potion_md', 'effect' => 'heal_mp_100'],
        ['id' => 'mp_potion_lg', 'effect' => 'heal_mp_300'],
        ['id' => 'mp_potion_mega', 'effect' => 'heal_mp_1000'],
        ['id' => 'mp_potion_great', 'effect' => 'heal_mp_pct_20'],
        ['id' => 'mp_potion_super', 'effect' => 'heal_mp_pct_35'],
        ['id' => 'mp_potion_ultimate', 'effect' => 'heal_mp_pct_50'],
        ['id' => 'mp_potion_divine', 'effect' => 'heal_mp_pct_100'],
    ];

    /**
     * Wszystkie id z ELIXIRS (shopStore.ts) w kolejności deklaracji. Potrzebne
     * TYLKO do wiernego odtworzenia `ELIXIRS.find(e => e.id === preferredId)` w
     * resolveAutoPotionElixir — poteki HP/MP + wszystkie elixiry buff/utility.
     *
     * @var list<string>
     */
    public const ELIXIR_IDS = [
        'hp_potion_sm', 'hp_potion_md', 'hp_potion_lg', 'hp_potion_mega',
        'hp_potion_great', 'hp_potion_super', 'hp_potion_ultimate', 'hp_potion_divine',
        'mp_potion_sm', 'mp_potion_md', 'mp_potion_lg', 'mp_potion_mega',
        'mp_potion_great', 'mp_potion_super', 'mp_potion_ultimate', 'mp_potion_divine',
        'xp_boost', 'xp_boost_100', 'skill_xp_boost', 'skill_xp_boost_100',
        'attack_speed_elixir', 'cd_reduction_elixir',
        'atk_dmg_elixir_25', 'atk_dmg_elixir_50', 'atk_dmg_elixir_100',
        'spell_dmg_elixir_25', 'spell_dmg_elixir_50', 'spell_dmg_elixir_100',
        'hp_boost_elixir', 'mp_boost_elixir', 'atk_boost_elixir',
        'hp_pct_elixir_25', 'mp_pct_elixir_25',
        'dungeon_reset', 'boss_reset', 'death_protection', 'amulet_of_loss',
        'stat_reset', 'offline_training_boost', 'utamo_vita', 'premium_xp_boost',
    ];

    // -- potionConversion: surowe receptury (chain) ---------------------------

    /** @var array<string, int> HP przed MP w kolejności UI. */
    private const FAMILY_ORDER = ['hp' => 0, 'mp' => 1];

    /**
     * Surowe receptury alchemii (koszty inputCount = ceil(shopPrice(out)/shopPrice(in))
     * — anti-exploit). Literalny `outputMinLevel` z TS jest IGNOROWANY: pochodna
     * niżej wyprowadza go z getPotionMinLevel(outputId) i sortuje.
     *
     * @var list<array{tier:int, family:string, inputId:string, inputCount:int, outputId:string}>
     */
    private const RAW_POTION_CONVERSIONS = [
        // -- HP --
        ['tier' => 1, 'family' => 'hp', 'inputId' => 'hp_potion_sm', 'inputCount' => 5, 'outputId' => 'hp_potion_md'],
        ['tier' => 2, 'family' => 'hp', 'inputId' => 'hp_potion_md', 'inputCount' => 4, 'outputId' => 'hp_potion_lg'],
        ['tier' => 3, 'family' => 'hp', 'inputId' => 'hp_potion_lg', 'inputCount' => 334, 'outputId' => 'hp_potion_great'],
        ['tier' => 4, 'family' => 'hp', 'inputId' => 'hp_potion_great', 'inputCount' => 2, 'outputId' => 'hp_potion_super'],
        ['tier' => 5, 'family' => 'hp', 'inputId' => 'hp_potion_super', 'inputCount' => 2, 'outputId' => 'hp_potion_ultimate'],
        ['tier' => 6, 'family' => 'hp', 'inputId' => 'hp_potion_ultimate', 'inputCount' => 2, 'outputId' => 'hp_potion_divine'],
        // Alternatywna gałąź płaska: 25× Silny -> 1× Mega
        ['tier' => 7, 'family' => 'hp', 'inputId' => 'hp_potion_lg', 'inputCount' => 25, 'outputId' => 'hp_potion_mega'],
        // -- MP --
        ['tier' => 1, 'family' => 'mp', 'inputId' => 'mp_potion_sm', 'inputCount' => 5, 'outputId' => 'mp_potion_md'],
        ['tier' => 2, 'family' => 'mp', 'inputId' => 'mp_potion_md', 'inputCount' => 4, 'outputId' => 'mp_potion_lg'],
        ['tier' => 3, 'family' => 'mp', 'inputId' => 'mp_potion_lg', 'inputCount' => 334, 'outputId' => 'mp_potion_great'],
        ['tier' => 4, 'family' => 'mp', 'inputId' => 'mp_potion_great', 'inputCount' => 2, 'outputId' => 'mp_potion_super'],
        ['tier' => 5, 'family' => 'mp', 'inputId' => 'mp_potion_super', 'inputCount' => 2, 'outputId' => 'mp_potion_ultimate'],
        ['tier' => 6, 'family' => 'mp', 'inputId' => 'mp_potion_ultimate', 'inputCount' => 2, 'outputId' => 'mp_potion_divine'],
        ['tier' => 7, 'family' => 'mp', 'inputId' => 'mp_potion_lg', 'inputCount' => 25, 'outputId' => 'mp_potion_mega'],
    ];

    // ==== potionGating =======================================================

    /** True dla id potek HP/MP (`hp_potion_*` / `mp_potion_*`). */
    public static function isHpMpPotionId(string $id): bool
    {
        return str_starts_with($id, 'hp_potion_') || str_starts_with($id, 'mp_potion_');
    }

    /**
     * Poziom postaci wymagany do kupna / użycia / craftu poteki. Nie-poteki
     * (elixiry buff, amulety, reset statów) i nieznane tiery zwracają 1.
     */
    public static function getPotionMinLevel(string $id): int
    {
        if (! self::isHpMpPotionId($id)) {
            return 1;
        }
        $tier = substr($id, strrpos($id, '_') + 1); // 'hp_potion_mega' -> 'mega'

        return self::TIER_MIN_LEVEL[$tier] ?? 1;
    }

    /** Czy postać o `level` może użyć (wypić / kupić / skraftować) tej poteki? */
    public static function canUsePotionAtLevel(string $id, int|float $level): bool
    {
        return $level >= self::getPotionMinLevel($id);
    }

    // ==== potionSystem: kategoryzacja ========================================

    /** Czy effect poteki jest procentowy. */
    public static function isPctPotion(string $effect): bool
    {
        return str_contains($effect, '_pct_');
    }

    /** Czy id poteki jest procentowe. */
    public static function isPctPotionId(string $potionId): bool
    {
        return in_array($potionId, self::PCT_HP_POTION_IDS, true)
            || in_array($potionId, self::PCT_MP_POTION_IDS, true);
    }

    /** Czy id poteki jest płaskie (nieprocentowe). */
    public static function isFlatPotionId(string $potionId): bool
    {
        return in_array($potionId, self::FLAT_HP_POTION_IDS, true)
            || in_array($potionId, self::FLAT_MP_POTION_IDS, true);
    }

    /** Odpowiedni cooldown (ms) dla poteki. */
    public static function getPotionCooldownMs(string $potionId): int
    {
        return self::isPctPotionId($potionId) ? self::PCT_POTION_COOLDOWN_MS : self::FLAT_POTION_COOLDOWN_MS;
    }

    /**
     * Etykieta leczenia parsowana z effect-stringa (protokół): heal_hp_50 ->
     * "+50 HP", heal_hp_pct_20 -> "+20% HP". Nierozpoznany effect -> zwraca go
     * bez zmian. Kolejność regexów jak w TS (płaski przed procentowym).
     */
    public static function getPotionLabel(string $effect): string
    {
        if (preg_match('/^heal_(hp|mp)_(\d+)$/', $effect, $m) === 1) {
            return '+'.$m[2].' '.strtoupper($m[1]);
        }
        if (preg_match('/^heal_(hp|mp)_pct_(\d+)$/', $effect, $m) === 1) {
            return '+'.$m[2].'% '.strtoupper($m[1]);
        }

        return $effect;
    }

    // ==== potionSystem: pule =================================================

    /** @return list<array{id:string, effect:string}> */
    public static function allHpPotions(): array
    {
        return array_values(array_filter(
            self::HP_MP_POTIONS,
            static fn (array $p): bool => str_starts_with($p['effect'], 'heal_hp'),
        ));
    }

    /** @return list<array{id:string, effect:string}> */
    public static function allMpPotions(): array
    {
        return array_values(array_filter(
            self::HP_MP_POTIONS,
            static fn (array $p): bool => str_starts_with($p['effect'], 'heal_mp'),
        ));
    }

    /** @return list<array{id:string, effect:string}> */
    public static function flatHpPotions(): array
    {
        return array_values(array_filter(
            self::allHpPotions(),
            static fn (array $p): bool => ! self::isPctPotion($p['effect']),
        ));
    }

    /** @return list<array{id:string, effect:string}> */
    public static function flatMpPotions(): array
    {
        return array_values(array_filter(
            self::allMpPotions(),
            static fn (array $p): bool => ! self::isPctPotion($p['effect']),
        ));
    }

    /** @return list<array{id:string, effect:string}> */
    public static function pctHpPotions(): array
    {
        return array_values(array_filter(
            self::allHpPotions(),
            static fn (array $p): bool => self::isPctPotion($p['effect']),
        ));
    }

    /** @return list<array{id:string, effect:string}> */
    public static function pctMpPotions(): array
    {
        return array_values(array_filter(
            self::allMpPotions(),
            static fn (array $p): bool => self::isPctPotion($p['effect']),
        ));
    }

    // ==== potionSystem: gettery (stan jako jawne parametry) ==================

    /**
     * Najsilniejsza potka z listy, którą postać posiada I może wypić (level-gated);
     * fallback do najsilniejszej level-odpowiedniej gdy żadnej nie posiada.
     * Zwraca id (tożsamość poteki) lub null — reszta pól IElixir to UI.
     *
     * @param  list<array{id:string, effect?:string}>  $potions
     * @param  array<string, int|float>  $consumables
     */
    public static function getBestPotion(array $potions, array $consumables, int|float $characterLevel = INF): ?string
    {
        $reversed = array_reverse(array_values($potions));

        foreach ($reversed as $p) {
            if (($consumables[$p['id']] ?? 0) > 0 && self::canUsePotionAtLevel($p['id'], $characterLevel)) {
                return $p['id'];
            }
        }
        foreach ($reversed as $p) {
            if (self::canUsePotionAtLevel($p['id'], $characterLevel)) {
                return $p['id'];
            }
        }

        return null;
    }

    /**
     * Elixir do slotu auto-potki. Preferuje skonfigurowaną potkę; jeśli licznik
     * 0 (lub za niski poziom) — fallback do najsilniejszej posiadanej z pasującej
     * puli. Zwraca id lub null. Nigdy nie wybiera potki, której postać nie może
     * wypić (za niski poziom).
     *
     * @param  array<string, int|float>  $consumables
     */
    public static function resolveAutoPotionElixir(
        ?string $preferredId,
        string $hpOrMp,
        string $slotKind,
        array $consumables,
        int|float $characterLevel = INF,
    ): ?string {
        // TS: `if (preferredId)` — '' i undefined są falsy.
        if ($preferredId !== null && $preferredId !== '') {
            $exists = in_array($preferredId, self::ELIXIR_IDS, true);
            if ($exists
                && ($consumables[$preferredId] ?? 0) > 0
                && self::canUsePotionAtLevel($preferredId, $characterLevel)) {
                return $preferredId;
            }
        }

        $pool = $hpOrMp === 'hp'
            ? ($slotKind === 'pct' ? self::pctHpPotions() : self::flatHpPotions())
            : ($slotKind === 'pct' ? self::pctMpPotions() : self::flatMpPotions());

        foreach (array_reverse($pool) as $p) {
            if (($consumables[$p['id']] ?? 0) > 0 && self::canUsePotionAtLevel($p['id'], $characterLevel)) {
                return $p['id'];
            }
        }

        return null;
    }

    // ==== potionConversion ===================================================

    /**
     * Wyprowadzone + posortowane receptury (odpowiednik POTION_CONVERSIONS):
     * outputMinLevel = getPotionMinLevel(outputId); sort po (rodzina HP<MP,
     * outputMinLevel rosnąco, tier). Tier jako stabilny tiebreak.
     *
     * @return list<array{tier:int, family:string, inputId:string, inputCount:int, outputId:string, outputMinLevel:int}>
     */
    public static function potionConversions(): array
    {
        $list = array_map(
            static fn (array $c): array => [
                ...$c,
                'outputMinLevel' => self::getPotionMinLevel($c['outputId']),
            ],
            self::RAW_POTION_CONVERSIONS,
        );

        usort($list, static fn (array $a, array $b): int => [
            self::FAMILY_ORDER[$a['family']], $a['outputMinLevel'], $a['tier'],
        ] <=> [
            self::FAMILY_ORDER[$b['family']], $b['outputMinLevel'], $b['tier'],
        ]);

        return array_values($list);
    }

    /**
     * Ile razy można wykonać tę konwersję przy danym stanie plecaka.
     *
     * @param  array{inputCount:int}  $conv
     */
    public static function getMaxConversions(array $conv, int $ownedInput): int
    {
        return (int) floor($ownedInput / $conv['inputCount']);
    }

    /**
     * Dostępność konwersji z gatingiem po poziomie (alchemia nie pozwala craftować
     * UP do tieru, którego nie można jeszcze użyć). requiredLevel z potionGating.
     *
     * @param  array{outputId:string, inputCount:int}  $conv
     * @return array{canConvert:bool, maxBatches:int, levelLocked:bool, requiredLevel:int}
     */
    public static function checkConversionAvailability(array $conv, int $ownedInput, int|float $characterLevel = INF): array
    {
        $maxBatches = self::getMaxConversions($conv, $ownedInput);
        $requiredLevel = self::getPotionMinLevel($conv['outputId']);
        $levelLocked = $characterLevel < $requiredLevel;

        return [
            'canConvert' => ! $levelLocked && $maxBatches > 0,
            'maxBatches' => $maxBatches,
            'levelLocked' => $levelLocked,
            'requiredLevel' => $requiredLevel,
        ];
    }
}
