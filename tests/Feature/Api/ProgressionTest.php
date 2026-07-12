<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const PR_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const PR_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function prChar(): Character
{
    return Character::factory()->forUser(PR_USER)->create(['level' => 50, 'xp' => 0, 'stat_points' => 0]);
}

function prSaveWithTask(Character $c, int $progress, int $killCount = 10, int $gold = 0): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => ['gold' => $gold, 'bag' => [], 'equipment' => [], 'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0],
            'tasks' => ['activeTask' => null, 'activeTasks' => [[
                'id' => 'task-1', 'monsterId' => 'rat', 'monsterName' => 'Szczur',
                'killCount' => $killCount, 'progress' => $progress, 'rewardGold' => 0, 'rewardXp' => 0,
            ]], 'completedTasks' => []],
        ],
    ]);
}

function prToken(): string
{
    return TokenFactory::forUser(PR_USER);
}

it('claims a completed task: server-recomputed gold to blob + xp to character', function () {
    $c = prChar();
    prSaveWithTask($c, progress: 10, killCount: 10);

    $res = $this->withToken(prToken())->postJson("/api/v1/characters/{$c->id}/tasks/task-1/claim");

    $res->assertOk()
        ->assertJsonPath('rewards.rewardXp', 45)
        ->assertJsonPath('rewards.rewardGold', 30);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(30)
        ->and($blob['tasks']['activeTasks'])->toBe([])
        ->and($blob['tasks']['completedTasks'])->toHaveCount(1);
    expect(Character::find($c->id)->xp)->toBe(45);
});

it('rejects claiming an unfinished task (422)', function () {
    $c = prChar();
    prSaveWithTask($c, progress: 3, killCount: 10);

    $this->withToken(prToken())->postJson("/api/v1/characters/{$c->id}/tasks/task-1/claim")->assertStatus(422);
    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(0);
});

it('is naturally idempotent: second claim is 404 (no double reward)', function () {
    $c = prChar();
    prSaveWithTask($c, progress: 10);

    $this->withToken(prToken())->postJson("/api/v1/characters/{$c->id}/tasks/task-1/claim")->assertOk();
    $this->withToken(prToken())->postJson("/api/v1/characters/{$c->id}/tasks/task-1/claim")->assertNotFound();

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(30);
});

it('404 for unknown task', function () {
    $c = prChar();
    prSaveWithTask($c, progress: 10);

    $this->withToken(prToken())->postJson("/api/v1/characters/{$c->id}/tasks/nope/claim")->assertNotFound();
});

it('blocks claiming on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(PR_USER_B)->create();

    $this->withToken(prToken())->postJson("/api/v1/characters/{$other->id}/tasks/task-1/claim")->assertForbidden();
});
