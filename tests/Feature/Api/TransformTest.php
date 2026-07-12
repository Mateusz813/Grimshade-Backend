<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const TR_USER_A = 'a1a1a1a1-a1a1-a1a1-a1a1-a1a1a1a1a1a1';
const TR_USER_B = 'b2b2b2b2-b2b2-b2b2-b2b2-b2b2b2b2b2b2';

beforeEach(function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(12345));
});

function transformChar(int $level = 30, string $userId = TR_USER_A, string $class = 'Knight'): Character
{
    return Character::factory()->forUser($userId)->create([
        'class' => $class,
        'level' => $level, 'xp' => 0, 'attack' => 500000, 'defense' => 50,
        'crit_chance' => 0.05, 'crit_damage' => 1.5,
        'hp' => 5000, 'max_hp' => 5000, 'gold' => 0, 'stat_points' => 0, 'highest_level' => $level,
    ]);
}

it('resolves a transform boss fight authoritatively and locks a pending claim', function () {
    $char = transformChar();

    $res = $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/1/resolve", [
            'requestId' => 'tr-req-1',
        ]);

    $res->assertOk()
        ->assertJsonPath('result.won', true)
        ->assertJsonPath('pendingClaimTransformId', 1);

    expect($res->json('result.boss.hp'))->toBeGreaterThan(0);

    $blob = GameSave::where('character_id', $char->id)->first()->state;
    expect($blob['transforms']['pendingClaimTransformId'])->toBe(1)
        ->and($blob['transforms']['completedTransforms'] ?? [])->toBe([]);
});

it('rejects a transform above the character level (422)', function () {
    $char = transformChar(5);

    $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/1/resolve", [
            'requestId' => 'tr-req-low',
        ])
        ->assertStatus(422);
});

it('rejects an out-of-order transform (422)', function () {
    $char = transformChar(1000);

    $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/2/resolve", [
            'requestId' => 'tr-req-order',
        ])
        ->assertStatus(422);
});

it('returns 404 for an unknown transform', function () {
    $char = transformChar(1000);

    $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/999/resolve", [
            'requestId' => 'tr-req-404',
        ])
        ->assertNotFound();
});

it('is idempotent — replaying a resolve requestId returns the cached result', function () {
    $char = transformChar();
    $body = ['requestId' => 'tr-req-idem'];

    $first = $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/1/resolve", $body);
    $second = $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/1/resolve", $body);

    $second->assertOk();
    expect($second->json('pendingClaimTransformId'))->toBe($first->json('pendingClaimTransformId'));
    $blob = GameSave::where('character_id', $char->id)->first()->state;
    expect($blob['transforms']['pendingClaimTransformId'])->toBe(1);
});

it('claim appends completedTransforms and grants deterministic consumables', function () {
    $char = transformChar(30, TR_USER_A, 'Knight');

    $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/1/resolve", [
            'requestId' => 'tr-claim-fight',
        ])->assertOk();

    $claim = $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/claim");

    $claim->assertOk()
        ->assertJsonPath('transformId', 1)
        ->assertJsonPath('completedTransforms', [1]);

    expect($claim->json('consumables.hp_potion_sm'))->toBe(50)
        ->and($claim->json('consumables.mp_potion_sm'))->toBe(50)
        ->and($claim->json('consumables.premium_xp_elixir'))->toBe(5)
        ->and($claim->json('consumables.mythic_stone'))->toBe(1);

    $blob = GameSave::where('character_id', $char->id)->first()->state;
    expect($blob['transforms']['completedTransforms'])->toBe([1])
        ->and($blob['transforms']['pendingClaimTransformId'])->toBeNull();
});

it('claim is idempotent — a second claim with no pending reward is 404', function () {
    $char = transformChar();

    $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/1/resolve", [
            'requestId' => 'tr-claim2-fight',
        ])->assertOk();

    $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/claim")
        ->assertOk();

    $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/claim")
        ->assertNotFound();
});

it('rejects resolving a transform on another user\'s character (403)', function () {
    $char = transformChar(30, TR_USER_B);

    $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/1/resolve", [
            'requestId' => 'tr-req-403',
        ])
        ->assertForbidden();
});

it('rejects claiming on another user\'s character (403)', function () {
    $char = transformChar(30, TR_USER_B);

    $this->withToken(TokenFactory::forUser(TR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/transform/claim")
        ->assertForbidden();
});

it('requires authentication (401)', function () {
    $char = transformChar();

    $this->postJson("/api/v1/characters/{$char->id}/transform/1/resolve", [
        'requestId' => 'tr-req-noauth',
    ])->assertUnauthorized();
});
