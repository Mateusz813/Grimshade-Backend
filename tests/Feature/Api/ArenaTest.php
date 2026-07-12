<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const AR_USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const AR_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

beforeEach(function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(999));
});

function arChar(string $userId, array $attrs = [], array $arena = []): Character
{
    $c = Character::factory()->forUser($userId)->create($attrs);
    foreach ($arena as $k => $v) {
        $c->{$k} = $v;
    }
    if ($arena !== []) {
        $c->save();
    }

    return $c->refresh();
}

function arWinner(string $userId = AR_USER_A, int $lp = 0): Character
{
    return arChar($userId, [
        'attack' => 1_000_000, 'defense' => 50, 'max_hp' => 500, 'hp' => 500,
        'crit_chance' => 0.05, 'crit_damage' => 1.5,
    ], ['arena_league_points' => $lp]);
}

function arWeak(string $userId, int $lp = 0): Character
{
    return arChar($userId, [
        'attack' => 1, 'defense' => 0, 'max_hp' => 100, 'hp' => 100,
        'crit_chance' => 0.0, 'crit_damage' => 1.5,
    ], ['arena_league_points' => $lp]);
}

function arTough(string $userId, int $lp = 0): Character
{
    return arChar($userId, [
        'attack' => 1_000_000, 'defense' => 50, 'max_hp' => 100_000, 'hp' => 100_000,
        'crit_chance' => 0.05, 'crit_damage' => 1.5,
    ], ['arena_league_points' => $lp]);
}

function arSave(Character $c, int $arenaPoints = 0): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id,
        'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => ['gold' => 0, 'arenaPoints' => $arenaPoints],
        ],
    ]);
}

it('resolves a match authoritatively and updates BOTH characters', function () {
    $attacker = arWinner(AR_USER_A, lp: 0);
    $defender = arWeak(AR_USER_B, lp: 100);
    arSave($attacker, arenaPoints: 0);

    $res = $this->withToken(TokenFactory::forUser(AR_USER_A))
        ->postJson("/api/v1/characters/{$attacker->id}/arena/match", [
            'opponentId' => $defender->id, 'requestId' => 'ar-1',
        ]);

    $res->assertOk()
        ->assertJsonPath('attackerWon', true)
        ->assertJsonPath('attackerIsHigher', true)
        ->assertJsonPath('reward.attacker.arenaPoints', 200)
        ->assertJsonPath('reward.attacker.leaguePoints', 2)
        ->assertJsonPath('arenaPoints', 200);

    $atk = Character::find($attacker->id);
    expect($atk->arena_kills)->toBe(1)
        ->and($atk->arena_deaths)->toBe(0)
        ->and($atk->arena_league_points)->toBe(2);

    $def = Character::find($defender->id);
    expect($def->arena_deaths)->toBe(1)
        ->and($def->arena_kills)->toBe(0)
        ->and($def->arena_league_points)->toBe(100);

    $blob = GameSave::where('character_id', $attacker->id)->first()->state;
    expect($blob['inventory']['arenaPoints'])->toBe(200);
});

it('IGNORES a forged attackerWon in the body (anti-cheat: server simulates)', function () {
    $attacker = arWeak(AR_USER_A, lp: 0);
    $defender = arTough(AR_USER_B, lp: 0);
    arSave($attacker);

    $res = $this->withToken(TokenFactory::forUser(AR_USER_A))
        ->postJson("/api/v1/characters/{$attacker->id}/arena/match", [
            'opponentId' => $defender->id, 'requestId' => 'ar-cheat',
            'attackerWon' => true,
            'arenaPoints' => 999999, 'leaguePoints' => 999,
        ]);

    $res->assertOk()->assertJsonPath('attackerWon', false);

    $atk = Character::find($attacker->id);
    expect($atk->arena_kills)->toBe(0)
        ->and($atk->arena_deaths)->toBe(1);

    $def = Character::find($defender->id);
    expect($def->arena_kills)->toBe(1)
        ->and($def->arena_league_points)->toBe(2);

    $blob = GameSave::where('character_id', $attacker->id)->first()->state;
    expect($blob['inventory']['arenaPoints'])->toBe(0);
});

it('rejects a match on another user\'s attacker (403)', function () {
    $attacker = arWinner(AR_USER_B);
    $defender = arWeak(AR_USER_A, lp: 5);

    $this->withToken(TokenFactory::forUser(AR_USER_A))
        ->postJson("/api/v1/characters/{$attacker->id}/arena/match", [
            'opponentId' => $defender->id, 'requestId' => 'ar-403',
        ])
        ->assertForbidden();
});

it('returns 404 for an unknown opponent', function () {
    $attacker = arWinner(AR_USER_A);

    $this->withToken(TokenFactory::forUser(AR_USER_A))
        ->postJson("/api/v1/characters/{$attacker->id}/arena/match", [
            'opponentId' => 'ffffffff-ffff-ffff-ffff-ffffffffffff', 'requestId' => 'ar-404',
        ])
        ->assertNotFound();
});

it('rejects fighting yourself (422)', function () {
    $attacker = arWinner(AR_USER_A);

    $this->withToken(TokenFactory::forUser(AR_USER_A))
        ->postJson("/api/v1/characters/{$attacker->id}/arena/match", [
            'opponentId' => $attacker->id, 'requestId' => 'ar-self',
        ])
        ->assertStatus(422);
});

it('is idempotent — replaying a requestId does not double-count', function () {
    $attacker = arWinner(AR_USER_A, lp: 0);
    $defender = arWeak(AR_USER_B, lp: 100);
    arSave($attacker);
    $body = ['opponentId' => $defender->id, 'requestId' => 'ar-idem'];

    $first = $this->withToken(TokenFactory::forUser(AR_USER_A))
        ->postJson("/api/v1/characters/{$attacker->id}/arena/match", $body);
    $first->assertOk();

    $second = $this->withToken(TokenFactory::forUser(AR_USER_A))
        ->postJson("/api/v1/characters/{$attacker->id}/arena/match", $body);
    $second->assertOk();

    expect($second->json('character.arena_kills'))->toBe($first->json('character.arena_kills'));
    expect(Character::find($attacker->id)->arena_kills)->toBe(1);
    expect(Character::find($defender->id)->arena_deaths)->toBe(1);
    expect(GameSave::where('character_id', $attacker->id)->first()->state['inventory']['arenaPoints'])->toBe(200);
});

it('requires authentication (401)', function () {
    $attacker = arWinner(AR_USER_A);
    $defender = arWeak(AR_USER_B);

    $this->postJson("/api/v1/characters/{$attacker->id}/arena/match", [
        'opponentId' => $defender->id, 'requestId' => 'ar-noauth',
    ])->assertUnauthorized();
});
