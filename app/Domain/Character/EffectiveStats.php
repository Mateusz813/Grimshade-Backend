<?php

declare(strict_types=1);

namespace App\Domain\Character;

use App\Domain\Combat\CombatElixirs;
use App\Domain\Content\ContentRepository;
use App\Domain\Items\ItemEconomy;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Skills\SkillSystem;
use App\Domain\Transform\TransformBonuses;
use App\Domain\Transform\TransformSystem;

/**
 * Port agregacji `getEffectiveChar` z frontu (src/systems/combatEngine.ts:786-836)
 * + zależnych helperów itemSystem.ts (getTotalEquipmentStats / getItemStats /
 * getGeneratedItemInfo / getEquippedGearLevel / getClassSkillBonus).
 *
 * CEL: serwerowe przeliczenie EFEKTYWNYCH statów postaci z żywego gearu w blobie
 * (equipment + bonuses + upgrade), treningów, transformacji i eliksirów — do
 * WALIDACJI / anty-cheatu (tryb SOFT). To NIE jest ścieżka combatu (klient liczy
 * walkę własnym silnikiem); serwer używa tego wyłącznie jako autorytet-referencję.
 *
 * Czysty (bez Eloquent) — bierze tablice treści jawnie (reguła Domain). Semantyka
 * liczb 1:1 z JS: `Math.floor` → floor(); `Math.round` → jsRound (floor(x+0.5)).
 * eq.speed/critChance/critDmg mnożone ×0.01; eq.hp/mp/attack/defense dodawane raw.
 */
final class EffectiveStats
{
    /** Klucze IItemStats (frontend) — tylko te bonusy sumują się do statów EQ. */
    private const ITEM_STAT_KEYS = ['attack', 'defense', 'hp', 'mp', 'speed', 'critChance', 'critDmg'];

    /**
     * Mnożnik obrażeń klasy (src/systems/combatEngine.ts). NIE wchodzi do
     * getEffectiveChar — używany przy liczeniu obrażeń. Wystawiony dla parytetu.
     *
     * @var array<string, float>
     */
    public const CLASS_MODIFIER = [
        'Knight' => 1.0,
        'Mage' => 1.3,
        'Cleric' => 1.0,
        'Archer' => 1.2,
        'Rogue' => 1.0,
        'Necromancer' => 1.2,
        'Bard' => 1.0,
    ];

    /** @var array<string, array{type:string, slot:string, itemLevel:int|null}|null> memo parsera itemId */
    private array $genInfoCache = [];

    /**
     * @param  array<string, mixed>  $itemTemplates  zawartość itemTemplates.json (weapons/offhands/armor/accessories)
     * @param  list<array<string, mixed>>  $allItems  spłaszczone bazowe itemy (items.json) — legacy path
     */
    public function __construct(
        private readonly array $itemTemplates,
        private readonly array $allItems,
        private readonly TransformBonuses $transformBonuses,
    ) {}

    /** Buduje instancję z żywej treści gry (jedno źródło prawdy z frontem). */
    public static function fromContent(ContentRepository $content): self
    {
        $items = $content->get('items');
        $allItems = [
            ...($items['weapons'] ?? []),
            ...($items['offhands'] ?? []),
            ...($items['armor'] ?? []),
            ...($items['accessories'] ?? []),
        ];

        return new self(
            $content->get('itemTemplates'),
            $allItems,
            new TransformBonuses(new TransformSystem($content->get('transforms'), $content->get('monsters'))),
        );
    }

    // ---- Parser itemId → {type, slot, itemLevel} (port getGeneratedItemInfo) ----

