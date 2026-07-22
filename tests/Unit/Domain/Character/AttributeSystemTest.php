<?php

declare(strict_types=1);

use App\Domain\Character\AttributeSystem;

it('awards one attribute point per 10 levels', function () {
    expect(AttributeSystem::getAttributePointsForLevel(1))->toBe(0)
        ->and(AttributeSystem::getAttributePointsForLevel(9))->toBe(0)
        ->and(AttributeSystem::getAttributePointsForLevel(10))->toBe(1)
        ->and(AttributeSystem::getAttributePointsForLevel(350))->toBe(35)
        ->and(AttributeSystem::getAttributePointsForLevel(1000))->toBe(100);
});

it('converts the per-class defense cap into a point budget', function () {
    foreach (AttributeSystem::ATTRIBUTE_DEF_CAP_PCT as $class => $capPct) {
        expect(AttributeSystem::getMaxDefensePoints($class))
            ->toBe((int) round($capPct / AttributeSystem::ATTRIBUTE_POINT_PCT));
    }
});

it('returns neutral multipliers for an empty allocation', function () {
    expect(AttributeSystem::getAttributeMultipliers([], 'Knight'))
        ->toBe(['attack' => 1.0, 'hp' => 1.0, 'defense' => 1.0]);
});

it('scales attack and hp linearly per point', function () {
    $m = AttributeSystem::getAttributeMultipliers(['attackPoints' => 40, 'hpPoints' => 10], 'Mage');
    expect($m['attack'])->toEqualWithDelta(1 + 40 * AttributeSystem::ATTRIBUTE_POINT_PCT / 100, 1e-9)
        ->and($m['hp'])->toEqualWithDelta(1 + 10 * AttributeSystem::ATTRIBUTE_POINT_PCT / 100, 1e-9);
});

it('clamps defense at the per-class cap', function () {
    foreach (AttributeSystem::ATTRIBUTE_DEF_CAP_PCT as $class => $capPct) {
        $m = AttributeSystem::getAttributeMultipliers(['defensePoints' => 10_000], $class);
        expect($m['defense'])->toEqualWithDelta(1 + $capPct / 100, 1e-9);
    }
});

it('gives Knight the highest and Mage the lowest defense cap', function () {
    $caps = AttributeSystem::ATTRIBUTE_DEF_CAP_PCT;
    expect($caps['Knight'])->toBe(max($caps))
        ->and($caps['Mage'])->toBe(min($caps));
});

it('treats negative allocations as zero', function () {
    expect(AttributeSystem::getAttributeMultipliers(
        ['attackPoints' => -5, 'hpPoints' => -5, 'defensePoints' => -5],
        'Rogue',
    ))->toBe(['attack' => 1.0, 'hp' => 1.0, 'defense' => 1.0]);
});

it('caps the full L1000 budget at the point budget x ATTRIBUTE_POINT_PCT in a single stat', function () {
    $budget = AttributeSystem::getAttributePointsForLevel(1000);
    $m = AttributeSystem::getAttributeMultipliers(['attackPoints' => $budget], 'Archer');
    expect($m['attack'])->toEqualWithDelta(1 + $budget * AttributeSystem::ATTRIBUTE_POINT_PCT / 100, 1e-9);
});

it('keeps every per-class defense cap reachable within the L1000 point budget', function () {
    $budget = AttributeSystem::getAttributePointsForLevel(1000);
    foreach (array_keys(AttributeSystem::ATTRIBUTE_DEF_CAP_PCT) as $class) {
        expect(AttributeSystem::getMaxDefensePoints($class))->toBeLessThanOrEqual($budget);
    }
});
