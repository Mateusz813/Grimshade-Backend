<?php

declare(strict_types=1);

namespace App\Domain\Loot;

use App\Domain\Items\ItemEconomy;
use App\Domain\Support\Rng\RngInterface;

final class ItemGenerator
{
    private const BONUS_STAT_POOL = ['hp', 'mp', 'attack', 'defense', 'speed', 'critChance', 'critDmg'];

    private const BONUS_STAT_RANGES = [
        'common' => ['min' => 1, 'max' => 5],
        'rare' => ['min' => 3, 'max' => 12],
        'epic' => ['min' => 5, 'max' => 18],
        'legendary' => ['min' => 10, 'max' => 35],
        'mythic' => ['min' => 20, 'max' => 60],
        'heroic' => ['min' => 40, 'max' => 100],
    ];

    private const STAT_RANGE_MULTIPLIER = [
        'hp' => 1.0, 'mp' => 1.0, 'attack' => 1.0, 'defense' => 1.0, 'speed' => 1.0,
        'critChance' => 0.3, 'critDmg' => 1.5,
    ];

    private const ARMOR_SLOT_BASE_STAT = [
        'helmet' => 'hp', 'armor' => 'hp', 'pants' => 'hp', 'shoulders' => 'hp',
        'boots' => 'hp', 'gloves' => 'attack',
    ];

    private const ARMOR_HP_MULTIPLIER = 6;

    private const ACCESSORY_SLOT_BASE_STAT = [
        'ring1' => 'attack', 'ring2' => 'attack', 'necklace' => 'defense', 'earrings' => 'defense',
    ];

    private const ITEM_CATEGORY_WEIGHTS = [
        'weapon' => 0.20, 'offhand' => 0.15, 'armor' => 0.45, 'accessory' => 0.20,
    ];

    private const ARMOR_SLOTS = ['helmet', 'armor', 'pants', 'boots', 'shoulders', 'gloves'];

    private const CLASSES = ['Knight', 'Mage', 'Cleric', 'Archer', 'Rogue', 'Necromancer', 'Bard'];

    public function __construct(
        private readonly array $templates,
        private readonly RngInterface $rng,
    ) {}

    private function randInt(int|float $min, int|float $max): int
    {
        return (int) ($min + floor($this->rng->nextFloat() * ($max - $min + 1)));
    }

    private function uuidSuffix(string $itemId): string
    {
        return $itemId.'_srv_'.dechex((int) floor($this->rng->nextFloat() * 0xFFFFFFF));
    }

    private function rarityStatMultiplier(string $rarity): float
    {
        return (float) ($this->templates['rarityMultipliers'][$rarity]['statMultiplier'] ?? 1.0);
    }

    private function calculateBaseStat(array $scaling, int $level, string $rarity): int
    {
        $mult = $this->rarityStatMultiplier($rarity);
        $baseValue = $this->randInt($scaling['baseMin'], $scaling['baseMax']);
        $levelBonus = (int) floor($level * $scaling['perLevel']);

        return (int) max(1, floor(($baseValue + $levelBonus) * $mult));
    }

    private function getWeaponBaseDamage(array $scaling, int $level, string $rarity): array
    {
        $mult = $this->rarityStatMultiplier($rarity);
        $levelBonus = $level * $scaling['perLevel'];
        $min = (int) max(1, floor(($scaling['baseMin'] + $levelBonus) * $mult));
        $max = (int) max($min + 1, floor(($scaling['baseMax'] + $levelBonus * 1.15) * $mult));

        return ['min' => $min, 'max' => $max];
    }

    private function generateBonusStats(string $rarity, array $excludeStats = []): array
    {
        $numBonuses = ItemEconomy::RARITY_BONUS_SLOTS[$rarity] ?? 0;
        if ($numBonuses === 0) {
            return [];
        }

        $range = self::BONUS_STAT_RANGES[$rarity];
        $pool = array_values(array_filter(self::BONUS_STAT_POOL, fn ($s) => ! in_array($s, $excludeStats, true)));
        $selected = array_slice($this->rng->shuffle($pool), 0, $numBonuses);

        $bonuses = [];
        foreach ($selected as $stat) {
            $mult = self::STAT_RANGE_MULTIPLIER[$stat] ?? 1.0;
            $bonuses[$stat] = (int) max(1, floor($this->randInt($range['min'], $range['max']) * $mult + 0.5));
        }

        return $bonuses;
    }