    /**
     * @return array{type:string, slot:string, itemLevel:int|null}|null
     */
    public function getGeneratedItemInfo(string $itemId): ?array
    {
        if (array_key_exists($itemId, $this->genInfoCache)) {
            return $this->genInfoCache[$itemId];
        }

        $parts = explode('_lvl', $itemId);
        $isStarter = str_starts_with($itemId, 'starter_') && count($parts) < 2;
        $typePart = $isStarter
            ? substr($itemId, strlen('starter_'))
            : (count($parts) >= 2 ? $parts[0] : null);

        // Level żyje w ogonie "_lvlN_rarity" (parts[1] === "N_rarity"); parseInt-semantyka.
        $parsedLevel = count($parts) >= 2 ? (int) $parts[1] : 0;
        $itemLevel = $parsedLevel > 0 ? $parsedLevel : null;

        if ($typePart === null || $typePart === '') {
            return $this->genInfoCache[$itemId] = null;
        }

        foreach ($this->itemTemplates['weapons'] ?? [] as $w) {
            if (($w['type'] ?? null) === $typePart) {
                return $this->genInfoCache[$itemId] = ['type' => $w['type'], 'slot' => $w['slot'], 'itemLevel' => $itemLevel];
            }
        }
        foreach ($this->itemTemplates['offhands'] ?? [] as $o) {
            if (($o['type'] ?? null) === $typePart) {
                return $this->genInfoCache[$itemId] = ['type' => $o['type'], 'slot' => $o['slot'], 'itemLevel' => $itemLevel];
            }
        }
        foreach ($this->itemTemplates['armor'] ?? [] as $prefix => $category) {
            foreach ($category['pieces'] ?? [] as $piece) {
                $armorType = "{$prefix}_{$piece['slot']}";
                if ($typePart === $armorType) {
                    return $this->genInfoCache[$itemId] = ['type' => $armorType, 'slot' => $piece['slot'], 'itemLevel' => $itemLevel];
                }
            }
        }
        foreach ($this->itemTemplates['accessories'] ?? [] as $a) {
            if (($a['type'] ?? null) === $typePart) {
                return $this->genInfoCache[$itemId] = ['type' => $a['type'], 'slot' => $a['slot'], 'itemLevel' => $itemLevel];
            }
        }

        return $this->genInfoCache[$itemId] = null;
    }

    // ---- Staty pojedynczego itemu (port getItemStats + generated-branch) --------

    /**
     * Staty jednego założonego itemu. Legacy (baseData != null) skaluje baseAtk/baseDef
     * przez upgrade; bonusy losowe płaskie. Generated (baseData == null) iteruje bonusy:
     * bazowy stat slotu skalowany przez upgrade, reszta płaska; klucze spoza IItemStats
     * (dmg_min/dmg_max) pomijane.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $baseData
     * @return array{attack:int|float, defense:int|float, hp:int|float, mp:int|float, speed:int|float, critChance:int|float, critDmg:int|float}
     */
    public function getItemStats(array $item, ?array $baseData): array
    {
        $upgradeLevel = (int) ($item['upgradeLevel'] ?? 0);
        $stats = ['attack' => 0, 'defense' => 0, 'hp' => 0, 'mp' => 0, 'speed' => 0, 'critChance' => 0, 'critDmg' => 0];

        if ($baseData !== null) {
            // Legacy: baseAtk/baseDef to bazowy stat slotu → skalują się z upgrade.
            $stats['attack'] = ItemEconomy::getUpgradedBaseStat(self::num($baseData['baseAtk'] ?? 0), $upgradeLevel);
            $stats['defense'] = ItemEconomy::getUpgradedBaseStat(self::num($baseData['baseDef'] ?? 0), $upgradeLevel);

            // Bonusy losowe legacy NIE są skalowane przez upgrade.
            foreach ((array) ($item['bonuses'] ?? []) as $key => $val) {
                if (array_key_exists($key, $stats)) {
                    $stats[$key] += $val;
                }
            }

            return $stats;
        }

        // Generated: staty tylko z bonusów. Upgrade skaluje WYŁĄCZNIE bazowy stat slotu.
        $slot = $this->getGeneratedItemInfo((string) ($item['itemId'] ?? ''))['slot'] ?? null;
        foreach ((array) ($item['bonuses'] ?? []) as $key => $val) {
            if (! array_key_exists($key, $stats)) {
                continue; // pomija dmg_min/dmg_max i inne nie-IItemStats
            }
            $stats[$key] += $this->isBaseStatKey($slot, (string) $key)
                ? ItemEconomy::getUpgradedBaseStat($val, $upgradeLevel)
                : $val;
        }

        return $stats;
    }

    // ---- Suma statów całego equipmentu (port getTotalEquipmentStats) ------------

    /**
     * @param  array<string, mixed>  $equipment  mapa slot => item|null (12 slotów)
     * @return array{attack:int|float, defense:int|float, hp:int|float, mp:int|float, speed:int|float, critChance:int|float, critDmg:int|float}
     */
    public function getTotalEquipmentStats(array $equipment): array
    {
        $total = ['attack' => 0, 'defense' => 0, 'hp' => 0, 'mp' => 0, 'speed' => 0, 'critChance' => 0, 'critDmg' => 0];

        foreach ($equipment as $item) {
            if (! is_array($item) || $item === []) {
                continue;
            }
            $base = $this->findBaseItem((string) ($item['itemId'] ?? ''));
            $stats = $this->getItemStats($item, $base);
            foreach ($total as $key => $_) {
                $total[$key] += $stats[$key];
            }
        }

        return $total;
    }

