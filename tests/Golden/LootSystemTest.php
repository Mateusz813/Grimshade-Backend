<?php

declare(strict_types=1);

use App\Domain\Loot\LootSystem;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

/**
 * PARYTET lootSystem: deterministyczne helpery + funkcje RNG (z tym samym
 * seedem mulberry32 co TS → identyczna sekwencja → identyczny wynik).
 */
beforeEach(function () {
    $this->golden = Golden::load('lootSystem.json');
});

it('matches scaleHeroicDropRate', function () {
    foreach ($this->golden['scaleHeroicDropRate'] as $case) {
        expect(LootSystem::scaleHeroicDropRate($case['rate'], $case['lvl']))->toEqual($case['value']);
    }
});

it('matches getGeneratedSellPrice', function () {
    foreach ($this->golden['getGeneratedSellPrice'] as $case) {
        expect(LootSystem::getGeneratedSellPrice($case['rarity'], $case['lvl']))->toEqual($case['value']);
    }
});

it('matches getMaxRarityForLevel', function () {
    foreach ($this->golden['getMaxRarityForLevel'] as $case) {
        expect(LootSystem::getMaxRarityForLevel($case['lvl']))->toEqual($case['value']);
    }
});

it('matches getEffectiveRarityChances', function () {
    foreach ($this->golden['getEffectiveRarityChances'] as $case) {
        expect(LootSystem::getEffectiveRarityChances($case['m']))
            ->toEqual($case['value'], 'getEffectiveRarityChances '.json_encode($case['m']));
    }
});

it('matches rollMonsterRarity (seeded)', function () {
    foreach ($this->golden['rollMonsterRarity'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(LootSystem::rollMonsterRarity($rng, $case['skip'], $case['mastery']))
            ->toEqual($case['value'], "rollMonsterRarity seed {$case['seed']}");
    }
});

it('matches rollRarity (seeded, incl. boss+heroic 2-roll path)', function () {
    foreach ($this->golden['rollRarity'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(LootSystem::rollRarity($rng, $case['monsterRarity'], $case['heroic']))
            ->toEqual($case['value'], "rollRarity seed {$case['seed']} {$case['monsterRarity']}");
    }
});

it('matches rollStoneDrop (seeded)', function () {
    foreach ($this->golden['rollStoneDrop'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(LootSystem::rollStoneDrop($rng, $case['monsterLevel'], $case['monsterRarity']))
            ->toEqual($case['value'], "rollStoneDrop seed {$case['seed']} {$case['monsterRarity']}");
    }
});

it('matches calculateGoldDrop (seeded)', function () {
    foreach ($this->golden['calculateGoldDrop'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(LootSystem::calculateGoldDrop($rng, $case['goldRange'], $case['partySize']))
            ->toEqual($case['value'], "calculateGoldDrop seed {$case['seed']}");
    }
});

it('matches rollPotionDrop (seeded, 2 + 4 roll paths)', function () {
    foreach ($this->golden['rollPotionDrop'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(LootSystem::rollPotionDrop($rng, $case['monsterLevel']))
            ->toEqual($case['value'], "rollPotionDrop seed {$case['seed']} lvl {$case['monsterLevel']}");
    }
});
