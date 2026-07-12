<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;
use App\Domain\Progression\TaskRewards;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('taskRewards.json');
    $content = new ContentRepository(dirname(__DIR__, 2).'/resources/game-content');
    $this->rewards = new TaskRewards($content->get('monsters'));
});

it('matches getEffectiveTaskXpPerKill (native + geometric override)', function () {
    foreach ($this->golden['getEffectiveTaskXpPerKill'] as $case) {
        expect($this->rewards->getEffectiveTaskXpPerKill($case['monster']))
            ->toEqual($case['value'], "getEffectiveTaskXpPerKill lvl {$case['monster']['level']}");
    }
});

it('matches computeTaskRewards across monsters and kill counts', function () {
    foreach ($this->golden['computeTaskRewards'] as $case) {
        expect($this->rewards->computeTaskRewards($case['monster'], $case['kills']))
            ->toEqual($case['result'], "computeTaskRewards lvl {$case['monster']['level']} ×{$case['kills']}");
    }
});
