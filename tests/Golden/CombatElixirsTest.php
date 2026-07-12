<?php

declare(strict_types=1);

use App\Domain\Combat\CombatElixirs;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('combatElixirs.json');
});

it('matches all elixir getters across active-buff scenarios', function () {
    foreach ($this->golden['getters'] as $i => $case) {
        $active = $case['active'];
        $computed = [
            'atkDamageMultiplier' => CombatElixirs::getAtkDamageMultiplier($active),
            'spellDamageMultiplier' => CombatElixirs::getSpellDamageMultiplier($active),
            'hpBonus' => CombatElixirs::getElixirHpBonus($active),
            'mpBonus' => CombatElixirs::getElixirMpBonus($active),
            'hpPctMultiplier' => CombatElixirs::getElixirHpPctMultiplier($active),
            'mpPctMultiplier' => CombatElixirs::getElixirMpPctMultiplier($active),
            'atkBonus' => CombatElixirs::getElixirAtkBonus($active),
            'defBonus' => CombatElixirs::getElixirDefBonus($active),
            'attackSpeedMultiplier' => CombatElixirs::getElixirAttackSpeedMultiplier($active),
        ];
        expect($computed)->toEqual($case['result'], 'getters #'.$i.' '.json_encode($active));
    }
});

it('matches tickCombatElixirs drain (always-drain + highest-tier-first)', function () {
    foreach ($this->golden['tick'] as $i => $case) {
        expect(CombatElixirs::tickCombatElixirs($case['input'], $case['ms']))
            ->toEqual($case['result'], "tickCombatElixirs #{$i} ms {$case['ms']}");
    }
});
