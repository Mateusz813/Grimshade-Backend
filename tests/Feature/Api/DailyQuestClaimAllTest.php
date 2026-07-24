<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const DQA_USER = 'dcdcdcdc-1212-3434-5656-909090909090';

function dqaChar(array $activeQuests, array $defs): Character
{
    $c = Character::factory()->forUser(DQA_USER)->create([
        'class' => 'Knight', 'level' => 30, 'highest_level' => 30, 'xp' => 0, 'gold' => 0,
    ]);
    GameSave::query()->create([
        'character_id' => $c->id,
        'user_id' => $c->user_id,
        'state' => [
            'inventory' => ['gold' => 0, 'bag' => [], 'equipment' => [], 'deposit' => []],
            'dailyQuests' => [
                'lastRefreshDate' => now()->toDateString(),
                'activeQuests' => $activeQuests,
                'todayQuestDefs' => $defs,
            ],
        ],
    ]);

    return $c;
}

function dqaDef(string $id, int $gold = 100, int $xp = 50): array
{
    return ['id' => $id, 'name_pl' => $id, 'minLevel' => 1, 'rewards' => ['gold' => $gold, 'xp' => $xp]];
}

function dqaClaimAll(Character $c, ?string $requestId = null)
{
    return test()->withToken(TokenFactory::forUser(DQA_USER))->postJson(
        "/api/v1/characters/{$c->id}/daily-quests/claim-all",
        ['requestId' => $requestId ?? (string) Str::uuid()],
    );
}

it('claims every completed unclaimed daily in ONE request and one transaction', function () {
    $c = dqaChar([
        ['questId' => 'q1', 'completed' => true, 'claimed' => false],
        ['questId' => 'q2', 'completed' => true, 'claimed' => false],
        ['questId' => 'q3', 'completed' => true, 'claimed' => false],
    ], [dqaDef('q1'), dqaDef('q2'), dqaDef('q3')]);

    $res = dqaClaimAll($c);

    $res->assertOk()->assertJsonPath('claimedCount', 3);
    expect($res->json('updated_at'))->not->toBeNull();

    $slice = GameSave::query()->where('character_id', $c->id)->first()->state['dailyQuests'];
    foreach ($slice['activeQuests'] as $aq) {
        expect($aq['claimed'])->toBeTrue();
    }
    expect(Character::query()->find($c->id)->quests_daily_done)->toBe(3);
});

it('skips incomplete and already-claimed quests', function () {
    $c = dqaChar([
        ['questId' => 'q1', 'completed' => true, 'claimed' => false],
        ['questId' => 'q2', 'completed' => false, 'claimed' => false],
        ['questId' => 'q3', 'completed' => true, 'claimed' => true],
    ], [dqaDef('q1'), dqaDef('q2'), dqaDef('q3')]);

    dqaClaimAll($c)->assertOk()->assertJsonPath('claimedCount', 1);

    $slice = GameSave::query()->where('character_id', $c->id)->first()->state['dailyQuests'];
    expect($slice['activeQuests'][1]['claimed'])->toBeFalse();
});

it('grants gold and xp exactly once across the batch', function () {
    $c = dqaChar([
        ['questId' => 'q1', 'completed' => true, 'claimed' => false],
        ['questId' => 'q2', 'completed' => true, 'claimed' => false],
    ], [dqaDef('q1', 100, 10), dqaDef('q2', 250, 20)]);

    $res = dqaClaimAll($c);

    $res->assertOk();
    $goldInState = GameSave::query()->where('character_id', $c->id)->first()->state['inventory']['gold'];
    expect($goldInState)->toBeGreaterThan(0);
    $fresh = Character::query()->find($c->id);
    expect($fresh->xp + 1)->toBeGreaterThan(1);
});

it('is idempotent per requestId — a double tap cannot double-claim', function () {
    $c = dqaChar([
        ['questId' => 'q1', 'completed' => true, 'claimed' => false],
    ], [dqaDef('q1', 100, 10)]);
    $rid = 'claim-all-fixed';

    dqaClaimAll($c, $rid)->assertOk()->assertJsonPath('claimedCount', 1);
    dqaClaimAll($c, $rid)->assertOk()->assertJsonPath('claimedCount', 1);

    expect(Character::query()->find($c->id)->quests_daily_done)->toBe(1);
});

it('returns claimedCount 0 when nothing is claimable and changes nothing', function () {
    $c = dqaChar([
        ['questId' => 'q1', 'completed' => false, 'claimed' => false],
    ], [dqaDef('q1')]);

    dqaClaimAll($c)->assertOk()->assertJsonPath('claimedCount', 0);
    expect(Character::query()->find($c->id)->quests_daily_done)->toBe(0);
});
