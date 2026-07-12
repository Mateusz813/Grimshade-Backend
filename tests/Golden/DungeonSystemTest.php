<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;
use App\Domain\Dungeon\DungeonSystem;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('dungeonSystem.json');
    $this->monsters = $this->golden['monsters'];
    $this->itemTemplates = (new ContentRepository(dirname(__DIR__, 2).'/resources/game-content'))->get('itemTemplates');
});

it('exposes matching constants', function () {
    expect(DungeonSystem::DUNGEON_RARITY_ORDER)
        ->toEqual($this->golden['constants']['DUNGEON_RARITY_ORDER']);
    expect(DungeonSystem::DUNGEON_MONSTER_TYPE_MULTIPLIERS)
        ->toEqual($this->golden['constants']['DUNGEON_MONSTER_TYPE_MULTIPLIERS']);
});

it('matches dungeon helper getters (minLevel/waves/cooldown/rewardGold/rewardXp)', function () {
    foreach ($this->golden['getDungeonMinLevel'] as $c) {
        expect(DungeonSystem::getDungeonMinLevel($c['dungeon']))
            ->toEqual($c['value'], "getDungeonMinLevel {$c['dungeon']['id']}");
    }
    foreach ($this->golden['getDungeonWaves'] as $c) {
        expect(DungeonSystem::getDungeonWaves($c['dungeon']))
            ->toEqual($c['value'], "getDungeonWaves {$c['dungeon']['id']}");
    }
    foreach ($this->golden['getDungeonCooldown'] as $c) {
        expect(DungeonSystem::getDungeonCooldown($c['dungeon']))
            ->toEqual($c['value'], "getDungeonCooldown {$c['dungeon']['id']}");
    }
    foreach ($this->golden['getDungeonRewardGold'] as $c) {
        expect(DungeonSystem::getDungeonRewardGold($c['dungeon']))
            ->toEqual($c['value'], "getDungeonRewardGold {$c['dungeon']['id']}");
    }
    foreach ($this->golden['getDungeonRewardXp'] as $c) {
        expect(DungeonSystem::getDungeonRewardXp($c['dungeon']))
            ->toEqual($c['value'], "getDungeonRewardXp {$c['dungeon']['id']}");
    }
});

it('matches canEnterDungeon (min-level + cooldown, czas parametryzowany)', function () {
    foreach ($this->golden['canEnterDungeon'] as $c) {
        expect(DungeonSystem::canEnterDungeon($c['dungeon'], $c['characterLevel'], $c['lastCompletedAt'], $c['nowMs']))
            ->toEqual($c['value'], "canEnterDungeon {$c['dungeon']['id']} lvl {$c['characterLevel']}");
    }
});

it('matches getDungeonRemainingMs', function () {
    foreach ($this->golden['getDungeonRemainingMs'] as $c) {
        expect(DungeonSystem::getDungeonRemainingMs($c['dungeon'], $c['lastCompletedAt'], $c['nowMs']))
            ->toEqual($c['value'], "getDungeonRemainingMs {$c['dungeon']['id']}");
    }
});

it('matches formatCooldown', function () {
    foreach ($this->golden['formatCooldown'] as $c) {
        expect(DungeonSystem::formatCooldown($c['ms']))->toEqual($c['value'], "formatCooldown {$c['ms']}");
    }
});

it('matches getFinalWaveMonsterType', function () {
    foreach ($this->golden['getFinalWaveMonsterType'] as $c) {
        expect(DungeonSystem::getFinalWaveMonsterType($c['dungeonLevel']))
            ->toEqual($c['value'], "getFinalWaveMonsterType {$c['dungeonLevel']}");
    }
});

it('matches getMidWaveMonsterType', function () {
    foreach ($this->golden['getMidWaveMonsterType'] as $c) {
        expect(DungeonSystem::getMidWaveMonsterType($c['dungeonLevel'], $c['wave'], $c['totalWaves']))
            ->toEqual($c['value'], "getMidWaveMonsterType lvl{$c['dungeonLevel']} w{$c['wave']}/{$c['totalWaves']}");
    }
});

