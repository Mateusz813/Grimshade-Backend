<?php

declare(strict_types=1);

namespace App\Domain\Arena;

/**
 * Port czystego katalogu sklepu areny: src/stores/shopStore.ts
 * getArenaShopCatalog (~line 555) + reguł cen z buyArenaItem (~line 584).
 *
 * Wszystko kupowane jest za arena points (AP), nie za gold. Cztery kubełki:
 *   - stones   — common..heroic kamienie ulepszeń (payload `<rarity>_stone`),
 *   - potions  — % HP/MP leczenie (payload realnej poteki: great/ultimate/divine),
 *   - mythic   — broń główna + offhand, apPrice 1000, perLevel (× clamp(lvl,1,1000)),
 *   - elixirs  — każdy NIE-HP/MP eliksir za AP = max(50, floor(price/10)).
 *
 * Czysty (bez Eloquent/RNG/now()) — ceny/gating/typy liczone deterministycznie.
 * Typ broni mitycznej rozstrzyga klasa postaci (CLASS_WEAPON_TYPES /
 * CLASS_OFFHAND_TYPES) — jak w TS; fallback = pierwszy szablon.
 */
final class ArenaShop
{
    /** @var list<array{id:string, name_pl:string, description_pl:string, icon:string, apPrice:int, kind:string, payloadId:string}> */
    private const ARENA_STONES = [
        ['id' => 'arena_stone_common',    'name_pl' => 'Kamień (Common)',    'description_pl' => 'Kamień ulepszenia common.',    'icon' => 'white-circle',  'apPrice' => 50,    'kind' => 'stone', 'payloadId' => 'common_stone'],
        ['id' => 'arena_stone_rare',      'name_pl' => 'Kamień (Rare)',      'description_pl' => 'Kamień ulepszenia rare.',      'icon' => 'blue-circle',   'apPrice' => 200,   'kind' => 'stone', 'payloadId' => 'rare_stone'],
        ['id' => 'arena_stone_epic',      'name_pl' => 'Kamień (Epic)',      'description_pl' => 'Kamień ulepszenia epic.',      'icon' => 'purple-circle', 'apPrice' => 800,   'kind' => 'stone', 'payloadId' => 'epic_stone'],
        ['id' => 'arena_stone_legendary', 'name_pl' => 'Kamień (Legendary)', 'description_pl' => 'Kamień ulepszenia legendary.', 'icon' => 'yellow-circle', 'apPrice' => 3000,  'kind' => 'stone', 'payloadId' => 'legendary_stone'],
        ['id' => 'arena_stone_mythic',    'name_pl' => 'Kamień (Mythic)',    'description_pl' => 'Kamień ulepszenia mythic.',    'icon' => 'gem-stone',     'apPrice' => 6000,  'kind' => 'stone', 'payloadId' => 'mythic_stone'],
        ['id' => 'arena_stone_heroic',    'name_pl' => 'Kamień (Heroic)',    'description_pl' => 'Bardzo rzadki kamień heroic.', 'icon' => 'red-circle',    'apPrice' => 12000, 'kind' => 'stone', 'payloadId' => 'heroic_stone'],
    ];

    /** @var list<array{id:string, name_pl:string, description_pl:string, icon:string, apPrice:int, kind:string, payloadId:string}> */
    private const ARENA_POTIONS = [
        ['id' => 'arena_hp_25',  'name_pl' => 'Potion HP 25%',  'description_pl' => 'Przywraca 25% maks. HP.',  'icon' => 'red-heart', 'apPrice' => 300,  'kind' => 'potion', 'payloadId' => 'hp_potion_great'],
        ['id' => 'arena_hp_50',  'name_pl' => 'Potion HP 50%',  'description_pl' => 'Przywraca 50% maks. HP.',  'icon' => 'red-heart', 'apPrice' => 800,  'kind' => 'potion', 'payloadId' => 'hp_potion_ultimate'],
        ['id' => 'arena_hp_100', 'name_pl' => 'Potion HP 100%', 'description_pl' => 'Przywraca 100% maks. HP.', 'icon' => 'red-heart', 'apPrice' => 2000, 'kind' => 'potion', 'payloadId' => 'hp_potion_divine'],
        ['id' => 'arena_mp_25',  'name_pl' => 'Potion MP 25%',  'description_pl' => 'Przywraca 25% maks. MP.',  'icon' => 'droplet',   'apPrice' => 300,  'kind' => 'potion', 'payloadId' => 'mp_potion_great'],
        ['id' => 'arena_mp_50',  'name_pl' => 'Potion MP 50%',  'description_pl' => 'Przywraca 50% maks. MP.',  'icon' => 'droplet',   'apPrice' => 800,  'kind' => 'potion', 'payloadId' => 'mp_potion_ultimate'],
        ['id' => 'arena_mp_100', 'name_pl' => 'Potion MP 100%', 'description_pl' => 'Przywraca 100% maks. MP.', 'icon' => 'droplet',   'apPrice' => 2000, 'kind' => 'potion', 'payloadId' => 'mp_potion_divine'],
    ];

