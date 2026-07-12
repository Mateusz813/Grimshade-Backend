<?php

declare(strict_types=1);

use App\Domain\Progression\LevelSystem;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('levelSystem.json');
});

it('matches xpToNextLevel for every level', function () {
    foreach ($this->golden['xpToNextLevel'] as $case) {
        expect(LevelSystem::xpToNextLevel($case['level']))
            ->toEqual($case['value'], "xpToNextLevel({$case['level']})");
    }
});

it('matches totalXpForLevel', function () {
    foreach ($this->golden['totalXpForLevel'] as $case) {
        expect(LevelSystem::totalXpForLevel($case['level']))
            ->toEqual($case['value'], "totalXpForLevel({$case['level']})");
    }
});

it('matches statPointsForLevelUp for every class', function () {
    foreach ($this->golden['statPointsForLevelUp'] as $case) {
        $class = $case['class'] === '' ? null : $case['class'];
        expect(LevelSystem::statPointsForLevelUp($class))->toEqual($case['value']);
    }
});

it('matches processXpGain (incl. multi-levelup)', function () {
    foreach ($this->golden['processXpGain'] as $case) {
        expect(LevelSystem::processXpGain($case['level'], $case['xp'], $case['gained']))
            ->toEqual($case['result'], "processXpGain({$case['level']},{$case['xp']},{$case['gained']})");
    }
});

it('matches getDeathLossLevels and getFleeLossLevels', function () {
    foreach ($this->golden['getDeathLossLevels'] as $case) {
        expect(LevelSystem::getDeathLossLevels($case['level']))->toEqual($case['value']);
    }
    foreach ($this->golden['getFleeLossLevels'] as $case) {
        expect(LevelSystem::getFleeLossLevels($case['level']))->toEqual($case['value']);
    }
});

it('matches losesItemsOnDeath', function () {
    foreach ($this->golden['losesItemsOnDeath'] as $case) {
        expect(LevelSystem::losesItemsOnDeath($case['level']))->toEqual($case['value']);
    }
});

it('matches applyDeathPenalty', function () {
    foreach ($this->golden['applyDeathPenalty'] as $case) {
        expect(LevelSystem::applyDeathPenalty($case['level'], $case['xp']))
            ->toEqual($case['result'], "applyDeathPenalty({$case['level']},{$case['xp']})");
    }
});

it('matches applyFleePenalty', function () {
    foreach ($this->golden['applyFleePenalty'] as $case) {
        expect(LevelSystem::applyFleePenalty($case['level'], $case['xp']))
            ->toEqual($case['result'], "applyFleePenalty({$case['level']},{$case['xp']})");
    }
});

it('matches applyDeathXpPenalty (legacy)', function () {
    foreach ($this->golden['applyDeathXpPenalty'] as $case) {
        expect(LevelSystem::applyDeathXpPenalty($case['xp'], $case['level']))
            ->toEqual($case['value']);
    }
});

it('matches xpProgress', function () {
    foreach ($this->golden['xpProgress'] as $case) {
        expect(LevelSystem::xpProgress($case['xp'], $case['level']))
            ->toEqual($case['value'], "xpProgress({$case['xp']},{$case['level']})");
    }
});
