<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const DQ_USER = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
const DQ_USER_B = 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee';

function dqToken(): string
{
    return TokenFactory::forUser(DQ_USER);
}

function dqChar(int $level = 10): Character
{
    return Character::factory()->forUser(DQ_USER)->create([
        'level' => $level, 'xp' => 0, 'stat_points' => 0, 'quests_daily_done' => 0,
    ]);
}

function dqSave(Character $c, array $dailyQuests, int $gold = 0): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => ['gold' => $gold, 'bag' => [], 'equipment' => [], 'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0],
            'dailyQuests' => $dailyQuests,
        ],
    ]);
}

function dqCompletedSlice(): array
{
    return [
        'lastRefreshDate' => '2026-07-09',
        'activeQuests' => [['questId' => 'daily_kill_5', 'progress' => 5, 'completed' => true, 'claimed' => false]],
        'todayQuestDefs' => [[
            'id' => 'daily_kill_5', 'name_pl' => 'Rozgrzewka', 'minLevel' => 25,
            'goal' => ['type' => 'kill_any', 'count' => 5],
            'rewards' => ['gold' => 200, 'xp' => 100],
        ]],
    ];
}

it('refresh generates the daily quest set on a new day (capped at 12)', function () {
    $c = dqChar(level: 100);

    $res = $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/refresh", ['date' => '2026-07-09']);

    $res->assertOk()
        ->assertJsonPath('refreshed', true)
        ->assertJsonPath('lastRefreshDate', '2026-07-09');
    expect($res->json('activeQuests'))->toHaveCount(12);

    $slice = GameSave::where('character_id', $c->id)->first()->state['dailyQuests'];
    expect($slice['lastRefreshDate'])->toBe('2026-07-09')
        ->and($slice['activeQuests'])->toHaveCount(12)
        ->and($slice['todayQuestDefs'])->toHaveCount(12)
        ->and($slice['activeQuests'][0]['progress'])->toBe(0)
        ->and($slice['activeQuests'][0]['completed'])->toBeFalse()
        ->and($slice['activeQuests'][0]['claimed'])->toBeFalse();
});

it('same-day refresh never resets progress of a quest that stays in the set', function () {
    $c = dqChar(level: 100);
    dqSave($c, [
        'lastRefreshDate' => '2026-07-09',
        'activeQuests' => [['questId' => 'daily_kill_10', 'progress' => 3, 'completed' => false, 'claimed' => false]],
        'todayQuestDefs' => [['id' => 'daily_kill_10', 'rewards' => ['gold' => 200, 'xp' => 100]]],
    ]);

    $res = $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/refresh", ['date' => '2026-07-09']);

    $res->assertOk()->assertJsonPath('lastRefreshDate', '2026-07-09');

    $slice = GameSave::where('character_id', $c->id)->first()->state['dailyQuests'];
    $entry = collect($slice['activeQuests'])->firstWhere('questId', 'daily_kill_10');
    expect($entry)->not->toBeNull()
        ->and($entry['progress'])->toBe(3);
});

it('claim on a completed quest grants scaled gold to blob + xp to character and bumps counter', function () {
    $c = dqChar(level: 10);
    dqSave($c, dqCompletedSlice(), gold: 50);

    $res = $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/daily_kill_5/claim");

    $res->assertOk()
        ->assertJsonPath('rewards.gold', 420)
        ->assertJsonPath('rewards.xp', 400)
        ->assertJsonPath('gold', 470)
        ->assertJsonPath('questsDailyDone', 1)
        ->assertJsonPath('newLevel', 10);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(470)
        ->and($blob['dailyQuests']['activeQuests'][0]['claimed'])->toBeTrue();

    $fresh = Character::find($c->id);
    expect($fresh->xp)->toBe(400)
        ->and($fresh->level)->toBe(10)
        ->and($fresh->quests_daily_done)->toBe(1);
});

it('rejects claiming an unfinished quest (422, no reward)', function () {
    $c = dqChar(level: 10);
    dqSave($c, [
        'lastRefreshDate' => '2026-07-09',
        'activeQuests' => [['questId' => 'daily_kill_5', 'progress' => 2, 'completed' => false, 'claimed' => false]],
        'todayQuestDefs' => [['id' => 'daily_kill_5', 'rewards' => ['gold' => 200, 'xp' => 100]]],
    ], gold: 50);

    $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/daily_kill_5/claim")
        ->assertStatus(422);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(50);
    expect(Character::find($c->id)->quests_daily_done)->toBe(0);
});

it('404 for a quest not in the active set', function () {
    $c = dqChar(level: 10);
    dqSave($c, dqCompletedSlice());

    $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/nope/claim")
        ->assertNotFound();
});

it('rejects a second claim on the same quest (422, no double reward)', function () {
    $c = dqChar(level: 10);
    dqSave($c, dqCompletedSlice(), gold: 0);

    $this->withToken(dqToken())->postJson("/api/v1/characters/{$c->id}/daily-quests/daily_kill_5/claim")->assertOk();
    $this->withToken(dqToken())->postJson("/api/v1/characters/{$c->id}/daily-quests/daily_kill_5/claim")->assertStatus(422);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(420);
    expect(Character::find($c->id)->quests_daily_done)->toBe(1);
});

it("blocks acting on another user's character (403)", function () {
    $other = Character::factory()->forUser(DQ_USER_B)->create();

    $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$other->id}/daily-quests/refresh")
        ->assertForbidden();

    $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$other->id}/daily-quests/daily_kill_5/claim")
        ->assertForbidden();
});

it('repairs a degraded slice on the same day and preserves progress', function () {
    $c = dqChar(level: 100);

    $seed = $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/refresh", ['date' => '2026-07-09']);
    $seed->assertOk()->assertJsonPath('refreshed', true);

    $fullDefs = $seed->json('todayQuestDefs');
    expect($fullDefs)->toHaveCount(12);

    $keptDefs = array_slice($fullDefs, 0, 2);
    $save = GameSave::where('character_id', $c->id)->firstOrFail();
    $blob = $save->state;
    $blob['dailyQuests'] = [
        'lastRefreshDate' => '2026-07-09',
        'todayQuestDefs' => $keptDefs,
        'activeQuests' => [
            ['questId' => $keptDefs[0]['id'], 'progress' => 3, 'completed' => false, 'claimed' => false],
            ['questId' => $keptDefs[1]['id'], 'progress' => 0, 'completed' => false, 'claimed' => false],
        ],
    ];
    $save->state = $blob;
    $save->save();

    $res = $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/refresh", ['date' => '2026-07-09']);

    $res->assertOk()
        ->assertJsonPath('refreshed', true)
        ->assertJsonPath('lastRefreshDate', '2026-07-09');

    expect($res->json('todayQuestDefs'))->toHaveCount(12);
    expect($res->json('activeQuests'))->toHaveCount(12);

    $preserved = collect($res->json('activeQuests'))->firstWhere('questId', $keptDefs[0]['id']);
    expect($preserved['progress'])->toEqual(3);
});

it('leaves a healthy slice untouched on the same day', function () {
    $c = dqChar(level: 100);

    $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/refresh", ['date' => '2026-07-09'])
        ->assertOk();

    $res = $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/refresh", ['date' => '2026-07-09']);

    $res->assertOk()->assertJsonPath('refreshed', false);
    expect($res->json('todayQuestDefs'))->toHaveCount(12);
});
