<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const SE_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const SE_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function seChar(int $level = 50): Character
{
    return Character::factory()->forUser(SE_USER)->create(['level' => $level]);
}

function seSave(Character $c, int $gold = 100000, array $extraInv = []): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id,
        'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => array_merge([
                'gold' => $gold,
                'bag' => [[
                    'uuid' => 'itm-1', 'itemId' => 'generated_rare_lvl50', 'rarity' => 'rare',
                    'bonuses' => ['attack' => 10], 'itemLevel' => 50, 'upgradeLevel' => 2,
                ]],
                'equipment' => [], 'deposit' => [],
                'consumables' => ['hp_potion_sm' => 3],
                'stones' => ['rare_stone' => 50, 'common_stone' => 10],
                'arenaPoints' => 0,
            ], $extraInv),
            'settings' => ['language' => 'pl'],
        ],
    ]);
}

function seToken(): string
{
    return TokenFactory::forUser(SE_USER);
}


it('returns blob-shaped state with character', function () {
    $c = seChar();
    seSave($c, 12345);

    $res = $this->withToken(seToken())->getJson("/api/v1/characters/{$c->id}/state");

    $res->assertOk()
        ->assertJsonPath('state.inventory.gold', 12345)
        ->assertJsonPath('character.id', $c->id);
});

it('blocks reading another user\'s state (403)', function () {
    $other = Character::factory()->forUser(SE_USER_B)->create();

    $this->withToken(seToken())->getJson("/api/v1/characters/{$other->id}/state")->assertForbidden();
});


it('writes ONLY the settings slice via prefs', function () {
    $c = seChar();
    seSave($c, 777);

    $this->withToken(seToken())
        ->putJson("/api/v1/characters/{$c->id}/prefs", ['settings' => ['language' => 'en', 'combatSpeed' => 'x2']])
        ->assertOk();

    $state = GameSave::where('character_id', $c->id)->first()->state;
    expect($state['settings'])->toBe(['language' => 'en', 'combatSpeed' => 'x2'])
        ->and($state['inventory']['gold'])->toBe(777);
});


it('sells an item: server-computed gold + stone refund, item removed', function () {
    $c = seChar();
    seSave($c, 1000);

    $res = $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/items/sell", [
        'itemUuid' => 'itm-1', 'requestId' => 'sell-1',
    ]);

    $res->assertOk()
        ->assertJsonPath('goldGained', 1650)
        ->assertJsonPath('stonesRefunded', 2)
        ->assertJsonPath('stoneType', 'rare_stone')
        ->assertJsonPath('gold', 2650);

    $state = GameSave::where('character_id', $c->id)->first()->state;
    expect($state['inventory']['bag'])->toBe([])
        ->and($state['inventory']['stones']['rare_stone'])->toBe(52);
});

it('sell is idempotent per requestId (no double gold)', function () {
    $c = seChar();
    seSave($c, 0);
    $body = ['itemUuid' => 'itm-1', 'requestId' => 'sell-x'];

    $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/items/sell", $body)->assertOk();
    $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/items/sell", $body)->assertOk();

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(1650);
});

it('selling a nonexistent item is 404 (and cannot dupe)', function () {
    $c = seChar();
    seSave($c);

    $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/items/sell", [
        'itemUuid' => 'no-such', 'requestId' => 'sell-404',
    ])->assertNotFound();
});


it('upgrade deducts cost ALWAYS and rolls success server-side', function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(12345));
    $c = seChar();
    seSave($c, 10000);

    $res = $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/items/upgrade", [
        'itemUuid' => 'itm-1', 'requestId' => 'up-1',
    ]);

    $res->assertOk()->assertJsonPath('success', false)->assertJsonPath('gold', 8000);
    $state = GameSave::where('character_id', $c->id)->first()->state;
    expect($state['inventory']['stones']['rare_stone'])->toBe(48)
        ->and($state['inventory']['bag'][0]['upgradeLevel'])->toBe(2);
    expect(Character::find($c->id)->item_upgrades_done)->toBe(0);
});

it('successful upgrade bumps level and the leaderboard counter', function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(7));
    $c = seChar();
    seSave($c, 10000);

    $res = $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/items/upgrade", [
        'itemUuid' => 'itm-1', 'requestId' => 'up-2',
    ]);

    $res->assertOk()->assertJsonPath('success', true)->assertJsonPath('item.upgradeLevel', 3);
    expect(Character::find($c->id)->item_upgrades_done)->toBe(1);
});

it('upgrade with insufficient gold is 422 and deducts NOTHING', function () {
    $c = seChar();
    seSave($c, 100);

    $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/items/upgrade", [
        'itemUuid' => 'itm-1', 'requestId' => 'up-3',
    ])->assertStatus(422);

    $state = GameSave::where('character_id', $c->id)->first()->state;
    expect($state['inventory']['gold'])->toBe(100)
        ->and($state['inventory']['stones']['rare_stone'])->toBe(50);
});


it('buys elixirs at server price with level gate', function () {
    $c = seChar(50);
    seSave($c, 2000);

    $res = $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-elixir", [
        'itemId' => 'hp_potion_sm', 'quantity' => 10, 'requestId' => 'buy-1',
    ]);

    $res->assertOk()->assertJsonPath('totalPrice', 300)->assertJsonPath('gold', 1700);
    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['consumables']['hp_potion_sm'])->toBe(13);
});

it('rejects buying above-level elixirs (422) and unknown items (404)', function () {
    $c = seChar(10);
    seSave($c, 10_000_000);

    $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-elixir", [
        'itemId' => 'hp_potion_mega', 'quantity' => 1, 'requestId' => 'buy-2',
    ])->assertStatus(422);

    $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-elixir", [
        'itemId' => 'no_such_item', 'quantity' => 1, 'requestId' => 'buy-3',
    ])->assertNotFound();
});

it('rejects buying without funds (422, nothing credited)', function () {
    $c = seChar(50);
    seSave($c, 10);

    $this->withToken(seToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-elixir", [
        'itemId' => 'hp_potion_sm', 'quantity' => 1, 'requestId' => 'buy-4',
    ])->assertStatus(422);

    $state = GameSave::where('character_id', $c->id)->first()->state;
    expect($state['inventory']['gold'])->toBe(10)
        ->and($state['inventory']['consumables']['hp_potion_sm'])->toBe(3);
});