    public static function getBaseStatKeysForSlot(?string $slot): array
    {
        return match ($slot) {
            'mainHand', 'offHand' => ['dmg_min', 'dmg_max', 'attack', 'defense'],
            'helmet', 'armor', 'pants', 'shoulders', 'boots' => ['hp'],
            'gloves' => ['attack'],
            'ring1', 'ring2' => ['attack'],
            'necklace', 'earrings' => ['defense'],
            default => [],
        };
    }

    public function rerollItemBonuses(array $item, ?string $slot): array
    {
        $bonuses = $item['bonuses'] ?? [];
        if ($slot === null) {
            return $bonuses;
        }

        $baseKeys = self::getBaseStatKeysForSlot($slot);

        $baseStats = [];
        foreach ($baseKeys as $key) {
            if (array_key_exists($key, $bonuses)) {
                $baseStats[$key] = $bonuses[$key];
            }
        }

        $newRandomBonuses = $this->generateBonusStats((string) $item['rarity'], $baseKeys);

        return [...$baseStats, ...$newRandomBonuses];
    }

    public function generateWeapon(string $weaponType, int $level, string $rarity): ?array
    {
        $template = collect($this->templates['weapons'])->firstWhere('type', $weaponType);
        if ($template === null) {
            return null;
        }

        $dmg = $this->getWeaponBaseDamage($template['scaling'], $level, $rarity);
        $bonuses = $this->generateBonusStats($rarity, ['attack']);
        $bonuses['dmg_min'] = $dmg['min'];
        $bonuses['dmg_max'] = $dmg['max'];

        $itemId = "{$weaponType}_lvl{$level}_{$rarity}";

        return [
            'uuid' => $this->uuidSuffix($itemId),
            'itemId' => $itemId,
            'rarity' => $rarity,
            'bonuses' => $bonuses,
            'itemLevel' => $level,
            'upgradeLevel' => 0,
        ];
    }

    public function generateOffhand(string $offhandType, int $level, string $rarity): ?array
    {
        $template = collect($this->templates['offhands'])->firstWhere('type', $offhandType);
        if ($template === null) {
            return null;
        }

        $isRogueDualWield = $template['type'] === 'dagger'
            || ($template['slot'] === 'offHand'
                && ($template['baseStatType'] ?? '') === 'attack'
                && in_array('Rogue', $template['allowedClasses'] ?? [], true));

        $itemId = "{$offhandType}_lvl{$level}_{$rarity}";

        if ($isRogueDualWield) {
            $dmg = $this->getWeaponBaseDamage($template['scaling'], $level, $rarity);
            $bonuses = $this->generateBonusStats($rarity, ['attack']);
            $bonuses['dmg_min'] = $dmg['min'];
            $bonuses['dmg_max'] = $dmg['max'];

            return [
                'uuid' => $this->uuidSuffix($itemId), 'itemId' => $itemId, 'rarity' => $rarity,
                'bonuses' => $bonuses, 'itemLevel' => $level, 'upgradeLevel' => 0,
            ];
        }

        $baseStat = $this->calculateBaseStat($template['scaling'], $level, $rarity);
        $baseKey = ($template['baseStatType'] ?? '') === 'defense' ? 'defense' : 'attack';
        $bonuses = $this->generateBonusStats($rarity, [$baseKey]);
        $bonuses[$baseKey] = ($bonuses[$baseKey] ?? 0) + $baseStat;

        return [
            'uuid' => $this->uuidSuffix($itemId), 'itemId' => $itemId, 'rarity' => $rarity,
            'bonuses' => $bonuses, 'itemLevel' => $level, 'upgradeLevel' => 0,
        ];
    }

