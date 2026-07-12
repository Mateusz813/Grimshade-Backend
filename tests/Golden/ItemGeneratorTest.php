<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;
use App\Domain\Items\ItemEconomy;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

function makeGenerator(int $seed): ItemGenerator
{
    $content = new ContentRepository(dirname(__DIR__, 2).'/resources/game-content');

    return new ItemGenerator($content->get('itemTemplates'), new Mulberry32Rng($seed));
}

function stripUuid(?array $item): ?array
{
    if ($item === null) {
        return null;
    }
    unset($item['uuid']);

    return $item;
}

beforeEach(function () {
    $this->golden = Golden::load('itemGenerator.json');
});

it('matches generateWeapon (common, seeded)', function () {
    foreach ($this->golden['generateWeapon'] as $case) {
        $gen = makeGenerator($case['seed']);
        expect(stripUuid($gen->generateWeapon($case['type'], $case['lvl'], 'common')))
            ->toEqual($case['result'], "generateWeapon {$case['type']} lvl{$case['lvl']}");
    }
});

it('matches generateOffhand (common, seeded, incl. Rogue dual-wield path)', function () {
    foreach ($this->golden['generateOffhand'] as $case) {
        $gen = makeGenerator($case['seed']);
        expect(stripUuid($gen->generateOffhand($case['type'], $case['lvl'], 'common')))
            ->toEqual($case['result'], "generateOffhand {$case['type']} seed {$case['seed']}");
    }
});

it('matches generateArmor (common, seeded, wszystkie prefiksy × sloty)', function () {
    foreach ($this->golden['generateArmor'] as $case) {
        $gen = makeGenerator($case['seed']);
        expect(stripUuid($gen->generateArmor($case['prefix'], $case['slot'], $case['lvl'], 'common')))
            ->toEqual($case['result'], "generateArmor {$case['prefix']}/{$case['slot']}");
    }
});

it('matches generateAccessory (common, seeded)', function () {
    foreach ($this->golden['generateAccessory'] as $case) {
        $gen = makeGenerator($case['seed']);
        expect(stripUuid($gen->generateAccessory($case['type'], $case['lvl'], 'common')))
            ->toEqual($case['result'], "generateAccessory {$case['type']} seed {$case['seed']}");
    }
});

it('matches generateRandomItemForClass (common, seeded — kategorie+sloty 1:1)', function () {
    foreach ($this->golden['generateRandomItemForClass'] as $case) {
        $gen = makeGenerator($case['seed']);
        expect(stripUuid($gen->generateRandomItemForClass($case['cls'], $case['lvl'], 'common')))
            ->toEqual($case['result'], "generateRandomItemForClass {$case['cls']} seed {$case['seed']}");
    }
});

it('matches generateStarterWeapon for all classes', function () {
    foreach ($this->golden['generateStarterWeapon'] as $case) {
        $gen = makeGenerator(1);
        expect(stripUuid($gen->generateStarterWeapon($case['cls'])))
            ->toEqual($case['result'], "generateStarterWeapon {$case['cls']}");
    }
});

it('matches getItemDisplayInfo parser (incl. legacy + unknown)', function () {
    $gen = makeGenerator(1);
    foreach ($this->golden['getItemDisplayInfo'] as $case) {
        expect($gen->getItemDisplayInfo($case['id']))
            ->toEqual($case['result'], "getItemDisplayInfo {$case['id']}");
    }
});

it('generates correct bonus counts and ranges for higher rarities (property)', function () {
    $ranges = [
        'rare' => [3, 12], 'epic' => [5, 18], 'legendary' => [10, 35],
        'mythic' => [20, 60], 'heroic' => [40, 100],
    ];
    foreach (['rare', 'epic', 'legendary', 'mythic', 'heroic'] as $rarity) {
        $expectedSlots = ItemEconomy::RARITY_BONUS_SLOTS[$rarity];
        foreach ([1, 42, 777] as $seed) {
            $gen = makeGenerator($seed);
            $item = $gen->generateWeapon('sword', 100, $rarity);
            $random = array_diff_key($item['bonuses'], ['dmg_min' => 1, 'dmg_max' => 1]);

            expect(count($random))->toBe($expectedSlots, "{$rarity} seed {$seed}")
                ->and(array_key_exists('attack', $random))->toBeFalse();

            [$min, $max] = $ranges[$rarity];
            foreach ($random as $stat => $value) {
                $mult = ['critChance' => 0.3, 'critDmg' => 1.5][$stat] ?? 1.0;
                expect($value)->toBeGreaterThanOrEqual(max(1, (int) floor($min * $mult)))
                    ->and($value)->toBeLessThanOrEqual((int) ceil($max * $mult) + 1);
            }
        }
    }
});

it('is deterministic per seed (same seed → same item)', function () {
    $a = makeGenerator(99)->generateRandomItem(120, 'mythic');
    $b = makeGenerator(99)->generateRandomItem(120, 'mythic');
    expect($a)->toEqual($b);
});
