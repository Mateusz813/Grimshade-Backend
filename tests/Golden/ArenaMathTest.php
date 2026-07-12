<?php

declare(strict_types=1);

use App\Domain\Arena\ArenaMath;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('arenaSystem.json');
});

it('matches getLeagueMultiplier', function () {
    foreach ($this->golden['getLeagueMultiplier'] as $case) {
        expect(ArenaMath::getLeagueMultiplier($case['league']))->toEqual($case['value']);
    }
});

it('matches getNextLeague / getPreviousLeague', function () {
    foreach ($this->golden['getNextLeague'] as $case) {
        expect(ArenaMath::getNextLeague($case['league']))->toEqual($case['value']);
    }
    foreach ($this->golden['getPreviousLeague'] as $case) {
        expect(ArenaMath::getPreviousLeague($case['league']))->toEqual($case['value']);
    }
});

it('matches getMatchReward', function () {
    foreach ($this->golden['getMatchReward'] as $case) {
        expect(ArenaMath::getMatchReward($case['won'], $case['higher']))
            ->toEqual($case['result'], 'getMatchReward '.json_encode([$case['won'], $case['higher']]));
    }
});

it('matches getSeasonOutcome (promote/stay/relegate)', function () {
    foreach ($this->golden['getSeasonOutcome'] as $case) {
        expect(ArenaMath::getSeasonOutcome($case['league'], $case['rank']))
            ->toEqual($case['result'], "getSeasonOutcome({$case['league']},{$case['rank']})");
    }
});

it('matches findRewardBucket (incl. out-of-range null)', function () {
    foreach ($this->golden['findRewardBucket'] as $case) {
        expect(ArenaMath::findRewardBucket($case['rank']))
            ->toEqual($case['result'], "findRewardBucket({$case['rank']})");
    }
});

it('matches applyLeagueMultiplier', function () {
    foreach ($this->golden['applyLeagueMultiplier'] as $case) {
        expect(ArenaMath::applyLeagueMultiplier(ArenaMath::findRewardBucket(1), $case['league']))
            ->toEqual($case['result'], "applyLeagueMultiplier({$case['league']})");
    }
});
