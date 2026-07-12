<?php

declare(strict_types=1);

use App\Domain\Skills\SkillEffectsV2;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('skillEffectsV2.json');
});


it('matches newStatusState', function () {
    expect(SkillEffectsV2::newStatusState())->toEqual($this->golden['newStatusState']);
});

it('matches parseEffects', function () {
    foreach ($this->golden['parseEffects'] as $case) {
        expect(SkillEffectsV2::parseEffects($case['effect']))
            ->toEqual($case['result'], 'parseEffects '.json_encode($case['effect']));
    }
});

it('matches hasEffect', function () {
    foreach ($this->golden['hasEffect'] as $case) {
        expect(SkillEffectsV2::hasEffect(SkillEffectsV2::parseEffects($case['effect']), $case['key']))
            ->toEqual($case['result'], "hasEffect {$case['effect']} {$case['key']}");
    }
});

it('matches findEffect', function () {
    foreach ($this->golden['findEffect'] as $case) {
        expect(SkillEffectsV2::findEffect(SkillEffectsV2::parseEffects($case['effect']), $case['key']))
            ->toEqual($case['result'], "findEffect {$case['effect']} {$case['key']}");
    }
});

it('matches isStunned', function () {
    foreach ($this->golden['isStunned'] as $case) {
        expect(SkillEffectsV2::isStunned(['stunMs' => $case['stunMs']]))
            ->toEqual($case['result'], "isStunned {$case['stunMs']}");
    }
});

it('matches skillTargetsEnemy', function () {
    foreach ($this->golden['skillTargetsEnemy'] as $case) {
        expect(SkillEffectsV2::skillTargetsEnemy($case['effect']))
            ->toEqual($case['result'], 'skillTargetsEnemy '.json_encode($case['effect']));
    }
});

it('matches applyIncomingDamage', function () {
    foreach ($this->golden['applyIncomingDamage'] as $case) {
        $target = ['immortalMs' => $case['immortalMs'], 'cannotDieMs' => $case['cannotDieMs']];
        expect(SkillEffectsV2::applyIncomingDamage($target, $case['targetCurrentHp'], $case['rawDamage']))
            ->toEqual($case['result'], "applyIncomingDamage hp {$case['targetCurrentHp']} dmg {$case['rawDamage']}");
    }
});

it('matches applyManaShieldRedirect', function () {
    foreach ($this->golden['applyManaShieldRedirect'] as $case) {
        $s = $case['manaShieldMs'] === null ? null : ['manaShieldMs' => $case['manaShieldMs']];
        expect(SkillEffectsV2::applyManaShieldRedirect($s, $case['currentMp'], $case['rawDmg']))
            ->toEqual($case['result'], "applyManaShieldRedirect mp {$case['currentMp']} dmg {$case['rawDmg']}");
    }
});

it('matches applyIncomingHeal', function () {
    foreach ($this->golden['applyIncomingHeal'] as $case) {
        $target = ['enemyNoHealMs' => $case['enemyNoHealMs'], 'markNoHealMs' => $case['markNoHealMs']];
        expect(SkillEffectsV2::applyIncomingHeal($target, $case['rawHeal']))
            ->toEqual($case['result'], "applyIncomingHeal heal {$case['rawHeal']}");
    }
});


it('matches tickStatus (mutuje stan)', function () {
    foreach ($this->golden['tickStatus'] as $i => $case) {
        $s = $case['before'];
        $result = SkillEffectsV2::tickStatus($s, $case['deltaMs'], $case['targetMaxHp']);
        expect($result)->toEqual($case['result'], "tickStatus #{$i} result");
        expect($s)->toEqual($case['after'], "tickStatus #{$i} state");
    }
});

it('matches consumeTargetMarkAmp (mutuje stan)', function () {
    foreach ($this->golden['consumeTargetMarkAmp'] as $i => $case) {
        $t = $case['before'];
        $result = SkillEffectsV2::consumeTargetMarkAmp($t);
        expect($result)->toEqual($case['result'], "consumeTargetMarkAmp #{$i} result");
        expect($t)->toEqual($case['after'], "consumeTargetMarkAmp #{$i} state");
    }
});


it('matches consumeCasterBasicHitMods (mutuje stan + RNG crit_next ułamkowy)', function () {
    foreach ($this->golden['consumeCasterBasicHitMods'] as $i => $case) {
        $rng = new Mulberry32Rng($case['seed']);
        $s = $case['before'];
        $result = SkillEffectsV2::consumeCasterBasicHitMods($rng, $s);
        expect($result)->toEqual($case['result'], "consumeCasterBasicHitMods #{$i} result");
        expect($s)->toEqual($case['after'], "consumeCasterBasicHitMods #{$i} state");
    }
});

it('matches resolveBasicHit (mutuje stan + RNG dodge/crit/party-IK)', function () {
    foreach ($this->golden['resolveBasicHit'] as $i => $case) {
        $rng = new Mulberry32Rng($case['seed']);
        $attacker = $case['attacker'];
        $target = $case['target'];
        $result = SkillEffectsV2::resolveBasicHit($rng, $attacker, $case['attackerClass'], $case['attackerBaseDmg'], $target);
        expect($result)->toEqual($case['result'], "resolveBasicHit #{$i} result");
        expect($attacker)->toEqual($case['attackerAfter'], "resolveBasicHit #{$i} attacker");
        expect($target)->toEqual($case['targetAfter'], "resolveBasicHit #{$i} target");
    }
});

it('matches applyEffects (mutuje wiele stanów + RNG stun_chance/instant_kill)', function () {
    foreach ($this->golden['applyEffects'] as $i => $case) {
        $rng = new Mulberry32Rng($case['seed']);
        $caster = $case['caster'];
        $target = $case['target'];
        $party = $case['party'];
        $enemy = $case['enemy'];
        $parsed = SkillEffectsV2::parseEffects($case['effect']);
        $result = SkillEffectsV2::applyEffects($rng, $parsed, $caster, $target, $case['targetHpPct'], $party, $enemy);
        expect($result)->toEqual($case['result'], "applyEffects #{$i} ({$case['effect']}) result");
        expect($caster)->toEqual($case['casterAfter'], "applyEffects #{$i} ({$case['effect']}) caster");
        expect($target)->toEqual($case['targetAfter'], "applyEffects #{$i} ({$case['effect']}) target");
        expect($party)->toEqual($case['partyAfter'], "applyEffects #{$i} ({$case['effect']}) party");
        expect($enemy)->toEqual($case['enemyAfter'], "applyEffects #{$i} ({$case['effect']}) enemy");
    }
});
