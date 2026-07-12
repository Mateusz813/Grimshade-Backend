<?php

declare(strict_types=1);

use App\Domain\Combat\CombatMath;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('combat.json');
});

it('matches calculateDamage', function () {
    foreach ($this->golden['calculateDamage'] as $i => $case) {
        expect(CombatMath::calculateDamage($case['params']))
            ->toEqual($case['result'], "calculateDamage #{$i}");
    }
});

it('matches calculateDualWieldDamage', function () {
    foreach ($this->golden['calculateDualWieldDamage'] as $i => $case) {
        expect(CombatMath::calculateDualWieldDamage($case['params']))
            ->toEqual($case['result'], "calculateDualWieldDamage #{$i}");
    }
});

it('matches calculateBlockChance', function () {
    foreach ($this->golden['calculateBlockChance'] as $case) {
        expect(CombatMath::calculateBlockChance($case['lvl'], $case['phys']))->toEqual($case['value']);
    }
});

it('matches calculateDodgeChance', function () {
    foreach ($this->golden['calculateDodgeChance'] as $case) {
        expect(CombatMath::calculateDodgeChance($case['cls'], $case['agi'], $case['phys']))->toEqual($case['value']);
    }
});

it('matches calculateSkillDamageWithMlvl', function () {
    foreach ($this->golden['calculateSkillDamageWithMlvl'] as $case) {
        [$d, $m, $e, $c] = $case['args'];
        expect(CombatMath::calculateSkillDamageWithMlvl($d, $m, $e, $c))->toEqual($case['value']);
    }
});

it('matches calculateSkillDamage', function () {
    foreach ($this->golden['calculateSkillDamage'] as $case) {
        [$a, $s, $e, $c] = $case['args'];
        expect(CombatMath::calculateSkillDamage($a, $s, $e, $c))->toEqual($case['value']);
    }
});

it('matches calculateAttackInterval', function () {
    foreach ($this->golden['calculateAttackInterval'] as $case) {
        expect(CombatMath::calculateAttackInterval($case['speed']))->toEqual($case['value']);
    }
});

it('matches calculateDeathPenalty', function () {
    foreach ($this->golden['calculateDeathPenalty'] as $case) {
        [$l, $xp, $next, $sxp] = $case['args'];
        expect(CombatMath::calculateDeathPenalty($l, $xp, $next, $sxp))
            ->toEqual($case['result'], 'calculateDeathPenalty '.json_encode($case['args']));
    }
});

it('matches applyDeathPenalty (legacy)', function () {
    foreach ($this->golden['applyDeathPenalty'] as $case) {
        [$xp, $lxp, $sxp] = $case['args'];
        expect(CombatMath::applyDeathPenalty($xp, $lxp, $sxp))->toEqual($case['result']);
    }
});

it('matches getSpeedMultiplier (x1/x2/x4)', function () {
    foreach ($this->golden['getSpeedMultiplier'] as $case) {
        expect(CombatMath::getSpeedMultiplier($case['speed']))->toEqual($case['value']);
    }
});

it('returns INF for SKIP speed (not in golden — JSON cannot hold Infinity)', function () {
    expect(CombatMath::getSpeedMultiplier('SKIP'))->toBe(INF);
});

it('matches getMonsterAttackRange', function () {
    foreach ($this->golden['getMonsterAttackRange'] as $case) {
        expect(CombatMath::getMonsterAttackRange($case['monster']))
            ->toEqual($case['result'], 'getMonsterAttackRange '.json_encode($case['monster']));
    }
});

it('matches applyMonsterRarity', function () {
    $base = ['hp' => 200, 'attack' => 50, 'defense' => 20, 'xp' => 100, 'gold' => [10, 40]];
    foreach ($this->golden['applyMonsterRarity'] as $case) {
        expect(CombatMath::applyMonsterRarity($base, $case['rarity']))
            ->toEqual($case['result'], "applyMonsterRarity {$case['rarity']}");
    }
});

it('matches getSpeedScaledCooldownMs', function () {
    foreach ($this->golden['getSpeedScaledCooldownMs'] as $case) {
        expect(CombatMath::getSpeedScaledCooldownMs($case['cd'], $case['mult']))->toEqual($case['value']);
    }
});
