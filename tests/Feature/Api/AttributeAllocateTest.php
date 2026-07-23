<?php

declare(strict_types=1);

use App\Domain\Character\AttributeSystem;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const ATTR_USER = 'cccccccc-5555-6666-7777-888888888888';

function attrChar(array $attrs = []): Character
{
    $c = Character::factory()->forUser(ATTR_USER)->create(array_merge(
        ['class' => 'Knight', 'level' => 300, 'highest_level' => 300, 'stat_points' => 30],
        $attrs,
    ));
    GameSave::query()->create(['character_id' => $c->id, 'user_id' => $c->user_id, 'state' => []]);

    return $c;
}

function attrAllocate(Character $c, string $stat, int $points, ?string $requestId = null)
{
    return test()->withToken(TokenFactory::forUser(ATTR_USER))->postJson(
        "/api/v1/characters/{$c->id}/attributes/allocate",
        ['requestId' => $requestId ?? (string) Str::uuid(), 'stat' => $stat, 'points' => $points],
    );
}

it('persists an allocation server-side immediately, so closing the app cannot lose it', function () {
    $c = attrChar();

    attrAllocate($c, 'attack', 5)->assertOk()->assertJsonPath('applied', 5);

    $fresh = Character::query()->find($c->id);
    $slice = GameSave::query()->where('character_id', $c->id)->first()->state['attributes'];

    expect($fresh->stat_points)->toBe(25)
        ->and($slice['attackPoints'])->toBe(5);
});

it('never applies more points than the character has in the budget', function () {
    $c = attrChar(['stat_points' => 3]);

    attrAllocate($c, 'hp', 10)->assertOk()->assertJsonPath('applied', 3);

    expect(Character::query()->find($c->id)->stat_points)->toBe(0);
});

it('enforces the per-class defense cap and leaves unspent points in the pool', function () {
    $c = attrChar(['class' => 'Mage', 'stat_points' => 100]);
    $cap = AttributeSystem::getMaxDefensePoints('Mage');

    attrAllocate($c, 'defense', 100)->assertOk()->assertJsonPath('applied', $cap);

    $fresh = Character::query()->find($c->id);
    expect($fresh->stat_points)->toBe(100 - $cap)
        ->and(GameSave::query()->where('character_id', $c->id)->first()->state['attributes']['defensePoints'])->toBe($cap);
});

it('is idempotent per requestId so a double tap cannot double-spend', function () {
    $c = attrChar();
    $rid = 'attr-fixed-request';

    attrAllocate($c, 'attack', 5, $rid)->assertOk();
    attrAllocate($c, 'attack', 5, $rid)->assertOk();

    expect(Character::query()->find($c->id)->stat_points)->toBe(25);
});

it('applies zero when the pool is empty and changes nothing', function () {
    $c = attrChar(['stat_points' => 0]);

    attrAllocate($c, 'attack', 5)->assertOk()->assertJsonPath('applied', 0);

    expect(Character::query()->find($c->id)->stat_points)->toBe(0);
});

it('blocks allocating on someone else character', function () {
    attrChar();
    $other = Character::factory()->forUser('dddddddd-5555-6666-7777-888888888888')->create(['stat_points' => 10]);

    test()->withToken(TokenFactory::forUser(ATTR_USER))->postJson(
        "/api/v1/characters/{$other->id}/attributes/allocate",
        ['requestId' => (string) Str::uuid(), 'stat' => 'attack', 'points' => 1],
    )->assertForbidden();
});