    public function generateArmor(string $armorPrefix, string $slot, int $level, string $rarity): ?array
    {
        $category = $this->templates['armor'][$armorPrefix] ?? null;
        if ($category === null) {
            return null;
        }
        $piece = collect($category['pieces'])->firstWhere('slot', $slot);
        if ($piece === null) {
            return null;
        }

        $rawBase = $this->calculateBaseStat($piece['scaling'], $level, $rarity);
        $baseStatKey = self::ARMOR_SLOT_BASE_STAT[$slot] ?? 'hp';
        $bonuses = $this->generateBonusStats($rarity, [$baseStatKey]);

        if ($baseStatKey === 'hp') {
            $bonuses['hp'] = ($bonuses['hp'] ?? 0) + $rawBase * self::ARMOR_HP_MULTIPLIER;
        } else {
            $bonuses['attack'] = ($bonuses['attack'] ?? 0) + $rawBase;
        }

        $itemId = "{$armorPrefix}_{$slot}_lvl{$level}_{$rarity}";

        return [
            'uuid' => $this->uuidSuffix($itemId), 'itemId' => $itemId, 'rarity' => $rarity,
            'bonuses' => $bonuses, 'itemLevel' => $level, 'upgradeLevel' => 0,
        ];
    }

    public function generateAccessory(string $accessoryType, int $level, string $rarity): ?array
    {
        $template = collect($this->templates['accessories'])->firstWhere('type', $accessoryType);
        if ($template === null) {
            return null;
        }

        $baseStat = $this->calculateBaseStat($template['scaling'], $level, $rarity);
        $slotKey = $template['type'] === 'ring' ? 'ring1' : $template['slot'];
        $baseStatKey = self::ACCESSORY_SLOT_BASE_STAT[$slotKey] ?? 'defense';

        $bonuses = $this->generateBonusStats($rarity, [$baseStatKey]);
        $bonuses[$baseStatKey] = ($bonuses[$baseStatKey] ?? 0) + $baseStat;

        $itemId = "{$accessoryType}_lvl{$level}_{$rarity}";

        return [
            'uuid' => $this->uuidSuffix($itemId), 'itemId' => $itemId, 'rarity' => $rarity,
            'bonuses' => $bonuses, 'itemLevel' => $level, 'upgradeLevel' => 0,
        ];
    }

    public function generateRandomItemForClass(string $characterClass, int $level, string $rarity): ?array
    {
        $roll = $this->rng->nextFloat();
        $cumulative = 0.0;
        $category = 'armor';
        foreach (self::ITEM_CATEGORY_WEIGHTS as $cat => $weight) {
            $cumulative += $weight;
            if ($roll < $cumulative) {
                $category = $cat;
                break;
            }
        }

        switch ($category) {
            case 'weapon':
                $t = collect($this->templates['weapons'])->first(fn ($w) => in_array($characterClass, $w['allowedClasses'], true));

                return $t === null ? null : $this->generateWeapon($t['type'], $level, $rarity);
            case 'offhand':
                $t = collect($this->templates['offhands'])->first(fn ($o) => in_array($characterClass, $o['allowedClasses'], true));

                return $t === null ? null : $this->generateOffhand($t['type'], $level, $rarity);
            case 'armor':
                $prefix = null;
                foreach ($this->templates['armor'] as $p => $cat) {
                    if (in_array($characterClass, $cat['allowedClasses'], true)) {
                        $prefix = $p;
                        break;
                    }
                }
                if ($prefix === null) {
                    return null;
                }
                $slot = self::ARMOR_SLOTS[(int) floor($this->rng->nextFloat() * count(self::ARMOR_SLOTS))];

                return $this->generateArmor($prefix, $slot, $level, $rarity);
            case 'accessory':
                $types = ['ring', 'necklace', 'earrings'];
                $type = $types[(int) floor($this->rng->nextFloat() * count($types))];

                return $this->generateAccessory($type, $level, $rarity);
        }

        return null;
    }

