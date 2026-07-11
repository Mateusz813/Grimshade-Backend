<?php

declare(strict_types=1);

use App\Domain\Combat\CombatLeavePenalty;
use App\Domain\Combat\DeathProtection;
use Tests\Support\Golden;

/**
 * PARYTET deathProtection: PHP DeathProtection + CombatLeavePenalty muszą
 * zwrócić DOKŁADNIE to, co TS deathProtection.ts (przez realny inventoryStore)
 * oraz rdzeń combatLeavePenalty.ts. Fixture wygenerowany w grimshade repo,
 * skopiowany tu. toEqual (nie toBe) — JSON nie rozróżnia int/float.
 */
beforeEach(function () {
    $this->golden = Golden::load('deathProtection.json');
});

it('matches hasDeathProtection for every consumables map', function () {
    foreach ($this->golden['hasDeathProtection'] as $case) {
        expect(DeathProtection::hasDeathProtection($case['consumables']))
            ->toEqual($case['value'], 'hasDeathProtection '.json_encode($case['consumables']));
    }
});

it('matches consumeDeathProtection (priorytet + zużycie + zachowane pozycje)', function () {
    foreach ($this->golden['consumeDeathProtection'] as $case) {
        expect(DeathProtection::consumeDeathProtection($case['consumables']))
            ->toEqual($case['result'], 'consumeDeathProtection '.json_encode($case['consumables']));
    }
});

it('matches computeLeavePenalty (pełna kara śmierci, ochrona pominięta)', function () {
    foreach ($this->golden['computeLeavePenalty'] as $case) {
        expect(CombatLeavePenalty::computeLeavePenalty($case['level'], $case['xp'], $case['highestLevel']))
            ->toEqual($case['result'], "computeLeavePenalty({$case['level']},{$case['xp']})");
    }
});
