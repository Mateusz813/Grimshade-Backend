<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const ASV_USER = 'abababab-1212-3434-5656-787878787878';

function asvChar(array $state = []): Character
{
    $c = Character::factory()->forUser(ASV_USER)->create([
        'class' => 'Knight', 'level' => 50, 'highest_level' => 50, 'stat_points' => 10,
    ]);
    GameSave::query()->create(['character_id' => $c->id, 'user_id' => $c->user_id, 'state' => $state]);

    return $c;
}

it('appends updated_at to a mutating per-action response that did not include it', function () {
    $itemUuid = (string) Str::uuid();
    $c = asvChar([
        'inventory' => [
            'gold' => 0,
            'bag' => [[
                'uuid' => $itemUuid,
                'itemId' => 'sword_lvl50_common',
                'rarity' => 'common',
                'bonuses' => ['dmg_min' => 5, 'dmg_max' => 9],
                'itemLevel' => 50,
                'upgradeLevel' => 0,
            ]],
            'equipment' => [],
            'deposit' => [],
        ],
    ]);

    $res = test()->withToken(TokenFactory::forUser(ASV_USER))->postJson(
        "/api/v1/characters/{$c->id}/items/sell",
        ['requestId' => (string) Str::uuid(), 'itemUuid' => $itemUuid],
    );

    $res->assertOk();
    expect($res->json('updated_at'))->not->toBeNull()
        ->and($res->json())->toHaveKey('goldGained');
});

it('does not touch GET responses', function () {
    $c = asvChar();

    $res = test()->withToken(TokenFactory::forUser(ASV_USER))->getJson(
        "/api/v1/characters/{$c->id}/state",
    );

    $res->assertOk();
    expect($res->json())->toHaveKey('updated_at');
});