it('matches getWaveMonsterType', function () {
    foreach ($this->golden['getWaveMonsterType'] as $c) {
        expect(DungeonSystem::getWaveMonsterType($c['wave'], $c['totalWaves'], $c['dungeonLevel']))
            ->toEqual($c['value'], "getWaveMonsterType lvl{$c['dungeonLevel']} w{$c['wave']}/{$c['totalWaves']}");
    }
});

it('matches getWaveMonsterCount', function () {
    foreach ($this->golden['getWaveMonsterCount'] as $c) {
        expect(DungeonSystem::getWaveMonsterCount($c['dungeonLevel'], $c['wave'], $c['totalWaves']))
            ->toEqual($c['value'], "getWaveMonsterCount lvl{$c['dungeonLevel']} w{$c['wave']}/{$c['totalWaves']}");
    }
});

it('matches getWaveComposition', function () {
    foreach ($this->golden['getWaveComposition'] as $c) {
        expect(DungeonSystem::getWaveComposition($c['dungeonLevel'], $c['wave'], $c['totalWaves']))
            ->toEqual($c['value'], "getWaveComposition lvl{$c['dungeonLevel']} w{$c['wave']}/{$c['totalWaves']}");
    }
});

it('matches pickWaveMonster (deterministyczny sort po poziomie + explicit picks)', function () {
    foreach ($this->golden['pickWaveMonster'] as $c) {
        expect(DungeonSystem::pickWaveMonster($c['dungeon'], $this->monsters, $c['wave'], $c['totalWaves']))
            ->toEqual($c['value'], "pickWaveMonster {$c['dungeon']['id']} wave {$c['wave']}");
    }
});

it('matches pickWaveMonsters (lead + eskorty)', function () {
    foreach ($this->golden['pickWaveMonsters'] as $c) {
        expect(DungeonSystem::pickWaveMonsters($c['dungeon'], $this->monsters, $c['wave'], $c['totalWaves']))
            ->toEqual($c['value'], "pickWaveMonsters {$c['dungeon']['id']} wave {$c['wave']}");
    }
});

it('matches scaleDungeonMonster (tiery 1-8/9-18/20+ × mnożniki typu)', function () {
    foreach ($this->golden['scaleDungeonMonster'] as $c) {
        expect(DungeonSystem::scaleDungeonMonster($c['monster'], $c['wave'], $c['totalWaves'], $c['dungeonLevel']))
            ->toEqual($c['value'], "scaleDungeonMonster {$c['monster']['id']} lvl{$c['dungeonLevel']} w{$c['wave']}/{$c['totalWaves']}");
    }
});

it('matches scaleDungeonMonsterAsType (re-baza eskort)', function () {
    foreach ($this->golden['scaleDungeonMonsterAsType'] as $c) {
        expect(DungeonSystem::scaleDungeonMonsterAsType($c['monster'], $c['wave'], $c['totalWaves'], $c['dungeonLevel'], $c['asType']))
            ->toEqual($c['value'], "scaleDungeonMonsterAsType {$c['asType']} lvl{$c['dungeonLevel']} w{$c['wave']}/{$c['totalWaves']}");
    }
});

it('matches resolveWave (deterministyczna symulacja)', function () {
    foreach ($this->golden['resolveWave'] as $c) {
        expect(DungeonSystem::resolveWave($c['playerHp'], $c['playerAtk'], $c['playerDef'], $c['monsterHp'], $c['monsterAtk'], $c['monsterDef']))
            ->toEqual($c['value'], "resolveWave hp{$c['playerHp']} vs hp{$c['monsterHp']}");
    }
});

it('matches estimateDungeonRewards (spawny × 4 + bonus poziomu)', function () {
    foreach ($this->golden['estimateDungeonRewards'] as $c) {
        expect(DungeonSystem::estimateDungeonRewards($c['dungeon'], $this->monsters, $c['monstersRaw']))
            ->toEqual($c['value'], "estimateDungeonRewards {$c['dungeon']['id']}");
    }
});

