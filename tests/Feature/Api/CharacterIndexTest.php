<?php

declare(strict_types=1);

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

it('returns the authenticated user\'s characters as a raw array', function () {
    Character::factory()->forUser(USER_A)->create(['name' => 'Krasek', 'class' => 'Knight']);
    Character::factory()->forUser(USER_A)->create(['name' => 'Zael', 'class' => 'Mage']);

    $response = $this->withToken(TokenFactory::forUser(USER_A))
        ->getJson('/api/v1/characters');

    $response->assertOk();
    expect($response->json())->toBeArray()->toHaveCount(2);
    $response->assertJsonFragment(['name' => 'Krasek', 'user_id' => USER_A])
        ->assertJsonFragment(['name' => 'Zael']);
    expect(array_is_list($response->json()))->toBeTrue();
});

it('never returns another user\'s characters', function () {
    Character::factory()->forUser(USER_A)->create(['name' => 'Mine']);
    Character::factory()->forUser(USER_B)->create(['name' => 'NotMine']);

    $response = $this->withToken(TokenFactory::forUser(USER_A))
        ->getJson('/api/v1/characters');

    $response->assertOk();
    expect($response->json())->toHaveCount(1);
    $response->assertJsonFragment(['name' => 'Mine'])
        ->assertJsonMissing(['name' => 'NotMine']);
});

it('returns an empty array when the user has no characters', function () {
    $response = $this->withToken(TokenFactory::forUser(USER_A))
        ->getJson('/api/v1/characters');

    $response->assertOk();
    expect($response->json())->toBe([]);
});

it('rejects a request without a token (401)', function () {
    $this->getJson('/api/v1/characters')->assertUnauthorized();
});

it('rejects a request with an invalid token (401)', function () {
    $this->withToken('garbage.token.value')
        ->getJson('/api/v1/characters')
        ->assertUnauthorized();
});

it('rejects a token with the wrong audience (401)', function () {
    $this->withToken(TokenFactory::forUser(USER_A, ['aud' => 'anon']))
        ->getJson('/api/v1/characters')
        ->assertUnauthorized();
});

it('returns characters ordered by creation time', function () {
    $first = Character::factory()->forUser(USER_A)->create(['name' => 'First']);
    $first->forceFill(['created_at' => now()->subDay()])->save();
    $second = Character::factory()->forUser(USER_A)->create(['name' => 'Second']);
    $second->forceFill(['created_at' => now()])->save();

    $names = collect(
        $this->withToken(TokenFactory::forUser(USER_A))->getJson('/api/v1/characters')->json()
    )->pluck('name')->all();

    expect($names)->toBe(['First', 'Second']);
});
