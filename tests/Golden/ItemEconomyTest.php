<?php

declare(strict_types=1);

use App\Domain\Items\ItemEconomy;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('itemSystem.json');
});

it('matches getRequiredStoneType', function () {
    foreach ($this->golden['getRequiredStoneType'] as $case) {
        expect(ItemEconomy::getRequiredStoneType($case['rarity']))->toEqual($case['value']);
    }
});

it('matches getEnhancementCost (table + >20 formula)', function () {
    foreach ($this->golden['getEnhancementCost'] as $case) {
        expect(ItemEconomy::getEnhancementCost($case['lvl'], $case['rarity']))
            ->toEqual($case['result'], "getEnhancementCost({$case['lvl']},{$case['rarity']})");
    }
});

it('matches getEnhancementMultiplier', function () {
    foreach ($this->golden['getEnhancementMultiplier'] as $case) {
        expect(ItemEconomy::getEnhancementMultiplier($case['u']))->toEqual($case['value']);
    }
});

it('matches getUpgradedBaseStat', function () {
    foreach ($this->golden['getUpgradedBaseStat'] as $case) {
        expect(ItemEconomy::getUpgradedBaseStat($case['base'], $case['u']))
            ->toEqual($case['value'], "getUpgradedBaseStat({$case['base']},{$case['u']})");
    }
});

it('matches getGearGapMultiplier', function () {
    foreach ($this->golden['getGearGapMultiplier'] as $case) {
        expect(ItemEconomy::getGearGapMultiplier($case['gear'], $case['content']))
            ->toEqual($case['value'], "getGearGapMultiplier({$case['gear']},{$case['content']})");
    }
});

it('matches getEnhancementRefund', function () {
    foreach ($this->golden['getEnhancementRefund'] as $case) {
        expect(ItemEconomy::getEnhancementRefund($case['lvl'], $case['rarity']))
            ->toEqual($case['result'], "getEnhancementRefund({$case['lvl']},{$case['rarity']})");
    }
});

it('matches getSellPrice (with and without baseData)', function () {
    foreach ($this->golden['getSellPrice'] as $case) {
        $baseData = $case['basePrice'] === null ? null : ['basePrice' => $case['basePrice']];
        expect(ItemEconomy::getSellPrice($case['item'], $baseData))
            ->toEqual($case['value'], 'getSellPrice '.json_encode($case['item']));
    }
});
