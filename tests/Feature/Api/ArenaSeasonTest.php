<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const ASE_USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const ASE_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function aseChar(string $userId = ASE_USER_A, int $level = 100): Character
{
    return Character::factory()->forUser($userId)->create(['level' => $level]);
}

function aseSave(Character $c, string $league = 'bronze', int $seasonPoints = 0, ?array $pending = null, int $arenaPoints = 0): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id,
        'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => [
                'gold' => 0, 'bag' => [], 'equipment' => [], 'deposit' => [],
                'consumables' => [], 'stones' => [], 'arenaPoints' => $arenaPoints,
            ],
            'arena' => [
                'league' => $league,
                'seasonPoints' => $seasonPoints,
                'pendingRewards' => $pending,
            ],
        ],
    ]);
}

it('returns the season slice with a reward preview when a rank is pending', function () {
    $c = aseChar();
    aseSave($c, league: 'silver', seasonPoints: 420, pending: ['league' => 'silver', 'finalRank' => 3]);

    $res = $this->withToken(TokenFactory::forUser(ASE_USER_A))
        ->getJson("/api/v1/characters/{$c->id}/arena/season");

    $res->assertOk()
        ->assertJsonPath('league', 'silver')
        ->assertJsonPath('seasonPoints', 420)
        ->assertJsonPath('finalRank', 3)
        ->assertJsonPath('pendingRewards.finalRank', 3)
        ->assertJsonPath('rewardPreview.gold', 100000)
        ->assertJsonPath('rewardPreview.arenaPoints', 1000);
});

it('returns a null preview and null rank when nothing is pending', function () {
    $c = aseChar();
    aseSave($c, league: 'gold', seasonPoints: 10);

    $this->withToken(TokenFactory::forUser(ASE_USER_A))
        ->getJson("/api/v1/characters/{$c->id}/arena/season")
        ->assertOk()
        ->assertJsonPath('league', 'gold')
        ->assertJsonPath('finalRank', null)
        ->assertJsonPath('pendingRewards', null)
        ->assertJsonPath('rewardPreview', null);
});

it('claims season rewards: grants scaled loot, promotes, and clears pending', function () {
    $c = aseChar();
    aseSave($c, league: 'silver', seasonPoints: 999, pending: ['league' => 'silver', 'finalRank' => 3], arenaPoints: 0);

    $res = $this->withToken(TokenFactory::forUser(ASE_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/season/claim", [
            'requestId' => 'ase-claim',
        ]);

    $res->assertOk()
        ->assertJsonPath('granted.gold', 100000)
        ->assertJsonPath('granted.arenaPoints', 1000)
        ->assertJsonPath('granted.mythicStones', 10)
        ->assertJsonPath('granted.commonStones', 60)
        ->assertJsonPath('granted.pctHpPotion', 50)
        ->assertJsonPath('outcome.type', 'promote')
        ->assertJsonPath('outcome.toLeague', 'gold')
        ->assertJsonPath('character.arena_league', 'gold');

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(100000)
        ->and($blob['inventory']['arenaPoints'])->toBe(1000)
        ->and($blob['inventory']['stones']['mythic_stone'])->toBe(10)
        ->and($blob['inventory']['stones']['common_stone'])->toBe(60)
        ->and($blob['inventory']['consumables']['hp_potion_divine'])->toBe(50)
        ->and($blob['inventory']['consumables']['mp_potion_divine'])->toBe(50);
    expect($blob['arena']['pendingRewards'])->toBeNull()
        ->and($blob['arena']['seasonPoints'])->toBe(0)
        ->and($blob['arena']['league'])->toBe('gold');
    expect(Character::find($c->id)->arena_league)->toBe('gold');
});

it('returns 422 when there is nothing to claim', function () {
    $c = aseChar();
    aseSave($c, league: 'bronze');

    $this->withToken(TokenFactory::forUser(ASE_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/season/claim", [
            'requestId' => 'ase-nothing',
        ])
        ->assertStatus(422);
});

it('is idempotent AND cannot double-claim after clearing pending', function () {
    $c = aseChar();
    aseSave($c, league: 'silver', pending: ['league' => 'silver', 'finalRank' => 3]);
    $body = ['requestId' => 'ase-idem'];

    $first = $this->withToken(TokenFactory::forUser(ASE_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/season/claim", $body);
    $first->assertOk()->assertJsonPath('granted.gold', 100000);

    $second = $this->withToken(TokenFactory::forUser(ASE_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/season/claim", $body);
    $second->assertOk()->assertJsonPath('granted.gold', 100000);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(100000);

    $this->withToken(TokenFactory::forUser(ASE_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/season/claim", ['requestId' => 'ase-again'])
        ->assertStatus(422);
});

it('claims a low finisher (no bucket): zero loot but still resets the season', function () {
    $c = aseChar();
    aseSave($c, league: 'bronze', pending: ['league' => 'bronze', 'finalRank' => 200]);

    $res = $this->withToken(TokenFactory::forUser(ASE_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/season/claim", [
            'requestId' => 'ase-low',
        ]);

    $res->assertOk()
        ->assertJsonPath('granted.gold', 0)
        ->assertJsonPath('outcome.type', 'stay');

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['arena']['pendingRewards'])->toBeNull()
        ->and($blob['arena']['league'])->toBe('bronze');
});

it('rejects a claim on another user\'s character (403)', function () {
    $c = aseChar(ASE_USER_B);
    aseSave($c, pending: ['league' => 'bronze', 'finalRank' => 1]);

    $this->withToken(TokenFactory::forUser(ASE_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/season/claim", ['requestId' => 'ase-403'])
        ->assertForbidden();
});

it('requires authentication for the season endpoints (401)', function () {
    $c = aseChar();
    aseSave($c);

    $this->getJson("/api/v1/characters/{$c->id}/arena/season")->assertUnauthorized();
});
