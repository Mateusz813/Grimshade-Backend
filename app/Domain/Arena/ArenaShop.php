<?php

declare(strict_types=1);

namespace App\Domain\Arena;

final class ArenaShop
{
    private const ARENA_STONES = [
        ['id' => 'arena_stone_common',    'name_pl' => 'Kamień (Common)',    'description_pl' => 'Kamień ulepszenia common.',    'icon' => 'white-circle',  'apPrice' => 50,    'kind' => 'stone', 'payloadId' => 'common_stone'],
        ['id' => 'arena_stone_rare',      'name_pl' => 'Kamień (Rare)',      'description_pl' => 'Kamień ulepszenia rare.',      'icon' => 'blue-circle',   'apPrice' => 200,   'kind' => 'stone', 'payloadId' => 'rare_stone'],
        ['id' => 'arena_stone_epic',      'name_pl' => 'Kamień (Epic)',      'description_pl' => 'Kamień ulepszenia epic.',      'icon' => 'purple-circle', 'apPrice' => 800,   'kind' => 'stone', 'payloadId' => 'epic_stone'],
        ['id' => 'arena_stone_legendary', 'name_pl' => 'Kamień (Legendary)', 'description_pl' => 'Kamień ulepszenia legendary.', 'icon' => 'yellow-circle', 'apPrice' => 3000,  'kind' => 'stone', 'payloadId' => 'legendary_stone'],
        ['id' => 'arena_stone_mythic',    'name_pl' => 'Kamień (Mythic)',    'description_pl' => 'Kamień ulepszenia mythic.',    'icon' => 'gem-stone',     'apPrice' => 6000,  'kind' => 'stone', 'payloadId' => 'mythic_stone'],
        ['id' => 'arena_stone_heroic',    'name_pl' => 'Kamień (Heroic)',    'description_pl' => 'Bardzo rzadki kamień heroic.', 'icon' => 'red-circle',    'apPrice' => 12000, 'kind' => 'stone', 'payloadId' => 'heroic_stone'],
    ];

    private const ARENA_POTIONS = [
        ['id' => 'arena_hp_25',  'name_pl' => 'Potion HP 25%',  'description_pl' => 'Przywraca 25% maks. HP.',  'icon' => 'red-heart', 'apPrice' => 300,  'kind' => 'potion', 'payloadId' => 'hp_potion_great'],
        ['id' => 'arena_hp_50',  'name_pl' => 'Potion HP 50%',  'description_pl' => 'Przywraca 50% maks. HP.',  'icon' => 'red-heart', 'apPrice' => 800,  'kind' => 'potion', 'payloadId' => 'hp_potion_ultimate'],
        ['id' => 'arena_hp_100', 'name_pl' => 'Potion HP 100%', 'description_pl' => 'Przywraca 100% maks. HP.', 'icon' => 'red-heart', 'apPrice' => 2000, 'kind' => 'potion', 'payloadId' => 'hp_potion_divine'],
        ['id' => 'arena_mp_25',  'name_pl' => 'Potion MP 25%',  'description_pl' => 'Przywraca 25% maks. MP.',  'icon' => 'droplet',   'apPrice' => 300,  'kind' => 'potion', 'payloadId' => 'mp_potion_great'],
        ['id' => 'arena_mp_50',  'name_pl' => 'Potion MP 50%',  'description_pl' => 'Przywraca 50% maks. MP.',  'icon' => 'droplet',   'apPrice' => 800,  'kind' => 'potion', 'payloadId' => 'mp_potion_ultimate'],
        ['id' => 'arena_mp_100', 'name_pl' => 'Potion MP 100%', 'description_pl' => 'Przywraca 100% maks. MP.', 'icon' => 'droplet',   'apPrice' => 2000, 'kind' => 'potion', 'payloadId' => 'mp_potion_divine'],
    ];

    private const ARENA_MYTHIC = [
        ['id' => 'arena_mythic_main',    'name_pl' => 'Mityczna Broń (Główna)',  'description_pl' => 'Bron mityczna na Twoim poziomie. Cena = poziom × 1000 AP.',         'icon' => 'crossed-swords', 'apPrice' => 1000, 'kind' => 'mythic_weapon',  'perLevel' => true],
        ['id' => 'arena_mythic_offhand', 'name_pl' => 'Mityczna Broń (Offhand)', 'description_pl' => 'Bron mityczna offhand na Twoim poziomie. Cena = poziom × 1000 AP.', 'icon' => 'dagger',         'apPrice' => 1000, 'kind' => 'mythic_offhand', 'perLevel' => true],
    ];

    private const POTION_TIER_MIN_LEVEL = [
        'sm' => 1, 'md' => 20, 'lg' => 50, 'mega' => 100,
        'great' => 200, 'super' => 350, 'ultimate' => 500, 'divine' => 700,
    ];

    private const CLASS_WEAPON_TYPES = [
        'Knight' => 'sword', 'Mage' => 'staff', 'Cleric' => 'holy_wand', 'Archer' => 'bow',
        'Rogue' => 'dagger', 'Necromancer' => 'dead_staff', 'Bard' => 'harp',
    ];

    private const CLASS_OFFHAND_TYPES = [
        'Knight' => 'shield', 'Mage' => 'spellbook', 'Cleric' => 'holy_cross', 'Archer' => 'quiver',
        'Rogue' => 'dagger', 'Necromancer' => 'voodoo_doll', 'Bard' => 'talisman',
    ];

    public const MYTHIC_LEVEL_CAP = 1000;

    public static function catalog(array $shopElixirs): array
    {
        return [
            ...self::ARENA_MYTHIC,
            ...self::ARENA_STONES,
            ...self::ARENA_POTIONS,
            ...self::elixirItems($shopElixirs),
        ];
    }

    public static function findItem(string $itemId, array $shopElixirs): ?array
    {
        foreach (self::catalog($shopElixirs) as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        return null;
    }

    public static function apPrice(array $item, int $level): int
    {
        $apPrice = (int) $item['apPrice'];
        if (($item['perLevel'] ?? false) === true) {
            $lvl = max(1, min(self::MYTHIC_LEVEL_CAP, $level));

            return $apPrice * $lvl;
        }

        return $apPrice;
    }

    public static function getPotionMinLevel(string $id): int
    {
        if (! str_starts_with($id, 'hp_potion_') && ! str_starts_with($id, 'mp_potion_')) {
            return 1;
        }
        $tier = substr($id, strrpos($id, '_') + 1);

        return self::POTION_TIER_MIN_LEVEL[$tier] ?? 1;
    }

    public static function weaponTypeForClass(string $class, string $fallbackType): string
    {
        return self::CLASS_WEAPON_TYPES[$class] ?? $fallbackType;
    }

    public static function offhandTypeForClass(string $class, string $fallbackType): string
    {
        return self::CLASS_OFFHAND_TYPES[$class] ?? $fallbackType;
    }

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
