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

/**
 * @param  array<string, mixed>  $dailyQuests  slice state.dailyQuests
 */
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

/** Ukończony quest gotowy do odbioru — daily_kill_5 (base gold 200 / xp 100). */
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
    // level 100 → wszystkie 27 questów eligible → wybór 12 (DAILY_QUEST_COUNT).
    // Brak bloba → lockedFor tworzy szkielet, needsRefresh(null) = true.
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

it('refresh is a no-op when already refreshed today (keeps progress)', function () {
    $c = dqChar(level: 100);
    dqSave($c, [
        'lastRefreshDate' => '2026-07-09',
        'activeQuests' => [['questId' => 'daily_kill_5', 'progress' => 3, 'completed' => false, 'claimed' => false]],
        'todayQuestDefs' => [['id' => 'daily_kill_5', 'rewards' => ['gold' => 200, 'xp' => 100]]],
    ]);

    $res = $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/refresh", ['date' => '2026-07-09']);

    $res->assertOk()->assertJsonPath('refreshed', false);

    $slice = GameSave::where('character_id', $c->id)->first()->state['dailyQuests'];
    expect($slice['activeQuests'])->toHaveCount(1)
        ->and($slice['activeQuests'][0]['progress'])->toBe(3);
});

it('claim on a completed quest grants scaled gold to blob + xp to character and bumps counter', function () {
    $c = dqChar(level: 10);
    dqSave($c, dqCompletedSlice(), gold: 50);

    $res = $this->withToken(dqToken())
        ->postJson("/api/v1/characters/{$c->id}/daily-quests/daily_kill_5/claim");

    // scaleRewards lvl 10: gold = floor(200 * (1+10*0.25) * 0.6) = floor(420) = 420
    //                       xp  = floor(100 * (1+10*0.3))        = floor(400) = 400
    $res->assertOk()
        ->assertJsonPath('rewards.gold', 420)
        ->assertJsonPath('rewards.xp', 400)
        ->assertJsonPath('gold', 470)            // 50 + 420
        ->assertJsonPath('questsDailyDone', 1)
        ->assertJsonPath('newLevel', 10);        // 400 xp < xpToNextLevel(10) → brak level-upa

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

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(420); // nie 840
    expect(Character::find($c->id)->quests_daily_done)->toBe(1);                                      // nie 2
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
