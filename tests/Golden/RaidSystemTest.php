<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;
use App\Domain\Raid\RaidSystem;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('raidSystem.json');
    $content = new ContentRepository(dirname(__DIR__, 2).'/resources/game-content');
    $this->raid = new RaidSystem($content->get('dungeons'), $content->get('monsters'));
});

it('matches getRaidWaveCount across level thresholds', function () {
    foreach ($this->golden['getRaidWaveCount'] as $case) {
        expect(RaidSystem::getRaidWaveCount($case['level']))
            ->toEqual($case['value'], "getRaidWaveCount({$case['level']})");
    }
});

it('matches getAllRaids (one raid per dungeon)', function () {
    expect($this->raid->getAllRaids())->toEqual($this->golden['getAllRaids']);
});

it('matches getRaidById (hits + misses)', function () {
    foreach ($this->golden['getRaidById'] as $case) {
        expect($this->raid->getRaidById($case['id']))
            ->toEqual($case['value'], "getRaidById({$case['id']})");
    }
});

it('matches estimateRaidRewards', function () {
    foreach ($this->golden['estimateRaidRewards'] as $case) {
        expect($this->raid->estimateRaidRewards($case['raid']))
            ->toEqual($case['value'], "estimateRaidRewards lvl {$case['raid']['level']}");
    }
});

it('matches generateWaveBosses (stat scaling, id-stripped)', function () {
    foreach ($this->golden['generateWaveBosses'] as $case) {
        expect($this->raid->generateWaveBosses($case['raid'], $case['waveIdx']))
            ->toEqual($case['value'], "generateWaveBosses lvl {$case['raid']['level']} wave {$case['waveIdx']}");
    }
});

it('matches computeMemberRewards (xp + gold)', function () {
    foreach ($this->golden['memberRewards'] as $case) {
        expect($this->raid->computeMemberRewards($case['raid'], $case['bossesDefeated']))
            ->toEqual($case['value'], "computeMemberRewards lvl {$case['raid']['level']} ×{$case['bossesDefeated']}");
    }
});

it('matches selectItemRarity (roll → rarity boundaries)', function () {
    foreach ($this->golden['selectItemRarity'] as $case) {
        expect(RaidSystem::selectItemRarity($case['roll']))
            ->toEqual($case['value'], "selectItemRarity({$case['roll']})");
    }
});

it('matches selectStoneDrop (roll → stone boundaries)', function () {
    foreach ($this->golden['selectStoneDrop'] as $case) {
        expect(RaidSystem::selectStoneDrop($case['roll']))
            ->toEqual($case['value'], "selectStoneDrop({$case['roll']})");
    }
});

it('matches selectCompletionRarity (roll → rarity boundaries)', function () {
    foreach ($this->golden['selectCompletionRarity'] as $case) {
        expect(RaidSystem::selectCompletionRarity($case['roll']))
            ->toEqual($case['value'], "selectCompletionRarity({$case['roll']})");
    }
});

it('matches seeded selectors (mulberry32 float → rarity)', function () {
    foreach ($this->golden['seededSelectors'] as $case) {
        $roll = (new Mulberry32Rng($case['seed']))->nextFloat();
        expect(RaidSystem::selectItemRarity($roll))
            ->toEqual($case['item'], "seeded item seed {$case['seed']}");
        expect(RaidSystem::selectStoneDrop($roll))
            ->toEqual($case['stone'], "seeded stone seed {$case['seed']}");
        expect(RaidSystem::selectCompletionRarity($roll))
            ->toEqual($case['completion'], "seeded completion seed {$case['seed']}");
    }
});

$itemRarities = ['heroic', 'mythic', 'legendary', 'epic', 'rare', 'common'];
$stoneIds = ['heroic_stone', 'mythic_stone', 'legendary_stone', 'epic_stone', 'rare_stone', 'common_stone'];

it('rollMemberDrops is deterministic for a given seed', function () {
    $raid = ['level' => 500, 'waves' => 4];
    $a = $this->raid->rollMemberDrops(new Mulberry32Rng(42), $raid, 16);
    $b = $this->raid->rollMemberDrops(new Mulberry32Rng(42), $raid, 16);
    expect($a)->toEqual($b);
});

it('rollMemberDrops always ends with exactly one completion bonus item', function () {
    foreach ([1, 2, 3, 7, 13, 42, 99, 777] as $seed) {
        $lines = $this->raid->rollMemberDrops(new Mulberry32Rng($seed), ['level' => 200, 'waves' => 3], 12);
        $bonus = array_values(array_filter($lines, static fn (array $l): bool => ($l['isBonus'] ?? false) === true));
        expect(count($bonus))->toBe(1);
        expect($bonus[0]['kind'])->toBe('item');
        expect($bonus[0]['rarity'])->toBeIn(['heroic', 'mythic', 'legendary', 'epic', 'rare', 'common']);
    }
});

it('rollMemberDrops with zero kills yields only the completion bonus', function () {
    $lines = $this->raid->rollMemberDrops(new Mulberry32Rng(7), ['level' => 100, 'waves' => 3], 0);
    expect($lines)->toHaveCount(1);
    expect($lines[0]['isBonus'] ?? false)->toBeTrue();
});

it('rollMemberDrops emits valid rarities, stone ids and eligible chest levels', function () use ($itemRarities, $stoneIds) {
    $raidLevel = 250;
    $eligibleChests = array_values(array_filter(
        RaidSystem::SPELL_CHEST_LEVELS,
        static fn (int $lvl): bool => $lvl <= $raidLevel,
    ));

    foreach ([1, 3, 13, 99, 777] as $seed) {
        $lines = $this->raid->rollMemberDrops(new Mulberry32Rng($seed), ['level' => $raidLevel, 'waves' => 3], 12);
        foreach ($lines as $line) {
            if ($line['kind'] === 'item') {
                expect($line['rarity'])->toBeIn($itemRarities);
            } elseif ($line['kind'] === 'upgrade_stone') {
                expect($line['rarity'])->toBeIn($itemRarities);
                expect($line['itemId'])->toBeIn($stoneIds);
            } elseif ($line['kind'] === 'spell_chest') {
                expect($line['amount'])->toBeIn($eligibleChests);
            }
        }
    }
});

it('rollMemberDrops never emits chest levels above the raid level', function () {
    foreach ([1, 2, 3, 7, 13, 42, 99, 777] as $seed) {
        $lines = $this->raid->rollMemberDrops(new Mulberry32Rng($seed), ['level' => 5, 'waves' => 1], 4);
        foreach ($lines as $line) {
            if ($line['kind'] === 'spell_chest') {
                expect($line['amount'])->toBeLessThanOrEqual(5);
            }
        }
    }
});
