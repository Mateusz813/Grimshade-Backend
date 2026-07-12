<?php

declare(strict_types=1);

use App\Domain\Boss\BossSystem;
use App\Domain\Content\ContentRepository;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('bossSystem.json');
    $content = new ContentRepository(dirname(__DIR__, 2).'/resources/game-content');
    $this->bosses = [];
    foreach ($content->get('bosses') as $b) {
        $this->bosses[$b['id']] = $b;
    }
});

it('exposes the same balance multipliers', function () {
    $c = $this->golden['constants'];
    expect(BossSystem::BOSS_HP_MULTIPLIER)->toEqual($c['hpMultiplier']);
    expect(BossSystem::BOSS_ATK_MULTIPLIER)->toEqual($c['atkMultiplier']);
    expect(BossSystem::BOSS_DEF_MULTIPLIER)->toEqual($c['defMultiplier']);
    expect(BossSystem::BOSS_REWARD_MULTIPLIER)->toEqual($c['rewardMultiplier']);
});

it('matches getScaledBossStats for real bosses', function () {
    foreach ($this->golden['getScaledBossStats'] as $case) {
        expect(BossSystem::getScaledBossStats($this->bosses[$case['bossId']]))
            ->toEqual($case['value'], "getScaledBossStats {$case['bossId']}");
    }
});

it('matches getScaledBossStats for synthetic edge bosses', function () {
    foreach ($this->golden['getScaledBossStatsSynthetic'] as $case) {
        expect(BossSystem::getScaledBossStats($case['boss']))
            ->toEqual($case['value'], "getScaledBossStats {$case['boss']['id']}");
    }
});

it('matches getBossDrops (real + synthetic uniqueDrops/dropTable branches)', function () {
    foreach ($this->golden['getBossDrops'] as $case) {
        expect(BossSystem::getBossDrops($this->bosses[$case['bossId']]))
            ->toEqual($case['value'], "getBossDrops {$case['bossId']}");
    }
    foreach ($this->golden['getBossDropsSynthetic'] as $case) {
        expect(BossSystem::getBossDrops($case['boss']))
            ->toEqual($case['value'], "getBossDrops {$case['boss']['id']}");
    }
});

it('matches getBossCooldown (real + nullish/falsy fallback branches)', function () {
    foreach ($this->golden['getBossCooldown'] as $case) {
        expect(BossSystem::getBossCooldown($this->bosses[$case['bossId']]))
            ->toEqual($case['value'], "getBossCooldown {$case['bossId']}");
    }
    foreach ($this->golden['getBossCooldownSynthetic'] as $case) {
        expect(BossSystem::getBossCooldown($case['boss']))
            ->toEqual($case['value'], "getBossCooldown {$case['boss']['id']}");
    }
});

it('matches getBossPhaseMultiplier around the enrage threshold', function () {
    foreach ($this->golden['getBossPhaseMultiplier'] as $case) {
        expect(BossSystem::getBossPhaseMultiplier($case['fraction']))
            ->toEqual($case['value'], "getBossPhaseMultiplier {$case['fraction']}");
    }
});

it('matches isBossEnraged', function () {
    foreach ($this->golden['isBossEnraged'] as $case) {
        expect(BossSystem::isBossEnraged($case['currentHp'], $case['maxHp']))
            ->toEqual($case['value'], "isBossEnraged {$case['currentHp']}/{$case['maxHp']}");
    }
});

it('matches computeBossRewards across boundary levels', function () {
    foreach ($this->golden['computeBossRewards'] as $case) {
        expect(BossSystem::computeBossRewards($case['level']))
            ->toEqual($case['value'], "computeBossRewards L{$case['level']}");
    }
});

it('matches getBossGoldRange for real bosses', function () {
    foreach ($this->golden['getBossGoldRange'] as $case) {
        expect(BossSystem::getBossGoldRange($this->bosses[$case['bossId']]))
            ->toEqual($case['value'], "getBossGoldRange {$case['bossId']}");
    }
});

it('matches getBossXp for real bosses', function () {
    foreach ($this->golden['getBossXp'] as $case) {
        expect(BossSystem::getBossXp($this->bosses[$case['bossId']]))
            ->toEqual($case['value'], "getBossXp {$case['bossId']}");
    }
});

it('matches getBossRecommendedLevel for real bosses', function () {
    foreach ($this->golden['getBossRecommendedLevel'] as $case) {
        expect(BossSystem::getBossRecommendedLevel($this->bosses[$case['bossId']]))
            ->toEqual($case['value'], "getBossRecommendedLevel {$case['bossId']}");
    }
});

it('matches canChallengeBoss (level gate + cooldown, ms-parametrized)', function () {
    foreach ($this->golden['canChallengeBoss'] as $case) {
        expect(BossSystem::canChallengeBoss(
            $this->bosses[$case['bossId']],
            $case['characterLevel'],
            $case['lastMs'],
            $case['nowMs'],
        ))->toEqual($case['value'], "canChallengeBoss {$case['bossId']} lvl {$case['characterLevel']}");
    }
});

it('matches getBossRemainingMs (ms-parametrized)', function () {
    foreach ($this->golden['getBossRemainingMs'] as $case) {
        expect(BossSystem::getBossRemainingMs(
            $this->bosses[$case['bossId']],
            $case['lastMs'],
            $case['nowMs'],
        ))->toEqual($case['value'], "getBossRemainingMs {$case['bossId']} now {$case['nowMs']}");
    }
});

it('matches rollBossGold (seeded)', function () {
    foreach ($this->golden['rollBossGold'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(BossSystem::rollBossGold($rng, $this->bosses[$case['bossId']]))
            ->toEqual($case['value'], "rollBossGold {$case['bossId']} seed {$case['seed']}");
    }
});

it('matches rollBossLoot (seeded, 1 roll per drop entry)', function () {
    foreach ($this->golden['rollBossLoot'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(BossSystem::rollBossLoot($rng, $this->bosses[$case['bossId']]))
            ->toEqual($case['value'], "rollBossLoot {$case['bossId']} seed {$case['seed']}");
    }
});

it('matches resolveBoss (deterministic combat + seeded loot/gold, win & loss)', function () {
    foreach ($this->golden['resolveBoss'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(BossSystem::resolveBoss($rng, $this->bosses[$case['bossId']], $case['character']))
            ->toEqual($case['result'], "resolveBoss {$case['bossId']} seed {$case['seed']}");
    }
});
