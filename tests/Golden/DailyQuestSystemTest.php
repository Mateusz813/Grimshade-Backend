<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;
use App\Domain\Progression\DailyQuestSystem;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('dailyQuestSystem.json');
    $content = new ContentRepository(dirname(__DIR__, 2).'/resources/game-content');
    $this->allQuests = $content->get('dailyQuests');
});

it('exposes DAILY_QUEST_COUNT matching TS', function () {
    expect(DailyQuestSystem::DAILY_QUEST_COUNT)
        ->toEqual($this->golden['constants']['DAILY_QUEST_COUNT']);
});

it('matches todayKey format for representative dates', function () {
    foreach ($this->golden['todayKey'] as $case) {
        expect(DailyQuestSystem::todayKey($case['year'], $case['month'], $case['day']))
            ->toEqual($case['value'], "todayKey({$case['year']},{$case['month']},{$case['day']})");
    }
});

it('matches needsRefresh (null/empty/same/diff)', function () {
    foreach ($this->golden['needsRefresh'] as $case) {
        expect(DailyQuestSystem::needsRefresh($case['last'], $case['today']))
            ->toEqual($case['value'], 'needsRefresh '.json_encode($case['last']).' vs '.$case['today']);
    }
});

it('matches scaleRewards (floor + elixir passthrough)', function () {
    foreach ($this->golden['scaleRewards'] as $case) {
        expect(DailyQuestSystem::scaleRewards($case['base'], $case['playerLevel']))
            ->toEqual($case['value'], "scaleRewards lvl {$case['playerLevel']}");
    }
});

it('matches selectDailyQuests (date-seeded deterministic shuffle)', function () {
    foreach ($this->golden['selectDailyQuests'] as $case) {
        $selected = DailyQuestSystem::selectDailyQuests(
            $this->allQuests,
            $case['playerLevel'],
            $case['today'],
        );

        $ids = array_map(static fn (array $q): string => $q['id'], $selected);
        expect($ids)
            ->toEqual($case['ids'], "selectDailyQuests ids lvl {$case['playerLevel']} @ {$case['today']}");
        expect($selected)
            ->toEqual($case['result'], "selectDailyQuests result lvl {$case['playerLevel']} @ {$case['today']}");
    }
});