    /** Średni poziom założonych GENEROWANYCH itemów (round). Brak → 1. */
    public function getEquippedGearLevel(array $equipment): int
    {
        $levels = [];
        foreach ($equipment as $item) {
            if (! is_array($item) || $item === []) {
                continue;
            }
            $info = $this->getGeneratedItemInfo((string) ($item['itemId'] ?? ''));
            if ($info !== null && ! empty($info['itemLevel'])) {
                $levels[] = $info['itemLevel'];
            }
        }

        return $levels === [] ? 1 : self::jsRound(array_sum($levels) / count($levels));
    }

    // ---- Bonus klasowy (port getClassSkillBonus) -------------------------------

    /**
     * @param  array<string, int>  $skillLevels
     * @return array{skillBonus:int, extraCritChance:float}
     */
    public static function getClassSkillBonus(string $characterClass, array $skillLevels): array
    {
        $skillBonus = 0;
        $extraCritChance = 0.0;

        switch ($characterClass) {
            case 'Knight':
                $skillBonus = (int) floor(($skillLevels['sword_fighting'] ?? 0) * 0.5);
                break;
            case 'Mage':
            case 'Necromancer':
                $skillBonus = (int) floor(($skillLevels['magic_level'] ?? 0) * 0.8);
                break;
            case 'Cleric':
                $skillBonus = (int) floor(($skillLevels['magic_level'] ?? 0) * 0.6);
                break;
            case 'Archer':
                $dist = $skillLevels['distance_fighting'] ?? 0;
                $skillBonus = (int) floor($dist * 0.4);
                $extraCritChance = $dist * 0.003;
                break;
            case 'Rogue':
                $dag = $skillLevels['dagger_fighting'] ?? 0;
                $skillBonus = (int) floor($dag * 0.3);
                $extraCritChance = $dag * 0.005;
                break;
            case 'Bard':
                $skillBonus = (int) floor(($skillLevels['bard_level'] ?? 0) * 0.5);
                break;
        }

        return ['skillBonus' => $skillBonus, 'extraCritChance' => $extraCritChance];
    }

    public static function getClassModifier(string $characterClass): float
    {
        return self::CLASS_MODIFIER[$characterClass] ?? 1.0;
    }

    // ---- Agregacja efektywnych statów (port getEffectiveChar) -------------------

