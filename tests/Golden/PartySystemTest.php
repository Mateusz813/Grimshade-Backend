<?php

declare(strict_types=1);

use App\Domain\Party\PartySystem;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

/**
 * PARYTET partySystem: PHP PartySystem musi zwrócić DOKŁADNIE to co TS
 * partySystem.ts (fixture wygenerowany w grimshade repo, skopiowany tu).
 * Deterministyczne formuły + pickWeightedAggroTarget (seed mulberry32 →
 * identyczna sekwencja → identyczny wynik).
 *
 * toEqual (nie toBe) — JSON nie rozróżnia int/float, porównanie luźne.
 */
beforeEach(function () {
    $this->golden = Golden::load('partySystem.json');
});

it('has MAX_PARTY_SIZE 4', function () {
    expect(PartySystem::MAX_PARTY_SIZE)->toEqual($this->golden['maxPartySize']);
});

it('matches calculateDropMultiplier', function () {
    foreach ($this->golden['calculateDropMultiplier'] as $case) {
        expect(PartySystem::calculateDropMultiplier($case['size']))
            ->toEqual($case['value'], "calculateDropMultiplier({$case['size']})");
    }
});

it('matches calculateXpMultiplier', function () {
    foreach ($this->golden['calculateXpMultiplier'] as $case) {
        expect(PartySystem::calculateXpMultiplier($case['size']))
            ->toEqual($case['value'], "calculateXpMultiplier({$case['size']})");
    }
});

it('matches calculateDifficultyMultiplier', function () {
    foreach ($this->golden['calculateDifficultyMultiplier'] as $case) {
        expect(PartySystem::calculateDifficultyMultiplier($case['size']))
            ->toEqual($case['value'], "calculateDifficultyMultiplier({$case['size']})");
    }
});

it('matches canJoinParty', function () {
    foreach ($this->golden['canJoinParty'] as $case) {
        expect(PartySystem::canJoinParty($case['size']))
            ->toEqual($case['value'], "canJoinParty({$case['size']})");
    }
});

it('matches isFull', function () {
    foreach ($this->golden['isFull'] as $case) {
        expect(PartySystem::isFull($case['members']))
            ->toEqual($case['value'], "isFull(size {$case['size']})");
    }
});

it('matches getHumanCount', function () {
    foreach ($this->golden['getHumanCount'] as $case) {
        expect(PartySystem::getHumanCount($case['members']))
            ->toEqual($case['value'], "getHumanCount({$case['label']})");
    }
});

it('matches getBotCount', function () {
    foreach ($this->golden['getBotCount'] as $case) {
        expect(PartySystem::getBotCount($case['members']))
            ->toEqual($case['value'], "getBotCount({$case['label']})");
    }
});

it('matches shouldSuggestBot', function () {
    foreach ($this->golden['shouldSuggestBot'] as $case) {
        expect(PartySystem::shouldSuggestBot($case['members']))
            ->toEqual($case['value'], "shouldSuggestBot({$case['label']})");
    }
});

it('matches createBotHelper (deterministic contract, no id)', function () {
    foreach ($this->golden['createBotHelper'] as $case) {
        expect(PartySystem::createBotHelper($case['members']))
            ->toEqual($case['value'], "createBotHelper({$case['label']})");
    }
});

it('matches getXpShare', function () {
    foreach ($this->golden['getXpShare'] as $case) {
        expect(PartySystem::getXpShare($case['total'], $case['size']))
            ->toEqual($case['value'], "getXpShare({$case['total']},{$case['size']})");
    }
});

it('matches getGoldShare', function () {
    foreach ($this->golden['getGoldShare'] as $case) {
        expect(PartySystem::getGoldShare($case['total'], $case['size']))
            ->toEqual($case['value'], "getGoldShare({$case['total']},{$case['size']})");
    }
});

it('matches getPartySummary', function () {
    foreach ($this->golden['getPartySummary'] as $case) {
        expect(PartySystem::getPartySummary($case['members']))
            ->toEqual($case['value'], "getPartySummary({$case['label']})");
    }
});

it('matches calculateHelpDamage', function () {
    foreach ($this->golden['calculateHelpDamage'] as $case) {
        expect(PartySystem::calculateHelpDamage($case['attack'], $case['remainingHp']))
            ->toEqual($case['value'], "calculateHelpDamage({$case['attack']})");
    }
});

it('matches getPartyBuffs', function () {
    foreach ($this->golden['getPartyBuffs'] as $case) {
        expect(PartySystem::getPartyBuffs($case['classes']))
            ->toEqual($case['value'], "getPartyBuffs({$case['label']})");
    }
});

it('matches hasOptimalComposition', function () {
    foreach ($this->golden['hasOptimalComposition'] as $case) {
        expect(PartySystem::hasOptimalComposition($case['classes']))
            ->toEqual($case['value'], "hasOptimalComposition({$case['label']})");
    }
});

it('matches getCompositionBonus', function () {
    foreach ($this->golden['getCompositionBonus'] as $case) {
        expect(PartySystem::getCompositionBonus($case['classes']))
            ->toEqual($case['value'], "getCompositionBonus({$case['label']})");
    }
});

it('matches applyPartyBuffs', function () {
    foreach ($this->golden['applyPartyBuffs'] as $case) {
        expect(PartySystem::applyPartyBuffs($case['baseAttack'], $case['baseDefense'], $case['maxHp'], $case['buffs']))
            ->toEqual($case['value'], "applyPartyBuffs({$case['label']})");
    }
});

it('matches getPartyGateLevel', function () {
    foreach ($this->golden['getPartyGateLevel'] as $case) {
        expect(PartySystem::getPartyGateLevel($case['myLevel'], $case['members']))
            ->toEqual($case['value'], "getPartyGateLevel({$case['label']})");
    }
});

it('matches getPartyMaxUnlockedMonsterLevel', function () {
    foreach ($this->golden['getPartyMaxUnlockedMonsterLevel'] as $case) {
        expect(PartySystem::getPartyMaxUnlockedMonsterLevel($case['myMax'], $case['members'], $case['presence'], $case['myId']))
            ->toEqual($case['value'], "getPartyMaxUnlockedMonsterLevel({$case['label']})");
    }
});

it('matches getAggroWeight (incl. unknown-class fallback 30)', function () {
    foreach ($this->golden['getAggroWeight'] as $case) {
        expect(PartySystem::getAggroWeight($case['class']))
            ->toEqual($case['value'], "getAggroWeight({$case['class']})");
    }
});

it('matches pickWeightedAggroTarget (seeded)', function () {
    foreach ($this->golden['pickWeightedAggroTarget'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(PartySystem::pickWeightedAggroTarget($rng, $case['targets']))
            ->toEqual($case['value'], "pickWeightedAggroTarget seed {$case['seed']} {$case['label']}");
    }
});
