<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const OHS_USER = 'aaaaaaaa-1111-2222-3333-444444444444';

function ohsChar(array $attrs = []): Character
{
    $c = Character::factory()->forUser(OHS_USER)->create(array_merge(
        ['class' => 'Archer', 'level' => 100, 'highest_level' => 100],
        $attrs,
    ));
    GameSave::query()->create(['character_id' => $c->id, 'user_id' => $c->user_id, 'state' => []]);

    return $c;
}

function ohsToken(): string
{
    return TokenFactory::forUser(OHS_USER);
}

function ohsStart(Character $c, string $monsterId, string $skillId = 'distance_fighting', ?string $requestId = null)
{
    return test()->withToken(ohsToken())->postJson(
        "/api/v1/characters/{$c->id}/offline-hunt/start",
        ['requestId' => $requestId ?? (string) Str::uuid(), 'monsterId' => $monsterId, 'skillId' => $skillId],
    );
}

it('persists the hunt server-side immediately, so closing the app cannot lose it', function () {
    $c = ohsChar();

    $res = ohsStart($c, 'rat');

    $res->assertOk()->assertJsonPath('started', true);

    $slice = GameSave::query()->where('character_id', $c->id)->first()->state['offlineHunt'];
    expect($slice['isActive'])->toBeTrue()
        ->and($slice['targetMonster']['id'])->toBe('rat')
        ->and($slice['trainedSkillId'])->toBe('distance_fighting')
        ->and($slice['startedAt'])->not->toBeNull();
});

it('makes settle see the hunt even though the client never committed its blob', function () {
    $c = ohsChar();
    ohsStart($c, 'rat')->assertOk();

    $save = GameSave::query()->where('character_id', $c->id)->first();
    expect($save->state['offlineHunt']['isActive'])->toBeTrue();
});

it('is idempotent per requestId so a double tap cannot restart the timer', function () {
    $c = ohsChar();
    $rid = 'fixed-request-id';

    ohsStart($c, 'rat', 'distance_fighting', $rid)->assertOk();
    $first = GameSave::query()->where('character_id', $c->id)->first()->state['offlineHunt']['startedAt'];

    ohsStart($c, 'rat', 'distance_fighting', $rid)->assertOk();
    $second = GameSave::query()->where('character_id', $c->id)->first()->state['offlineHunt']['startedAt'];

    expect($second)->toBe($first);
});

it('rejects an unknown monster', function () {
    $c = ohsChar();

    ohsStart($c, 'nie_ma_takiego_potwora')->assertStatus(422);
});

it('rejects a monster above the character level', function () {
    $c = ohsChar(['level' => 1, 'highest_level' => 1]);

    ohsStart($c, 'end_of_all')->assertStatus(422);
});

it('blocks starting a hunt on someone else character', function () {
    $mine = ohsChar();
    $other = Character::factory()->forUser('bbbbbbbb-1111-2222-3333-444444444444')->create(['level' => 100]);

    test()->withToken(ohsToken())->postJson(
        "/api/v1/characters/{$other->id}/offline-hunt/start",
        ['requestId' => (string) Str::uuid(), 'monsterId' => 'rat', 'skillId' => 'distance_fighting'],
    )->assertForbidden();

    expect($mine->id)->not->toBe($other->id);
});