it('matches rollDungeonRarity (seeded, 1 rzut, capowany maxRarity)', function () {
    foreach ($this->golden['rollDungeonRarity'] as $c) {
        $rng = new Mulberry32Rng($c['seed']);
        expect(DungeonSystem::rollDungeonRarity($rng, $c['maxRarity']))
            ->toEqual($c['value'], "rollDungeonRarity {$c['maxRarity']} seed {$c['seed']}");
    }
});

it('matches rollDungeonGold (seeded, 1 rzut)', function () {
    foreach ($this->golden['rollDungeonGold'] as $c) {
        $rng = new Mulberry32Rng($c['seed']);
        expect(DungeonSystem::rollDungeonGold($rng, $c['range']))
            ->toEqual($c['value'], "rollDungeonGold [{$c['range'][0]},{$c['range'][1]}] seed {$c['seed']}");
    }
});

it('matches rollDungeonItemDrop (common → bit-parity przez ItemGenerator)', function () {
    foreach ($this->golden['rollDungeonItemDrop'] as $c) {
        $rng = new Mulberry32Rng($c['seed']);
        $items = new ItemGenerator($this->itemTemplates, $rng);
        expect(DungeonSystem::rollDungeonItemDrop($rng, $items, $c['dungeon'], $c['isBossWave']))
            ->toEqual($c['value'], "rollDungeonItemDrop {$c['dungeon']['id']} seed {$c['seed']} boss ".($c['isBossWave'] ? '1' : '0'));
    }
});

it('matches resolveDungeon (common → pełna symulacja bit-parity)', function () {
    foreach ($this->golden['resolveDungeon'] as $c) {
        $rng = new Mulberry32Rng($c['seed']);
        $items = new ItemGenerator($this->itemTemplates, $rng);
        expect(DungeonSystem::resolveDungeon($c['dungeon'], $c['character'], $this->monsters, $rng, $items))
            ->toEqual($c['value'], "resolveDungeon {$c['label']} seed {$c['seed']}");
    }
});

it('rollDungeonItemDrop honours rarity cap + item level for epic dungeons (property)', function () {
    $dungeon = ['id' => 'ep', 'name_pl' => '', 'name_en' => '', 'level' => 50, 'maxRarity' => 'epic', 'description_pl' => ''];
    $order = DungeonSystem::DUNGEON_RARITY_ORDER;
    $epicIdx = array_search('epic', $order, true);

    foreach ([1, 42, 777] as $seed) {
        $rng = new Mulberry32Rng($seed);
        $items = new ItemGenerator($this->itemTemplates, $rng);
        for ($k = 0; $k < 60; $k++) {
            $drop = DungeonSystem::rollDungeonItemDrop($rng, $items, $dungeon, true);
            if ($drop === null) {
                continue;
            }
            expect($drop['itemLevel'])->toBe(50);
            expect(array_search($drop['rarity'], $order, true))->toBeLessThanOrEqual($epicIdx);
        }
    }
});

it('resolveDungeon stays structurally valid for rare+ dungeons (property, no bit-parity)', function () {
    $dungeon = ['id' => 'ep8', 'name_pl' => '', 'name_en' => '', 'level' => 8, 'maxRarity' => 'epic', 'description_pl' => '', 'dailyAttempts' => 5];
    $character = ['attack' => 99999, 'defense' => 9999, 'max_hp' => 10_000_000, 'level' => 50];

    foreach ([1, 42, 777] as $seed) {
        $rng = new Mulberry32Rng($seed);
        $items = new ItemGenerator($this->itemTemplates, $rng);
        $out = DungeonSystem::resolveDungeon($dungeon, $character, $this->monsters, $rng, $items);

        $totalWaves = DungeonSystem::getDungeonWaves($dungeon);
        expect($out['result']['success'])->toBeTrue()
            ->and($out['result']['wavesCleared'])->toBe($totalWaves)
            ->and(count($out['waveResults']))->toBe($totalWaves);

        foreach ($out['result']['items'] as $item) {
            expect($item['itemLevel'])->toBe(8);
        }

        [$goldMin, $goldMax] = DungeonSystem::getDungeonRewardGold($dungeon);
        expect($out['result']['gold'])->toBeGreaterThanOrEqual($goldMin)
            ->and($out['result']['gold'])->toBeLessThanOrEqual($goldMax);
    }
});
