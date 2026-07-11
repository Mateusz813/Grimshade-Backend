<?php

declare(strict_types=1);

use App\Domain\Bot\BotSystem;
use App\Domain\Content\ContentRepository;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

/**
 * PARYTET botSystem: PHP BotSystem == TS botSystem.ts. Czyste tabele statów
 * (golden bit-exact z classes.json) + funkcje RNG (ten sam seed mulberry32 co
 * TS → identyczna sekwencja → identyczny wynik). Staty klas i pierwsze skille
 * czytane z resources/game-content (to samo źródło co front src/data).
 *
 * toEqual (nie toBe) — JSON nie rozróżnia int/float (np. speed 2.0 == 2).
 */
beforeEach(function () {
    $this->golden = Golden::load('botSystem.json');
    $content = new ContentRepository(dirname(__DIR__, 2).'/resources/game-content');
    $this->bot = new BotSystem($content->get('classes'), $content->get('skills'));
});

it('matches calculateAoeDamage', function () {
    foreach ($this->golden['calculateAoeDamage'] as $case) {
        expect(BotSystem::calculateAoeDamage($case['bossAttack'], $case['targetDefense']))
            ->toEqual($case['value'], "calculateAoeDamage({$case['bossAttack']},{$case['targetDefense']})");
    }
});

it('matches isBossAoeTurn', function () {
    foreach ($this->golden['isBossAoeTurn'] as $case) {
        expect(BotSystem::isBossAoeTurn($case['turnCounter']))
            ->toEqual($case['value'], "isBossAoeTurn({$case['turnCounter']})");
    }
});

it('matches getAggroSwitchInterval (seeded)', function () {
    foreach ($this->golden['getAggroSwitchInterval'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(BotSystem::getAggroSwitchInterval($rng))
            ->toEqual($case['value'], "getAggroSwitchInterval seed {$case['seed']}");
    }
});

it('matches pickAggroTarget legacy (seeded uniform)', function () {
    foreach ($this->golden['pickAggroTargetLegacy'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(BotSystem::pickAggroTarget($rng, $case['arg']))
            ->toEqual($case['value'], 'pickAggroTarget legacy seed '.$case['seed'].' '.json_encode($case['arg']));
    }
});

it('matches pickAggroTarget weighted (seeded class-weighted)', function () {
    foreach ($this->golden['pickAggroTargetWeighted'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(BotSystem::pickAggroTarget($rng, $case['arg']))
            ->toEqual($case['value'], 'pickAggroTarget weighted seed '.$case['seed'].' '.json_encode($case['arg']));
    }
});

it('matches calculateBotAction (seeded, skill + attack paths)', function () {
    foreach ($this->golden['calculateBotAction'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect($this->bot->calculateBotAction($rng, $case['bot'], $case['bossDefense'], $case['canUseSkill']))
            ->toEqual($case['value'], "calculateBotAction {$case['label']} seed {$case['seed']}");
    }
});

it('matches generateBot (seeded: class/level/name + stats)', function () {
    foreach ($this->golden['generateBot'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect($this->bot->generateBot(
            $rng,
            $case['playerLevel'],
            $case['playerClass'],
            $case['existingClasses'],
            $case['botSeq'],
            $case['nowMs'],
        ))->toEqual($case['value'], "generateBot seed {$case['seed']} lvl {$case['playerLevel']} {$case['playerClass']}");
    }
});

it('matches generateBotWithClass (seeded, full stat table)', function () {
    foreach ($this->golden['generateBotWithClass'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect($this->bot->generateBotWithClass(
            $rng,
            $case['playerLevel'],
            $case['botClass'],
            $case['botSeq'],
            $case['nowMs'],
        ))->toEqual($case['value'], "generateBotWithClass seed {$case['seed']} lvl {$case['playerLevel']} {$case['botClass']}");
    }
});

it('matches generateBotParty (seeded, growing exclusion set)', function () {
    foreach ($this->golden['generateBotParty'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect($this->bot->generateBotParty(
            $rng,
            $case['playerLevel'],
            $case['playerClass'],
            $case['count'],
            $case['startSeq'],
            $case['nowMs'],
        ))->toEqual($case['value'], "generateBotParty seed {$case['seed']} count {$case['count']}");
    }
});
