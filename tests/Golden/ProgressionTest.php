<?php

declare(strict_types=1);

use App\Domain\Progression\Progression;
use Tests\Support\Golden;

/** PARYTET progression: PHP Progression == TS progression.ts (bramkowanie). */
it('matches getMonsterUnlockStatus (level + mastery gate)', function () {
    $golden = Golden::load('progression.json');
    // Ta sama lista co generator TS.
    $monsters = [
        ['id' => 'rat', 'level' => 1],
        ['id' => 'wolf', 'level' => 5],
        ['id' => 'bear', 'level' => 10],
    ];
    $byId = collect($monsters)->keyBy('id');

    foreach ($golden['getUnlockState'] as $case) {
        $monster = $byId[$case['monsterId']];
        $actual = Progression::getUnlockState($monster, $monsters, $case['characterLevel'], $case['masteries']);
        expect($actual)->toEqual($case['result'], "progression: {$case['label']}");
    }
});
