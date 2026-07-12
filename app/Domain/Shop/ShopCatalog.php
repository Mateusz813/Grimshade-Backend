<?php

declare(strict_types=1);

namespace App\Domain\Shop;

final class ShopCatalog
{
    public const ITEM_LEVEL_CAP = 100;

    private const CATEGORY_BASE_MULT = [
        'weapon' => 30,
        'offhand' => 25,
        'armor' => 20,
        'accessory' => 16,
    ];

    private const RARITY_PRICE_MULT = [
        'common' => 1,
        'rare' => 12,
    ];

    private const CLASS_WEAPON_TYPES = [
        'Knight' => ['sword'],
        'Mage' => ['staff'],
        'Cleric' => ['holy_wand'],
        'Archer' => ['bow'],
        'Rogue' => ['dagger'],
        'Necromancer' => ['dead_staff'],
        'Bard' => ['harp'],
    ];

    private const CLASS_OFFHAND_TYPES = [
        'Knight' => ['shield'],
        'Mage' => ['spellbook'],
        'Cleric' => ['holy_cross'],
        'Archer' => ['quiver'],
        'Rogue' => ['dagger'],
        'Necromancer' => ['voodoo_doll'],
        'Bard' => ['talisman'],
    ];

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

    public function __construct(private readonly array $templates) {}

    public static function calculateShopPrice(int $level, string $rarity, string $category): int
    {
        $base = (self::CATEGORY_BASE_MULT[$category] ?? 10) * $level + 20;

        return (int) floor($base * (self::RARITY_PRICE_MULT[$rarity] ?? 1));
    }

    public function generate(string $characterClass, int $level): array
    {
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
