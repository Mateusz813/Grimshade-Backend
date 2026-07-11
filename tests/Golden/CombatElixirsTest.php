<?php

declare(strict_types=1);

use App\Domain\Combat\CombatElixirs;
use Tests\Support\Golden;

/**
 * PARYTET combatElixirs: PHP CombatElixirs == TS combatElixirs.ts.
 *  - gettery: stan = lista aktywnych efektów (hasBuff == in_array),
 *  - tickCombatElixirs: stan = mapa effect => remainingMs (pausable) + ms.
 * Wektory generowane z TS przez ustawienie stanu buffStore i wywołanie
 * realnych funkcji — patrz tests/integration/combatElixirs.golden.test.ts.
 */
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
