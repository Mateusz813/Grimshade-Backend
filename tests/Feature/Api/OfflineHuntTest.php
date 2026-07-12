<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const OH_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const OH_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 9, 12, 0, 0, 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function ohChar(string $userId = OH_USER): Character
{
    return Character::factory()->forUser($userId)->create([
        'level' => 50, 'xp' => 0, 'stat_points' => 0, 'highest_level' => 50, 'gold' => 0,
    ]);
}

function ohToken(string $userId = OH_USER): string
{
    return TokenFactory::forUser($userId);
}

function ohSaveWithHunt(Character $c, string $startedAt, int $masteryLevel = 0, int $gold = 0): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => ['gold' => $gold, 'bag' => [], 'equipment' => [], 'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0],
            'offlineHunt' => [
                'isActive' => true,
                'startedAt' => $startedAt,
                'targetMonster' => ['id' => 'rat', 'level' => 1, 'xp' => 3, 'gold' => [1, 1], 'name_pl' => 'Szczur'],
                'trainedSkillId' => 'sword_fighting',
            ],
            'mastery' => [
                'masteries' => $masteryLevel > 0 ? ['rat' => ['level' => $masteryLevel]] : [],
                'masteryKills' => [],
            ],
        ],
    ]);
}

it('settles offline hunt and grants server-computed rewards by elapsed time', function () {
    $c = ohChar();
    ohSaveWithHunt($c, now()->copy()->subHours(2)->toIso8601String());

    $res = $this->withToken(ohToken())
        ->postJson("/api/v1/characters/{$c->id}/offline-hunt/settle", ['requestId' => 'oh-1']);

    $res->assertOk()
        ->assertJsonPath('settled', true)
        ->assertJsonPath('kills', 720)
        ->assertJsonPath('xpGained', 2160)
        ->assertJsonPath('goldGained', 720)
        ->assertJsonPath('cappedSeconds', 7200);

    expect(Character::find($c->id)->xp)->toBe(2160)
        ->and(Character::find($c->id)->level)->toBe(50)
        ->and(Character::find($c->id)->gold)->toBe(0);

    $save = GameSave::where('character_id', $c->id)->first();
    expect($save->state['inventory']['gold'])->toBe(720)
        ->and($save->state['offlineHunt']['isActive'])->toBeFalse()
        ->and($save->state['offlineHunt']['startedAt'])->toBeNull()
        ->and($save->offline_entered_at)->toBeNull();
});

it('caps elapsed hunt time at 12h', function () {
    $c = ohChar();
    ohSaveWithHunt($c, now()->copy()->subHours(24)->toIso8601String());

    $res = $this->withToken(ohToken())
        ->postJson("/api/v1/characters/{$c->id}/offline-hunt/settle", ['requestId' => 'oh-cap']);

    $res->assertOk()
        ->assertJsonPath('cappedSeconds', 43200)
        ->assertJsonPath('kills', 4320);
});

it('applies mastery speed + reward multipliers', function () {
    $c = ohChar();
    ohSaveWithHunt($c, now()->copy()->subHours(2)->toIso8601String(), masteryLevel: 20);

    $res = $this->withToken(ohToken())
        ->postJson("/api/v1/characters/{$c->id}/offline-hunt/settle", ['requestId' => 'oh-mastery']);

    $res->assertOk()
        ->assertJsonPath('kills', 2880)
        ->assertJsonPath('xpGained', 11520);
});

it('does not double-grant when the same requestId is replayed (idempotent)', function () {
    $c = ohChar();
    ohSaveWithHunt($c, now()->copy()->subHours(2)->toIso8601String());
    $body = ['requestId' => 'oh-idem'];

    $first = $this->withToken(ohToken())->postJson("/api/v1/characters/{$c->id}/offline-hunt/settle", $body);
    $first->assertOk()->assertJsonPath('goldGained', 720);

    $second = $this->withToken(ohToken())->postJson("/api/v1/characters/{$c->id}/offline-hunt/settle", $body);
    $second->assertOk()->assertJsonPath('goldGained', 720)->assertJsonPath('kills', 720);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(720)
        ->and(Character::find($c->id)->xp)->toBe(2160);
});

it('clears the offline marker so a fresh settle grants nothing (natural anti-dupe)', function () {
    $c = ohChar();
    ohSaveWithHunt($c, now()->copy()->subHours(2)->toIso8601String());

    $this->withToken(ohToken())
        ->postJson("/api/v1/characters/{$c->id}/offline-hunt/settle", ['requestId' => 'oh-a'])
        ->assertOk()->assertJsonPath('settled', true);

    $this->withToken(ohToken())
        ->postJson("/api/v1/characters/{$c->id}/offline-hunt/settle", ['requestId' => 'oh-b'])
        ->assertOk()->assertJsonPath('settled', false)->assertJsonPath('kills', 0);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(720)
        ->and(Character::find($c->id)->xp)->toBe(2160);
});

it('settles nothing when there is no active hunt', function () {
    $c = ohChar();
    GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => ['gold' => 0, 'bag' => [], 'equipment' => [], 'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0],
            'offlineHunt' => ['isActive' => false, 'startedAt' => null, 'targetMonster' => null, 'trainedSkillId' => null],
        ],
    ]);

    $this->withToken(ohToken())
        ->postJson("/api/v1/characters/{$c->id}/offline-hunt/settle", ['requestId' => 'oh-none'])
        ->assertOk()->assertJsonPath('settled', false)->assertJsonPath('kills', 0);

    expect(Character::find($c->id)->xp)->toBe(0);
});

it('rejects settling on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(OH_USER_B)->create();

    $this->withToken(ohToken())
        ->postJson("/api/v1/characters/{$other->id}/offline-hunt/settle", ['requestId' => 'oh-x'])
        ->assertForbidden();
});

it('requires authentication (401)', function () {
    $c = ohChar();

    $this->postJson("/api/v1/characters/{$c->id}/offline-hunt/settle", ['requestId' => 'oh-noauth'])
        ->assertUnauthorized();
});
