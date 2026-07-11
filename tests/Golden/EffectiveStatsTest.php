<?php

declare(strict_types=1);

use App\Domain\Character\EffectiveStats;
use App\Domain\Content\ContentRepository;

/**
 * PARYTET getEffectiveChar (port src/systems/combatEngine.ts:786-836 + zależne
 * helpery itemSystem.ts). Brak wygenerowanego golden-fixture na froncie → wartości
 * oczekiwane wyliczone RĘCZNIE z formuł i skomentowane krok po kroku.
 */
beforeEach(function () {
    // Golden testy nie bootują aplikacji (brak resource_path) — ścieżka względem pliku.
    $this->eff = EffectiveStats::fromContent(new ContentRepository(dirname(__DIR__, 2).'/resources/game-content'));
});

// ---- Parser itemId ---------------------------------------------------------

it('parses generated item ids into {type, slot, itemLevel}', function () {
    expect($this->eff->getGeneratedItemInfo('sword_lvl10_epic'))
        ->toEqual(['type' => 'sword', 'slot' => 'mainHand', 'itemLevel' => 10]);

    expect($this->eff->getGeneratedItemInfo('heavy_armor_lvl20_legendary'))
        ->toEqual(['type' => 'heavy_armor', 'slot' => 'armor', 'itemLevel' => 20]);

    expect($this->eff->getGeneratedItemInfo('ring_lvl10_mythic'))
        ->toEqual(['type' => 'ring', 'slot' => 'ring1', 'itemLevel' => 10]);

    // Starter (bez _lvl) → itemLevel null.
    expect($this->eff->getGeneratedItemInfo('starter_sword'))
        ->toEqual(['type' => 'sword', 'slot' => 'mainHand', 'itemLevel' => null]);

    // Legacy id bez _lvl i spoza szablonów → null.
    expect($this->eff->getGeneratedItemInfo('sword_of_beginnings'))->toBeNull();
});

// ---- getTotalEquipmentStats ------------------------------------------------

it('sums generated equipment stats: base-stat keys scale with upgrade, extras flat', function () {
    $equipment = [
        // mainHand: dmg_min/dmg_max pomijane (nie IItemStats); attack (base) +5, hp (extra) +8.
        'mainHand' => [
            'itemId' => 'sword_lvl10_epic', 'rarity' => 'epic',
            'bonuses' => ['dmg_min' => 20, 'dmg_max' => 30, 'attack' => 5, 'hp' => 8],
            'itemLevel' => 10, 'upgradeLevel' => 0,
        ],
        // mythic ring: attack (base) +15, critChance +12 (flat), critDmg +20 (flat).
        'ring1' => [
            'itemId' => 'ring_lvl10_mythic', 'rarity' => 'mythic',
            'bonuses' => ['attack' => 15, 'critChance' => 12, 'critDmg' => 20],
            'itemLevel' => 10, 'upgradeLevel' => 0,
        ],
    ];

    expect($this->eff->getTotalEquipmentStats($equipment))->toEqual([
        'attack' => 20, 'defense' => 0, 'hp' => 8, 'mp' => 0,
        'speed' => 0, 'critChance' => 12, 'critDmg' => 20,
    ]);
});

it('scales only the base stat of a slot by upgrade level (getUpgradedBaseStat)', function () {
    // armor: hp is base → getUpgradedBaseStat(60, 5) = max(round(60*1.5)=90, 60+5=65) = 90.
    //        defense is NOT a base key for armor → stays flat 10.
    $equipment = [
        'armor' => [
            'itemId' => 'heavy_armor_lvl20_legendary', 'rarity' => 'legendary',
            'bonuses' => ['hp' => 60, 'defense' => 10],
            'itemLevel' => 20, 'upgradeLevel' => 5,
        ],
    ];

    $eq = $this->eff->getTotalEquipmentStats($equipment);
    expect($eq['hp'])->toBe(90)
        ->and($eq['defense'])->toBe(10);
});

// ---- getEquippedGearLevel --------------------------------------------------

it('averages equipped generated item levels (Math.round)', function () {
    $equipment = [
        'mainHand' => ['itemId' => 'sword_lvl10_common', 'bonuses' => []],
        'helmet' => ['itemId' => 'heavy_helmet_lvl20_common', 'bonuses' => []],
    ];
    // round((10 + 20) / 2) = 15.
    expect($this->eff->getEquippedGearLevel($equipment))->toBe(15);
    // Brak generowanych itemów → 1.
    expect($this->eff->getEquippedGearLevel([]))->toBe(1);
});

// ---- getClassSkillBonus ----------------------------------------------------

it('computes class skill bonus + extra crit per class table', function () {
    expect(EffectiveStats::getClassSkillBonus('Knight', ['sword_fighting' => 21]))
        ->toEqual(['skillBonus' => 10, 'extraCritChance' => 0.0]); // floor(21*0.5)=10

    expect(EffectiveStats::getClassSkillBonus('Archer', ['distance_fighting' => 100]))
        ->toEqual(['skillBonus' => 40, 'extraCritChance' => 0.3]); // floor(100*0.4)=40, 100*0.003

    $rogue = EffectiveStats::getClassSkillBonus('Rogue', ['dagger_fighting' => 50]);
    expect($rogue['skillBonus'])->toBe(15) // floor(50*0.3)
        ->and($rogue['extraCritChance'])->toEqualWithDelta(0.25, 1e-9); // 50*0.005
});

