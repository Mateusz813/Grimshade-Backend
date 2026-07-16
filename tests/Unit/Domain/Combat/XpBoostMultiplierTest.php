<?php

declare(strict_types=1);

use App\Domain\Combat\CombatElixirs;

it('getXpBoostMultiplier returns 1.0 with no XP buffs', function () {
    expect(CombatElixirs::getXpBoostMultiplier([]))->toBe(1.0);
});

it('getXpBoostMultiplier applies xp_boost 1.5x', function () {
    expect(CombatElixirs::getXpBoostMultiplier(['xp_boost']))->toBe(1.5);
});

it('getXpBoostMultiplier prefers xp_boost_100 (2.0x) over xp_boost', function () {
    expect(CombatElixirs::getXpBoostMultiplier(['xp_boost', 'xp_boost_100']))->toBe(2.0);
});

it('getXpBoostMultiplier stacks premium multiplicatively (1.5 x 2 = 3)', function () {
    expect(CombatElixirs::getXpBoostMultiplier(['xp_boost', 'premium_xp_boost']))->toBe(3.0);
    expect(CombatElixirs::getXpBoostMultiplier(['xp_boost_100', 'premium_xp_boost']))->toBe(4.0);
});

it('getSkillXpBoostMultiplier applies skill boosts', function () {
    expect(CombatElixirs::getSkillXpBoostMultiplier([]))->toBe(1.0);
    expect(CombatElixirs::getSkillXpBoostMultiplier(['skill_xp_boost']))->toBe(1.5);
    expect(CombatElixirs::getSkillXpBoostMultiplier(['skill_xp_boost', 'skill_xp_boost_100']))->toBe(2.0);
});

it('activeBuffEffects keeps realtime buffs not yet expired, drops expired and other characters', function () {
    $now = 1_000_000;
    $blob = ['buffs' => ['allBuffs' => [
        ['effect' => 'xp_boost', 'characterId' => 'c1', 'timerMode' => 'realtime', 'expiresAt' => $now + 60_000],
        ['effect' => 'xp_boost_100', 'characterId' => 'c1', 'timerMode' => 'realtime', 'expiresAt' => $now - 1],
        ['effect' => 'premium_xp_boost', 'characterId' => 'c2', 'timerMode' => 'realtime', 'expiresAt' => $now + 60_000],
    ]]];
    $effects = CombatElixirs::activeBuffEffects($blob, 'c1', $now);
    expect($effects)->toContain('xp_boost');
    expect($effects)->not->toContain('xp_boost_100');
    expect($effects)->not->toContain('premium_xp_boost');
});

it('activeBuffEffects keeps charge and pausable buffs with remaining time', function () {
    $now = 1_000_000;
    $blob = ['buffs' => ['allBuffs' => [
        ['effect' => 'shadow_step', 'timerMode' => 'realtime', 'charges' => 2, 'expiresAt' => 0],
        ['effect' => 'atk_dmg_50', 'timerMode' => 'pausable', 'remainingMs' => 5000],
        ['effect' => 'dead_pausable', 'timerMode' => 'pausable', 'remainingMs' => 0],
    ]]];
    $effects = CombatElixirs::activeBuffEffects($blob, null, $now);
    expect($effects)->toContain('shadow_step');
    expect($effects)->toContain('atk_dmg_50');
    expect($effects)->not->toContain('dead_pausable');
});

it('combines activeBuffEffects with getXpBoostMultiplier for a realtime elixir', function () {
    $now = 1_000_000;
    $blob = ['buffs' => ['allBuffs' => [
        ['effect' => 'xp_boost_100', 'characterId' => 'c1', 'timerMode' => 'realtime', 'expiresAt' => $now + 60_000],
        ['effect' => 'premium_xp_boost', 'characterId' => 'c1', 'timerMode' => 'realtime', 'expiresAt' => $now + 60_000],
    ]]];
    $mult = CombatElixirs::getXpBoostMultiplier(CombatElixirs::activeBuffEffects($blob, 'c1', $now));
    expect($mult)->toBe(4.0);
    expect((int) floor(100 * $mult))->toBe(400);
});
