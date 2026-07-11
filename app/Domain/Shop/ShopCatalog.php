<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * Port src/stores/shopStore.ts `generateShopItems` + `calculateShopPrice` —
 * katalog itemowego sklepu (zakładka "Itemy", NIE eliksiry) jest GENEROWANY
 * dynamicznie per klasa+poziom postaci; nie ma go w shop.json.
 *
 * Serwer odtwarza ten sam katalog żeby autorytatywnie ustalić:
 *  - czy dane `itemId` istnieje w sklepie tej klasy (id koduje typ/level/rarity),
 *  - jego CENĘ (calculateShopPrice — bit-w-bit z frontem),
 *  - parametry generacji (templateType + type / prefix+slot) dla ItemGenerator.
 *
 * PARYTET: id/cena liczone 1:1 z shopStore.ts. `previewBonuses` (UI-only) i pola
 * kosmetyczne (name/icon) są POMINIĘTE — serwer ich nie potrzebuje. Item, który
 * gracz dostaje, generuje ItemGenerator dokładnie jak `buyShopItem` na froncie.
 */
final class ShopCatalog
{
    /** Maksymalny poziom itemu jaki sklep kiedykolwiek wygeneruje (SHOP_ITEM_LEVEL_CAP). */
    public const ITEM_LEVEL_CAP = 100;

    /** Ceny bazowe per kategoria (CATEGORY_BASE_MULT z shopStore.ts). */
    private const CATEGORY_BASE_MULT = [
        'weapon' => 30,
        'offhand' => 25,
        'armor' => 20,
        'accessory' => 16,
    ];

    /** Mnożnik ceny per rarity — WŁASNY sklepowy (RARITY_PRICE_MULT), NIE rarityMultipliers. */
    private const RARITY_PRICE_MULT = [
        'common' => 1,
        'rare' => 12,
    ];

    /** @var array<string, list<string>> */
    private const CLASS_WEAPON_TYPES = [
        'Knight' => ['sword'],
        'Mage' => ['staff'],
        'Cleric' => ['holy_wand'],
        'Archer' => ['bow'],
        'Rogue' => ['dagger'],
        'Necromancer' => ['dead_staff'],
        'Bard' => ['harp'],
    ];

    /** @var array<string, list<string>> */
    private const CLASS_OFFHAND_TYPES = [
        'Knight' => ['shield'],
        'Mage' => ['spellbook'],
        'Cleric' => ['holy_cross'],
        'Archer' => ['quiver'],
        'Rogue' => ['dagger'],
        'Necromancer' => ['voodoo_doll'],
        'Bard' => ['talisman'],
    ];

    /** @var array<string, string> */
    private const CLASS_ARMOR_TYPES = [
        'Knight' => 'heavy',
        'Mage' => 'magic',
        'Cleric' => 'magic',
        'Archer' => 'light',
        'Rogue' => 'light',
        'Necromancer' => 'magic',
        'Bard' => 'light',
    ];

    private const RARITIES = ['common', 'rare'];

    /**
     * @param  array<string, mixed>  $templates  zawartość itemTemplates.json
     */
    public function __construct(private readonly array $templates) {}

    /**
     * Cena sklepowa itemu — 1:1 z calculateShopPrice() na froncie.
     */
    public static function calculateShopPrice(int $level, string $rarity, string $category): int
    {
        $base = (self::CATEGORY_BASE_MULT[$category] ?? 10) * $level + 20;

        return (int) floor($base * (self::RARITY_PRICE_MULT[$rarity] ?? 1));
    }

    /**
     * Odtwarza katalog itemów sklepu dla danej klasy+poziomu. Zwraca mapę
     * `id => entry`, gdzie entry zawiera wszystko czego potrzeba do wyceny i
     * generacji: templateType, type / (armorPrefix + slot), level, rarity, price.
     *
     * @return array<string, array{
     *     id: string, price: int, level: int, rarity: string, templateType: string,
     *     type?: string, slot?: string, armorPrefix?: string
     * }>
     */
    public function generate(string $characterClass, int $level): array
    {
        // Cap poziomu itemu — jak na froncie (Math.min(level, SHOP_ITEM_LEVEL_CAP)).
        $level = min($level, self::ITEM_LEVEL_CAP);

        $weapons = $this->templates['weapons'] ?? [];
        $offhands = $this->templates['offhands'] ?? [];
        $armorMap = $this->templates['armor'] ?? [];
        $accessories = $this->templates['accessories'] ?? [];

        $allowedWeaponTypes = self::CLASS_WEAPON_TYPES[$characterClass] ?? [];
        $weapon = $this->firstByType($weapons, $allowedWeaponTypes);

        $allowedOffhandTypes = self::CLASS_OFFHAND_TYPES[$characterClass] ?? [];
        $offhand = $this->firstByType($offhands, $allowedOffhandTypes);

        $armorPrefix = self::CLASS_ARMOR_TYPES[$characterClass] ?? null;
        $armorCategory = $armorPrefix !== null ? ($armorMap[$armorPrefix] ?? null) : null;

        $catalog = [];

        foreach (self::RARITIES as $rarity) {
            // Broń
            if ($weapon !== null) {
                $id = "shop_{$weapon['type']}_{$level}_{$rarity}";
                $catalog[$id] = [
                    'id' => $id,
                    'price' => self::calculateShopPrice($level, $rarity, 'weapon'),
                    'level' => $level,
                    'rarity' => $rarity,
                    'templateType' => 'weapon',
                    'type' => $weapon['type'],
                ];
            }

            // Offhand
            if ($offhand !== null && $allowedOffhandTypes !== []) {
                $id = "shop_{$offhand['type']}_{$level}_{$rarity}";
                $catalog[$id] = [
                    'id' => $id,
                    'price' => self::calculateShopPrice($level, $rarity, 'offhand'),
                    'level' => $level,
                    'rarity' => $rarity,
                    'templateType' => 'offhand',
                    'type' => $offhand['type'],
                ];
            }

            // Pancerz
            if ($armorCategory !== null && $armorPrefix !== null) {
                foreach (($armorCategory['pieces'] ?? []) as $piece) {
                    $slot = $piece['slot'];
                    $id = "shop_{$armorPrefix}_{$slot}_{$level}_{$rarity}";
                    $catalog[$id] = [
                        'id' => $id,
                        'price' => self::calculateShopPrice($level, $rarity, 'armor'),
                        'level' => $level,
                        'rarity' => $rarity,
                        'templateType' => 'armor',
                        'armorPrefix' => $armorPrefix,
                        'slot' => $slot,
                    ];
                }
            }

            // Akcesoria
            foreach ($accessories as $acc) {
                $id = "shop_{$acc['type']}_{$level}_{$rarity}";
                $catalog[$id] = [
                    'id' => $id,
                    'price' => self::calculateShopPrice($level, $rarity, 'accessory'),
                    'level' => $level,
                    'rarity' => $rarity,
                    'templateType' => 'accessory',
                    'type' => $acc['type'],
                ];
            }
        }

        return $catalog;
    }

    /**
     * Pierwszy template, którego `type` jest w dozwolonych typach (find() z TS).
     *
     * @param  array<int, array<string, mixed>>  $templates
     * @param  list<string>  $allowedTypes
     * @return array<string, mixed>|null
     */
    private function firstByType(array $templates, array $allowedTypes): ?array
    {
        foreach ($templates as $t) {
            if (in_array($t['type'], $allowedTypes, true)) {
                return $t;
            }
        }

        return null;
    }
}