    /**
     * Efektywne staty postaci: baza (kolumny characters) + equipment + trening +
     * transformacje + eliksiry, w DOKŁADNEJ kolejności combatEngine.ts:791-835.
     *
     * @param  array<string, mixed>  $baseRow  surowe pola postaci (attack, defense, max_hp, ...)
     * @param  array<string, mixed>  $equipment  mapa slot => item
     * @param  array<string, int>  $skillLevels
     * @param  list<int>  $completedTransforms
     * @param  list<string>  $activeElixirBuffs
     * @return array<string, mixed>
     */
    public function getEffectiveChar(
        array $baseRow,
        array $equipment,
        array $skillLevels,
        ?string $characterClass,
        array $completedTransforms = [],
        array $activeElixirBuffs = [],
        int|float $contentLevel = 0,
    ): array {
        $eq = $this->getTotalEquipmentStats($equipment);
        $tb = SkillSystem::getTrainingBonuses($skillLevels, $characterClass);

        // Transformacje (LIVE) — flat do puli raw, procenty mnożą całość.
        $transformFlatHp = $this->transformBonuses->getTransformFlatHp($completedTransforms, $characterClass);
        $transformFlatMp = $this->transformBonuses->getTransformFlatMp($completedTransforms, $characterClass);
        $transformFlatAtk = $this->transformBonuses->getTransformFlatAttack($completedTransforms, $characterClass);
        $transformFlatDef = $this->transformBonuses->getTransformFlatDefense($completedTransforms, $characterClass);
        $transformHpRegenFlat = $this->transformBonuses->getTransformHpRegenFlat($completedTransforms, $characterClass);
        $transformMpRegenFlat = $this->transformBonuses->getTransformMpRegenFlat($completedTransforms, $characterClass);
        $transformAtkPctMult = $this->transformBonuses->getTransformAtkPctMultiplier($completedTransforms, $characterClass);
        $transformDefPctMult = $this->transformBonuses->getTransformDefPctMultiplier($completedTransforms, $characterClass);
        $transformHpPctMult = $this->transformBonuses->getTransformHpPctMultiplier($completedTransforms, $characterClass);
        $transformMpPctMult = $this->transformBonuses->getTransformMpPctMultiplier($completedTransforms, $characterClass);

        // Eliksiry bojowe.
        $elixHp = CombatElixirs::getElixirHpBonus($activeElixirBuffs);
        $elixMp = CombatElixirs::getElixirMpBonus($activeElixirBuffs);
        $elixAtk = CombatElixirs::getElixirAtkBonus($activeElixirBuffs);
        $elixDef = CombatElixirs::getElixirDefBonus($activeElixirBuffs);
        $elixHpPctMult = CombatElixirs::getElixirHpPctMultiplier($activeElixirBuffs);
        $elixMpPctMult = CombatElixirs::getElixirMpPctMultiplier($activeElixirBuffs);
        $elixAsMult = CombatElixirs::getElixirAttackSpeedMultiplier($activeElixirBuffs);

        // NaN hardening — każde pole z bazy defaultowane (jak `?? 0` na froncie).
        $baseAttack = self::num($baseRow['attack'] ?? 0);
        $baseDefense = self::num($baseRow['defense'] ?? 0);
        $baseMaxHp = self::num($baseRow['max_hp'] ?? 0);
        $baseMaxMp = self::num($baseRow['max_mp'] ?? 0);
        $baseAttackSpeedV = self::num($baseRow['attack_speed'] ?? 0);
        $baseCritChance = self::num($baseRow['crit_chance'] ?? 0);

        $baseAttackSpeed = $baseAttackSpeedV + $eq['speed'] * 0.01 + $tb['attack_speed'];

        $rawMaxHp = $baseMaxHp + $eq['hp'] + $tb['max_hp'] + $elixHp + $transformFlatHp;
        $rawMaxMp = $baseMaxMp + $eq['mp'] + $tb['max_mp'] + $elixMp + $transformFlatMp;
        $rawDefense = $baseDefense + $eq['defense'] + $tb['defense'] + $elixDef + $transformFlatDef;

        $gearGapMult = ItemEconomy::getGearGapMultiplier($this->getEquippedGearLevel($equipment), $contentLevel);
        $rawAttack = ($baseAttack + $eq['attack'] + $elixAtk + $transformFlatAtk) * $gearGapMult;

        return [
            ...$baseRow,
            'attack' => (int) floor($rawAttack * $transformAtkPctMult),
            'defense' => (int) floor($rawDefense * $transformDefPctMult),
            'max_hp' => (int) floor($rawMaxHp * $elixHpPctMult * $transformHpPctMult),
            'max_mp' => (int) floor($rawMaxMp * $elixMpPctMult * $transformMpPctMult),
            'attack_speed' => $baseAttackSpeed * $elixAsMult, // NIE floorowane
            'crit_chance' => min(0.5, $baseCritChance + $eq['critChance'] * 0.01 + $tb['crit_chance']),
            'crit_damage' => self::num($baseRow['crit_damage'] ?? 2.0) + $eq['critDmg'] * 0.01 + $tb['crit_dmg'],
            'hp_regen' => self::num($baseRow['hp_regen'] ?? 0) + $tb['hp_regen'] + $transformHpRegenFlat,
            'mp_regen' => self::num($baseRow['mp_regen'] ?? 0) + $tb['mp_regen'] + $transformMpRegenFlat,
        ];
    }

    // ---- Helpery ---------------------------------------------------------------

    /** Bazowy item z listy allItems (legacy). Null → item generowany. */
    private function findBaseItem(string $itemId): ?array
    {
        foreach ($this->allItems as $base) {
            if (($base['id'] ?? null) === $itemId) {
                return $base;
            }
        }

        return null;
    }

    /** Czy `key` to bazowy stat danego slotu (skaluje się z upgrade). */
    private function isBaseStatKey(?string $slot, string $key): bool
    {
        if ($slot === null) {
            return false;
        }

        return in_array($key, ItemGenerator::getBaseStatKeysForSlot($slot), true);
    }

    /** Koercja do skończonej liczby (undefined/null/NaN/Inf → 0). */
    private static function num(mixed $v): int|float
    {
        if (! is_numeric($v)) {
            return 0;
        }
        $n = $v + 0;

        return is_finite((float) $n) ? $n : 0;
    }

    /** Math.round z JS (half-up dla nieujemnych). */
    private static function jsRound(int|float $x): int
    {
        return (int) floor($x + 0.5);
    }
}
