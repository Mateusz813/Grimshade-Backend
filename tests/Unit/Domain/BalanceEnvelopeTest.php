<?php

declare(strict_types=1);

use App\Domain\Character\AttributeSystem;
use App\Domain\Combat\CombatMath;
use App\Domain\Skills\SkillSystem;

it('keeps the player damage compression curve sub-linear and inside its tuned band', function () {
    expect(CombatMath::DMG_COMPRESS_P)->toBeLessThan(1.0)
        ->and(CombatMath::DMG_COMPRESS_P)->toBeGreaterThan(0.5)
        ->and(CombatMath::DMG_COMPRESS_K)->toBeGreaterThan(0.5)
        ->and(CombatMath::DMG_COMPRESS_K)->toBeLessThan(10.0);

    $small = CombatMath::compressPlayerDamage(100);
    $big = CombatMath::compressPlayerDamage(10_000);
    expect($big / $small)->toBeLessThan(100.0);
});

it('never lets defense mitigate more than 75 percent', function () {
    expect(CombatMath::DEF_CAP)->toBeLessThanOrEqual(0.75);

    foreach ([1, 10, 100, 1000, 100_000] as $def) {
        foreach ([1, 50, 350, 1000] as $level) {
            expect(CombatMath::defMitigation($def, $level))->toBeLessThanOrEqual(0.75);
        }
    }
});

it('keeps a monster hit above a quarter of its raw damage however tanky the target', function () {
    foreach ([100, 500, 2000] as $raw) {
        expect(CombatMath::mitigateDamage($raw, 1_000_000, 1, false))
            ->toBeGreaterThanOrEqual((int) floor($raw * 0.25));
    }
});

it('bounds the crit multiplier to 1.5x - 2.5x', function () {
    expect(CombatMath::CRIT_MULT_MIN)->toBe(1.5)
        ->and(CombatMath::CRIT_MULT_MAX)->toBe(2.5);

    foreach ([-5.0, 0.0, 0.25, 0.5, 1.0, 5.0] as $roll) {
        $m = CombatMath::rollCritMultiplier($roll);
        expect($m)->toBeGreaterThanOrEqual(1.5)->and($m)->toBeLessThanOrEqual(2.5);
    }
});

it('keeps the skill upgrade curve diminishing and under 1.5x', function () {
    expect(SkillSystem::getCombatSkillUpgradeMultiplier(0))->toBe(1.0);

    foreach ([1, 5, 10, 20, 50, 200] as $u) {
        expect(SkillSystem::getCombatSkillUpgradeMultiplier($u))->toBeLessThan(1.5);
    }

    $earlyStep = SkillSystem::getCombatSkillUpgradeMultiplier(2) - SkillSystem::getCombatSkillUpgradeMultiplier(1);
    $lateStep = SkillSystem::getCombatSkillUpgradeMultiplier(20) - SkillSystem::getCombatSkillUpgradeMultiplier(19);
    expect($lateStep)->toBeLessThan($earlyStep);
});

it('caps the whole attribute budget at 10 percent in a single stat', function () {
    $budget = AttributeSystem::getAttributePointsForLevel(1000);
    expect($budget)->toBe(100);

    $m = AttributeSystem::getAttributeMultipliers(['attackPoints' => $budget], 'Archer');
    expect($m['attack'])->toBeLessThanOrEqual(1.10);

    foreach (array_keys(AttributeSystem::ATTRIBUTE_DEF_CAP_PCT) as $class) {
        $def = AttributeSystem::getAttributeMultipliers(['defensePoints' => 100_000], $class);
        expect($def['defense'])->toBeLessThanOrEqual(1.10);
    }
});

it('gives the Knight the highest defense cap and the Mage the lowest', function () {
    $caps = AttributeSystem::ATTRIBUTE_DEF_CAP_PCT;
    expect($caps['Knight'])->toBe(max($caps))
        ->and($caps['Mage'])->toBe(min($caps));
});
