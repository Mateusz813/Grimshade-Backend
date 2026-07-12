<?php

declare(strict_types=1);

use App\Domain\OfflineHunt\OfflineHuntSystem;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('offlineHuntSystem.json');
});

it('matches getOfflineHuntSpeedMultiplier', function () {
    foreach ($this->golden['getOfflineHuntSpeedMultiplier'] as $case) {
        expect(OfflineHuntSystem::getOfflineHuntSpeedMultiplier($case['masteryLevel']))
            ->toEqual($case['value'], "getOfflineHuntSpeedMultiplier lvl {$case['masteryLevel']}");
    }
});

it('matches preview across time/mastery/buff/monster cases', function () {
    foreach ($this->golden['preview'] as $case) {
        expect(OfflineHuntSystem::preview($case['input']))
            ->toEqual($case['result'], 'preview '.json_encode($case['input']));
    }
});

it('matches aggregateClaimRewards across rarity distributions', function () {
    foreach ($this->golden['aggregateClaimRewards'] as $case) {
        expect(OfflineHuntSystem::aggregateClaimRewards($case['input']))
            ->toEqual($case['result'], 'aggregateClaimRewards '.json_encode($case['input']['killsByRarity']));
    }
});

it('matches weightedTaskKills', function () {
    foreach ($this->golden['weightedTaskKills'] as $case) {
        expect(OfflineHuntSystem::weightedTaskKills($case['killsByRarity']))
            ->toEqual($case['value'], 'weightedTaskKills '.json_encode($case['killsByRarity']));
    }
});

it('matches rollKillsByRarity (seeded mulberry32)', function () {
    foreach ($this->golden['rollKillsByRarity'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(OfflineHuntSystem::rollKillsByRarity($rng, $case['kills'], $case['mastery']))
            ->toEqual($case['value'], "rollKillsByRarity seed {$case['seed']} kills {$case['kills']}");
    }
});

it('rollKillsByRarity conserves total kills and rarity keys (property)', function () {
    foreach ($this->golden['rollKillsByRarity'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        $kbr = OfflineHuntSystem::rollKillsByRarity($rng, $case['kills'], $case['mastery']);

        expect(array_keys($kbr))->toEqual(['normal', 'strong', 'epic', 'legendary', 'boss']);
        expect(array_sum($kbr))->toEqual($case['kills'], "suma == kills seed {$case['seed']}");
        foreach ($kbr as $count) {
            expect($count)->toBeGreaterThanOrEqual(0);
        }
    }
});