    /** @var list<array{id:string, name_pl:string, description_pl:string, icon:string, apPrice:int, kind:string, perLevel:bool}> */
    private const ARENA_MYTHIC = [
        ['id' => 'arena_mythic_main',    'name_pl' => 'Mityczna Broń (Główna)',  'description_pl' => 'Bron mityczna na Twoim poziomie. Cena = poziom × 1000 AP.',         'icon' => 'crossed-swords', 'apPrice' => 1000, 'kind' => 'mythic_weapon',  'perLevel' => true],
        ['id' => 'arena_mythic_offhand', 'name_pl' => 'Mityczna Broń (Offhand)', 'description_pl' => 'Bron mityczna offhand na Twoim poziomie. Cena = poziom × 1000 AP.', 'icon' => 'dagger',         'apPrice' => 1000, 'kind' => 'mythic_offhand', 'perLevel' => true],
    ];

    /**
     * Poziom odblokowania per sufiks-tier poteki (potionGating.ts). Poteki
     * areny są bramkowane poziomem REALNEJ poteki, którą wypłacają (payloadId).
     *
     * @var array<string, int>
     */
    private const POTION_TIER_MIN_LEVEL = [
        'sm' => 1, 'md' => 20, 'lg' => 50, 'mega' => 100,
        'great' => 200, 'super' => 350, 'ultimate' => 500, 'divine' => 700,
    ];

    /** @var array<string, string> itemSystem.ts CLASS_WEAPON_TYPES (typ broni głównej per klasa). */
    private const CLASS_WEAPON_TYPES = [
        'Knight' => 'sword', 'Mage' => 'staff', 'Cleric' => 'holy_wand', 'Archer' => 'bow',
        'Rogue' => 'dagger', 'Necromancer' => 'dead_staff', 'Bard' => 'harp',
    ];

    /** @var array<string, string> itemSystem.ts CLASS_OFFHAND_TYPES (typ offhandu per klasa). */
    private const CLASS_OFFHAND_TYPES = [
        'Knight' => 'shield', 'Mage' => 'spellbook', 'Cleric' => 'holy_cross', 'Archer' => 'quiver',
        'Rogue' => 'dagger', 'Necromancer' => 'voodoo_doll', 'Bard' => 'talisman',
    ];

    /** Cap poziomu ceny broni mitycznej (buyArenaItem: clamp(level, 1, 1000)). */
    public const MYTHIC_LEVEL_CAP = 1000;

    /**
     * Pełny katalog areny + dynamiczne eliksiry za AP (kolejność jak w TS:
     * mythic, stones, potions, elixirs).
     *
     * @param  list<array<string, mixed>>  $shopElixirs  wpisy shop.json ['elixirs']
     * @return list<array<string, mixed>>
     */
    public static function catalog(array $shopElixirs): array
    {
        return [
            ...self::ARENA_MYTHIC,
            ...self::ARENA_STONES,
            ...self::ARENA_POTIONS,
            ...self::elixirItems($shopElixirs),
        ];
    }

    /**
     * Znajdź wpis katalogu po id (włącznie z dynamicznymi eliksirami).
     *
     * @param  list<array<string, mixed>>  $shopElixirs
     * @return array<string, mixed>|null
     */
    public static function findItem(string $itemId, array $shopElixirs): ?array
    {
        foreach (self::catalog($shopElixirs) as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Cena AP wpisu dla postaci na danym poziomie. perLevel => apPrice ×
     * clamp(level, 1, 1000); inaczej apPrice płaskie.
     *
     * @param  array<string, mixed>  $item
     */
    public static function apPrice(array $item, int $level): int
    {
        $apPrice = (int) $item['apPrice'];
        if (($item['perLevel'] ?? false) === true) {
            $lvl = max(1, min(self::MYTHIC_LEVEL_CAP, $level));

            return $apPrice * $lvl;
        }

        return $apPrice;
    }

    /**
     * Minimalny poziom postaci, żeby kupić potekę o danym id (payloadId).
     * Nie-poteki (i nieznane tiery) → 1.
     */
    public static function getPotionMinLevel(string $id): int
    {
        if (! str_starts_with($id, 'hp_potion_') && ! str_starts_with($id, 'mp_potion_')) {
            return 1;
        }
        $tier = substr($id, strrpos($id, '_') + 1);

        return self::POTION_TIER_MIN_LEVEL[$tier] ?? 1;
    }

    /** Typ broni głównej dla klasy (fallback = pierwszy szablon weapons). */
    public static function weaponTypeForClass(string $class, string $fallbackType): string
    {
        return self::CLASS_WEAPON_TYPES[$class] ?? $fallbackType;
    }

    /** Typ offhandu dla klasy (fallback = pierwszy szablon offhands). */
    public static function offhandTypeForClass(string $class, string $fallbackType): string
    {
        return self::CLASS_OFFHAND_TYPES[$class] ?? $fallbackType;
    }

    /**
     * Dynamiczne eliksiry AP: filtruj HP/MP poteki, cena = max(50, floor(price/10)).
     *
     * @param  list<array<string, mixed>>  $shopElixirs
     * @return list<array{id:string, apPrice:int, kind:string, payloadId:string}>
     */
    private static function elixirItems(array $shopElixirs): array
    {
        $out = [];
        foreach ($shopElixirs as $e) {
            $id = (string) $e['id'];
            if (str_starts_with($id, 'hp_potion_') || str_starts_with($id, 'mp_potion_')) {
                continue;
            }
            $out[] = [
                'id' => "arena_elixir_{$id}",
                'apPrice' => max(50, (int) floor((int) $e['price'] / 10)),
                'kind' => 'elixir',
                'payloadId' => $id,
            ];
        }

        return $out;
    }
}