// ---- getEffectiveChar (full aggregation) -----------------------------------

it('aggregates effective char: equipment crit + attack, no elixir/transform', function () {
    $baseRow = [
        'attack' => 100, 'defense' => 50, 'max_hp' => 500, 'max_mp' => 200,
        'attack_speed' => 1.0, 'crit_chance' => 0.05, 'crit_damage' => 1.5,
        'hp_regen' => 2, 'mp_regen' => 1,
    ];
    $equipment = [
        'mainHand' => [
            'itemId' => 'sword_lvl10_epic', 'rarity' => 'epic',
            'bonuses' => ['dmg_min' => 20, 'dmg_max' => 30, 'attack' => 5, 'hp' => 8],
            'itemLevel' => 10, 'upgradeLevel' => 0,
        ],
        'ring1' => [
            'itemId' => 'ring_lvl10_mythic', 'rarity' => 'mythic',
            'bonuses' => ['attack' => 15, 'critChance' => 12, 'critDmg' => 20],
            'itemLevel' => 10, 'upgradeLevel' => 0,
        ],
    ];

    $e = $this->eff->getEffectiveChar($baseRow, $equipment, [], 'Knight');

    expect($e['attack'])->toBe(120)        // (100 + 20 eq) * 1.0
        ->and($e['defense'])->toBe(50)     // 50 + 0
        ->and($e['max_hp'])->toBe(508)     // 500 + 8 eq.hp
        ->and($e['max_mp'])->toBe(200);
    expect($e['attack_speed'])->toEqualWithDelta(1.0, 1e-9);
    expect($e['crit_chance'])->toEqualWithDelta(0.17, 1e-9);  // 0.05 + 12*0.01
    expect($e['crit_damage'])->toEqualWithDelta(1.70, 1e-9);  // 1.5 + 20*0.01
    expect($e['hp_regen'])->toEqualWithDelta(2.0, 1e-9);
    expect($e['mp_regen'])->toEqualWithDelta(1.0, 1e-9);
});

it('aggregates training + elixirs: crit cap 0.5, attack_speed & hp% multipliers', function () {
    $baseRow = [
        'attack' => 200, 'defense' => 30, 'max_hp' => 400, 'max_mp' => 300,
        'attack_speed' => 1.0, 'crit_chance' => 0.2, 'crit_damage' => 1.5,
        'hp_regen' => 0, 'mp_regen' => 0,
    ];
    $equipment = [
        'ring1' => [
            'itemId' => 'ring_lvl30_heroic', 'rarity' => 'heroic',
            'bonuses' => ['attack' => 40, 'critChance' => 40],
            'itemLevel' => 30, 'upgradeLevel' => 0,
        ],
    ];
    // Mage training: attack_speed 5*0.1=0.5, max_hp 3*5=15, defense 4, crit_chance 10*0.005=0.05.
    $skillLevels = ['crit_chance' => 10, 'attack_speed' => 5, 'max_hp' => 3, 'defense' => 4];
    $elixirs = ['attack_speed', 'atk_boost_50', 'hp_pct_25'];

    $e = $this->eff->getEffectiveChar($baseRow, $equipment, $skillLevels, 'Mage', [], $elixirs);

    expect($e['attack'])->toBe(290)     // (200 + 40 eq + 50 elix) * 1.0
        ->and($e['defense'])->toBe(34)  // 30 + 4 training
        ->and($e['max_hp'])->toBe(518)  // floor((400 + 15 training) * 1.25 hp%)
        ->and($e['max_mp'])->toBe(300);
    expect($e['attack_speed'])->toEqualWithDelta(1.8, 1e-9);  // (1.0 + 0.5 training) * 1.20 elix
    expect($e['crit_chance'])->toEqualWithDelta(0.5, 1e-9);   // min(0.5, 0.2 + 0.4 + 0.05) → capped
    expect($e['crit_damage'])->toEqualWithDelta(1.5, 1e-9);
});

it('applies gear-gap penalty when under-geared for content level', function () {
    $baseRow = ['attack' => 100, 'max_hp' => 200, 'defense' => 0, 'max_mp' => 0, 'attack_speed' => 1.0, 'crit_chance' => 0.0];
    $equipment = [
        'mainHand' => ['itemId' => 'sword_lvl10_common', 'bonuses' => []],
        'helmet' => ['itemId' => 'heavy_helmet_lvl20_common', 'bonuses' => ['hp' => 30]],
    ];
    // gearLevel = round((10+20)/2) = 15; contentLevel 30 → mult = max(0.05, (15/30)^2) = 0.25.
    $e = $this->eff->getEffectiveChar($baseRow, $equipment, [], 'Knight', [], [], 30);

    expect($e['attack'])->toBe(25)      // floor(100 * 0.25)
        ->and($e['max_hp'])->toBe(230); // 200 + 30 eq.hp (helmet base scales, up 0)
});
