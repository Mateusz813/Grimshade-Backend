<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;
use App\Domain\Skills\SkillBuffs;
use Tests\Support\Golden;

/**
 * PARYTET skillBuffs: PHP SkillBuffs == TS skillBuffs.ts, na tej samej treści
 * skills.json (getSkillDef) + na czystej matematyce parsowania buffów.
 * toEqual (nie toBe) — JSON nie rozróżnia int/float, więc porównanie luźne
 * (durationMs/healPctPerSec liczone jako float, w fixture jako liczby całk.).
 */
beforeEach(function () {
    $this->golden = Golden::load('skillBuffs.json');
    $content = new ContentRepository(dirname(__DIR__, 2).'/resources/game-content');
    $this->buffs = new SkillBuffs($content->get('skills'));
});

it('matches chargeBuffEffectKey (stały protokół skill_charge_<head>)', function () {
    foreach ($this->golden['chargeBuffEffectKey'] as $case) {
        expect(SkillBuffs::chargeBuffEffectKey($case['head']))
            ->toEqual($case['value'], "chargeBuffEffectKey({$case['head']})");
    }
});

it('matches getSkillDef lookup (present + missing ids)', function () {
    foreach ($this->golden['getSkillDef'] as $case) {
        expect($this->buffs->getSkillDef($case['skillId']))
            ->toEqual($case['value'], "getSkillDef({$case['skillId']})");
    }
});

it('matches applySkillBuff op-log (charge + timed + ignored + brzegowe)', function () {
    foreach ($this->golden['applySkillBuff'] as $case) {
        expect(SkillBuffs::applySkillBuff($case['skillId'], $case['effect']))
            ->toEqual($case['ops'], "applySkillBuff({$case['skillId']})");
    }
});
