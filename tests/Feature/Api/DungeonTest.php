<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const DG_USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const DG_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

const DG_DUNGEON_ID = 'dungeon_1';
const DG_HIGH_DUNGEON_ID = 'dungeon_40';

beforeEach(function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(12345));
});

function dungeonChar(string $userId = DG_USER_A, int $level = 5): Character
{
    return Character::factory()->forUser($userId)->create([
        'class' => 'Knight',
        'level' => $level, 'xp' => 0, 'attack' => 100000, 'defense' => 50,
        'hp' => 500, 'max_hp' => 500, 'gold' => 0, 'stat_points' => 0, 'highest_level' => $level,
    ]);
}

function dungeonSaveWithAttempts(Character $c, int $used, string $date): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => ['gold' => 0, 'bag' => [], 'equipment' => [], 'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0],
            'dungeons' => ['dailyAttempts' => [DG_DUNGEON_ID => ['used' => $used, 'date' => $date]], 'clearedDungeonIds' => [], 'lastResult' => null],
        ],
    ]);
}

it('resolves a dungeon authoritatively and grants server-computed rewards', function () {
    $char = dungeonChar();

    $res = $this->withToken(TokenFactory::forUser(DG_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/dungeon/".DG_DUNGEON_ID.'/resolve', [
            'requestId' => 'dg-req-1',
        ]);

    $res->assertOk()
        ->assertJsonPath('result.success', true)
        ->assertJsonPath('attemptsUsed', 1)
        ->assertJsonPath('attemptsMax', 5);
    expect($res->json('result.gold'))->toBeGreaterThan(0)
        ->and($res->json('result.xp'))->toBeGreaterThan(0);

    $save = GameSave::where('character_id', $char->id)->first();
    expect($save->state['inventory']['gold'])->toBe($res->json('result.gold'))
        ->and(Character::find($char->id)->gold)->toBe(0);

    expect(Character::find($char->id)->xp)->toBeGreaterThan(0)
        ->and($save->state['dungeons']['dailyAttempts'][DG_DUNGEON_ID]['used'])->toBe(1)
        ->and($save->state['dungeons']['clearedDungeonIds'][DG_DUNGEON_ID])->toBeTrue();
});

it('IGNORES forged reward fields in the body (anti-cheat)', function () {
    $char = dungeonChar();

    $res = $this->withToken(TokenFactory::forUser(DG_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/dungeon/".DG_DUNGEON_ID.'/resolve', [
            'requestId' => 'dg-cheat',
            'gold' => 999999999, 'xp' => 999999999, 'level' => 999, 'success' => true,
        ]);

    $res->assertOk();
    $blobGold = GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'];
    expect($blobGold)->toBe($res->json('result.gold'))
        ->and($blobGold)->toBeLessThan(999999999)
        ->and(Character::find($char->id)->level)->toBeLessThan(999);
});

it('rejects a dungeon above the character min-level (422)', function () {
    $char = dungeonChar(DG_USER_A, level: 5);

    $this->withToken(TokenFactory::forUser(DG_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/dungeon/".DG_HIGH_DUNGEON_ID.'/resolve', [
            'requestId' => 'dg-gate',
        ])
        ->assertStatus(422);

    expect(GameSave::where('character_id', $char->id)->exists())->toBeFalse();
});

it('rejects a dungeon when the daily attempt limit is exhausted (422)', function () {
    $char = dungeonChar();
    dungeonSaveWithAttempts($char, used: 5, date: now()->toDateString());

    $this->withToken(TokenFactory::forUser(DG_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/dungeon/".DG_DUNGEON_ID.'/resolve', [
            'requestId' => 'dg-limit',
        ])
        ->assertStatus(422);

    expect(GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'])->toBe(0);
});

it('resets the daily limit on a new day (yesterday\'s attempts do not count)', function () {
    $char = dungeonChar();
    dungeonSaveWithAttempts($char, used: 5, date: now()->subDay()->toDateString());

    $res = $this->withToken(TokenFactory::forUser(DG_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/dungeon/".DG_DUNGEON_ID.'/resolve', [
            'requestId' => 'dg-newday',
        ]);

    $res->assertOk()->assertJsonPath('result.success', true)->assertJsonPath('attemptsUsed', 1);
});

it('rejects resolving a dungeon on another user\'s character (403)', function () {
    $char = dungeonChar(DG_USER_B);

    $this->withToken(TokenFactory::forUser(DG_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/dungeon/".DG_DUNGEON_ID.'/resolve', [
            'requestId' => 'dg-403',
        ])
        ->assertForbidden();
});

it('returns 404 for an unknown dungeon', function () {
    $char = dungeonChar();

    $this->withToken(TokenFactory::forUser(DG_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/dungeon/no_such_dungeon/resolve", [
            'requestId' => 'dg-404',
        ])
        ->assertNotFound();
});

it('is idempotent — replaying a requestId does not double-grant rewards', function () {
    $char = dungeonChar();
    $body = ['requestId' => 'dg-idem'];

    $first = $this->withToken(TokenFactory::forUser(DG_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/dungeon/".DG_DUNGEON_ID.'/resolve', $body);
    $goldAfterFirst = GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'];

    $second = $this->withToken(TokenFactory::forUser(DG_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/dungeon/".DG_DUNGEON_ID.'/resolve', $body);

    $second->assertOk();
    expect($second->json('result.gold'))->toBe($first->json('result.gold'));
    $save = GameSave::where('character_id', $char->id)->first();
    expect($save->state['inventory']['gold'])->toBe($goldAfterFirst)
        ->and($save->state['dungeons']['dailyAttempts'][DG_DUNGEON_ID]['used'])->toBe(1);
});

it('requires authentication (401)', function () {
    $char = dungeonChar();

    $this->postJson("/api/v1/characters/{$char->id}/dungeon/".DG_DUNGEON_ID.'/resolve', [
        'requestId' => 'dg-noauth',
    ])->assertUnauthorized();
});
