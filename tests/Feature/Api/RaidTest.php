<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const RD_USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const RD_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

beforeEach(function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(12345));
});

function rdStrongChar(string $userId = RD_USER_A): Character
{
    return Character::factory()->forUser($userId)->create([
        'level' => 5, 'xp' => 0, 'attack' => 100000, 'defense' => 50,
        'hp' => 500, 'max_hp' => 500, 'gold' => 0, 'stat_points' => 0, 'highest_level' => 5,
        'class' => 'Knight',
    ]);
}

it('resolves a raid authoritatively: full clear grants server-computed xp+gold', function () {
    $char = rdStrongChar();

    $res = $this->withToken(TokenFactory::forUser(RD_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/raid/raid_1/resolve", ['requestId' => 'rd-1']);

    $res->assertOk()
        ->assertJsonPath('result.cleared', true)
        ->assertJsonPath('result.bossesDefeated', 4)
        ->assertJsonPath('result.totalBosses', 4)
        ->assertJsonPath('result.xp', 1441)
        ->assertJsonPath('result.gold', 1720)
        ->assertJsonPath('attemptsUsed', 1)
        ->assertJsonPath('attemptsMax', 5);

    $blob = GameSave::where('character_id', $char->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(1720)
        ->and($blob['raid']['attempts']['raid_1']['count'])->toBe(1)
        ->and($blob['raid']['lastResult']['cleared'])->toBeTrue();
    expect(Character::find($char->id)->xp)->toBe(1441)
        ->and(Character::find($char->id)->gold)->toBe(0);
    expect($res->json('gold'))->toBe(1720);
});

it('IGNORES forged reward fields in the body (anti-cheat)', function () {
    $char = rdStrongChar();

    $res = $this->withToken(TokenFactory::forUser(RD_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/raid/raid_1/resolve", [
            'requestId' => 'rd-cheat',
            'gold' => 999999999, 'xp' => 999999999, 'bossesDefeated' => 999, 'cleared' => true,
        ]);

    $res->assertOk();
    $blobGold = GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'];
    expect($blobGold)->toBe(1720)
        ->and($blobGold)->toBeLessThan(999999999);
    expect(Character::find($char->id)->xp)->toBe(1441);
});

it('rejects resolving a raid on another user\'s character (403)', function () {
    $char = rdStrongChar(RD_USER_B);

    $this->withToken(TokenFactory::forUser(RD_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/raid/raid_1/resolve", ['requestId' => 'rd-x'])
        ->assertForbidden();
});

it('rejects a raid above the character level (422)', function () {
    $char = rdStrongChar();

    $this->withToken(TokenFactory::forUser(RD_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/raid/raid_7/resolve", ['requestId' => 'rd-gate'])
        ->assertStatus(422);

    expect(Character::find($char->id)->xp)->toBe(0);
});

it('returns 404 for an unknown raid', function () {
    $char = rdStrongChar();

    $this->withToken(TokenFactory::forUser(RD_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/raid/raid_nope/resolve", ['requestId' => 'rd-404'])
        ->assertNotFound();
});

it('is idempotent — replaying a requestId does not double-grant rewards or attempts', function () {
    $char = rdStrongChar();
    $body = ['requestId' => 'rd-idem'];

    $first = $this->withToken(TokenFactory::forUser(RD_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/raid/raid_1/resolve", $body);
    $goldAfterFirst = GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'];

    $second = $this->withToken(TokenFactory::forUser(RD_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/raid/raid_1/resolve", $body);

    $second->assertOk();
    expect($second->json('result.gold'))->toBe($first->json('result.gold'))
        ->and($second->json('attemptsUsed'))->toBe(1);

    $blob = GameSave::where('character_id', $char->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe($goldAfterFirst)
        ->and($blob['raid']['attempts']['raid_1']['count'])->toBe(1);
});

it('enforces the daily attempt limit (422 when exhausted)', function () {
    $char = rdStrongChar();

    GameSave::create([
        'user_id' => $char->user_id, 'character_id' => $char->id,
        'state' => [
            '_ownerCharacterId' => $char->id,
            'inventory' => ['gold' => 0, 'bag' => [], 'equipment' => [], 'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0],
            'raid' => ['attempts' => ['raid_1' => ['date' => now()->toDateString(), 'count' => 5]]],
        ],
    ]);

    $this->withToken(TokenFactory::forUser(RD_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/raid/raid_1/resolve", ['requestId' => 'rd-limit'])
        ->assertStatus(422);

    $blob = GameSave::where('character_id', $char->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(0)
        ->and($blob['raid']['attempts']['raid_1']['count'])->toBe(5);
});

it('requires authentication (401)', function () {
    $char = rdStrongChar();

    $this->postJson("/api/v1/characters/{$char->id}/raid/raid_1/resolve", ['requestId' => 'rd-noauth'])
        ->assertUnauthorized();
});