    public function generateRandomItem(int $level, string $rarity): ?array
    {
        $class = self::CLASSES[(int) floor($this->rng->nextFloat() * count(self::CLASSES))];

        return $this->generateRandomItemForClass($class, $level, $rarity);
    }

    public function generateStarterWeapon(string $characterClass): ?array
    {
        $starter = $this->templates['starterWeapons'][$characterClass] ?? null;
        if ($starter === null) {
            return null;
        }

        $itemId = 'starter_'.$starter['type'];
        $dmgMin = (int) max(1, floor($starter['baseAtk'] * 0.8));
        $dmgMax = (int) max($dmgMin + 1, floor($starter['baseAtk'] * 1.2));

        return [
            'uuid' => $this->uuidSuffix($itemId), 'itemId' => $itemId, 'rarity' => 'common',
            'bonuses' => ['dmg_min' => $dmgMin, 'dmg_max' => $dmgMax], 'itemLevel' => 1, 'upgradeLevel' => 0,
        ];
    }

    public function getItemDisplayInfo(string $itemId): ?array
    {
        $parts = explode('_lvl', $itemId);
        if (count($parts) < 2) {
            return self::legacyItemInfo($itemId);
        }
        $typePart = $parts[0];

        foreach ($this->templates['weapons'] as $w) {
            if ($w['type'] === $typePart) {
                return ['name_pl' => $w['name_pl'], 'name_en' => $w['name_en'], 'type' => $w['type'], 'slot' => $w['slot']];
            }
        }
        foreach ($this->templates['offhands'] as $o) {
            if ($o['type'] === $typePart) {
                return ['name_pl' => $o['name_pl'], 'name_en' => $o['name_en'], 'type' => $o['type'], 'slot' => $o['slot']];
            }
        }
        foreach ($this->templates['armor'] as $prefix => $category) {
            foreach ($category['pieces'] as $piece) {
                if ($typePart === "{$prefix}_{$piece['slot']}") {
                    return [
                        'name_pl' => "{$category['prefix_pl']} {$piece['name_pl']}",
                        'name_en' => "{$category['prefix_en']} {$piece['name_en']}",
                        'type' => "{$prefix}_{$piece['slot']}",
                        'slot' => $piece['slot'],
                    ];
                }
            }
        }
        foreach ($this->templates['accessories'] as $a) {
            if ($a['type'] === $typePart) {
                return ['name_pl' => $a['name_pl'], 'name_en' => $a['name_en'], 'type' => $a['type'], 'slot' => $a['slot']];
            }
        }

        return null;
    }

    private static function legacyItemInfo(string $itemId): ?array
    {
        $map = [
            'sword_of_beginnings' => ['name_pl' => 'Miecz Poczatku', 'name_en' => 'Sword of Beginnings', 'type' => 'sword', 'slot' => 'mainHand'],
            'apprentice_staff' => ['name_pl' => 'Kostur Ucznia', 'name_en' => 'Apprentice Staff', 'type' => 'staff', 'slot' => 'mainHand'],
            'wooden_mace' => ['name_pl' => 'Drewniana Bulawa', 'name_en' => 'Wooden Mace', 'type' => 'holy_wand', 'slot' => 'mainHand'],
            'short_bow' => ['name_pl' => 'Krotki Luk', 'name_en' => 'Short Bow', 'type' => 'bow', 'slot' => 'mainHand'],
            'rusty_dagger' => ['name_pl' => 'Zardzewialy Sztylet', 'name_en' => 'Rusty Dagger', 'type' => 'dagger', 'slot' => 'mainHand'],
            'bone_staff' => ['name_pl' => 'Kostur Kosciany', 'name_en' => 'Bone Staff', 'type' => 'dead_staff', 'slot' => 'mainHand'],
            'lute' => ['name_pl' => 'Lutnia', 'name_en' => 'Lute', 'type' => 'harp', 'slot' => 'mainHand'],
        ];

        return $map[$itemId] ?? null;
    }
}
