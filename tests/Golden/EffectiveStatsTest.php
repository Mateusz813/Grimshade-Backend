<?php

declare(strict_types=1);

use App\Domain\Character\EffectiveStats;
use App\Domain\Content\ContentRepository;

beforeEach(function () {
    $this->eff = EffectiveStats::fromContent(new ContentRepository(dirname(__DIR__, 2).'/resources/game-content'));
});


it('parses generated item ids into {type, slot, itemLevel}', function () {
    expect($this->eff->getGeneratedItemInfo('sword_lvl10_epic'))
        ->toEqual(['type' => 'sword', 'slot' => 'mainHand', 'itemLevel' => 10]);

    expect($this->eff->getGeneratedItemInfo('heavy_armor_lvl20_legendary'))
        ->toEqual(['type' => 'heavy_armor', 'slot' => 'armor', 'itemLevel' => 20]);

    expect($this->eff->getGeneratedItemInfo('ring_lvl10_mythic'))
        ->toEqual(['type' => 'ring', 'slot' => 'ring1', 'itemLevel' => 10]);

    expect($this->eff->getGeneratedItemInfo('starter_sword'))
        ->toEqual(['type' => 'sword', 'slot' => 'mainHand', 'itemLevel' => null]);

    expect($this->eff->getGeneratedItemInfo('sword_of_beginnings'))->toBeNull();
});


it('sums generated equipment stats: base-stat keys scale with upgrade, extras flat', function () {
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

    expect($this->eff->getTotalEquipmentStats($equipment))->toEqual([
        'attack' => 20, 'defense' => 0, 'hp' => 8, 'mp' => 0,
        'speed' => 0, 'critChance' => 12, 'critDmg' => 20,
    ]);
});

it('scales only the base stat of a slot by upgrade level (getUpgradedBaseStat)', function () {
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


it('averages equipped generated item levels (Math.round)', function () {
    $equipment = [
        'mainHand' => ['itemId' => 'sword_lvl10_common', 'bonuses' => []],
        'helmet' => ['itemId' => 'heavy_helmet_lvl20_common', 'bonuses' => []],
    ];
    expect($this->eff->getEquippedGearLevel($equipment))->toBe(15);
    expect($this->eff->getEquippedGearLevel([]))->toBe(1);
});


it('computes class skill bonus + extra crit per class table', function () {
    expect(EffectiveStats::getClassSkillBonus('Knight', ['sword_fighting' => 21]))
        ->toEqual(['skillBonus' => 10, 'extraCritChance' => 0.0]);

    expect(EffectiveStats::getClassSkillBonus('Archer', ['distance_fighting' => 100]))
        ->toEqual(['skillBonus' => 40, 'extraCritChance' => 0.3]);

    $rogue = EffectiveStats::getClassSkillBonus('Rogue', ['dagger_fighting' => 50]);
    expect($rogue['skillBonus'])->toBe(15)
        ->and($rogue['extraCritChance'])->toEqualWithDelta(0.25, 1e-9);
});


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

    expect($e['attack'])->toBe(120)
        ->and($e['defense'])->toBe(50)
        ->and($e['max_hp'])->toBe(508)
        ->and($e['max_mp'])->toBe(200);
    expect($e['attack_speed'])->toEqualWithDelta(1.0, 1e-9);
    expect($e['crit_chance'])->toEqualWithDelta(0.17, 1e-9);
    expect($e['crit_damage'])->toEqualWithDelta(1.70, 1e-9);
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
    $skillLevels = ['crit_chance' => 10, 'attack_speed' => 5, 'max_hp' => 3, 'defense' => 4];
    $elixirs = ['attack_speed', 'atk_boost_50', 'hp_pct_25'];

    $e = $this->eff->getEffectiveChar($baseRow, $equipment, $skillLevels, 'Mage', [], $elixirs);

    expect($e['attack'])->toBe(290)
        ->and($e['defense'])->toBe(34)
        ->and($e['max_hp'])->toBe(518)
        ->and($e['max_mp'])->toBe(300);
    expect($e['attack_speed'])->toEqualWithDelta(1.8, 1e-9);
    expect($e['crit_chance'])->toEqualWithDelta(0.5, 1e-9);
    expect($e['crit_damage'])->toEqualWithDelta(1.5, 1e-9);
});

it('applies gear-gap penalty when under-geared for content level', function () {
    $baseRow = ['attack' => 100, 'max_hp' => 200, 'defense' => 0, 'max_mp' => 0, 'attack_speed' => 1.0, 'crit_chance' => 0.0];
    $equipment = [
        'mainHand' => ['itemId' => 'sword_lvl10_common', 'bonuses' => []],
        'helmet' => ['itemId' => 'heavy_helmet_lvl20_common', 'bonuses' => ['hp' => 30]],
    ];
    $e = $this->eff->getEffectiveChar($baseRow, $equipment, [], 'Knight', [], [], 30);

    expect($e['attack'])->toBe(25)
        ->and($e['max_hp'])->toBe(230);
});
