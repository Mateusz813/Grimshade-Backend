<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const AS_USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const AS_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

beforeEach(function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(999));
});

function asChar(string $userId = AS_USER_A, int $level = 300, string $class = 'Knight'): Character
{
    return Character::factory()->forUser($userId)->create(['level' => $level, 'class' => $class]);
}

function asSave(Character $c, int $arenaPoints = 0): GameSave
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
        ],
    ]);
}

// ---- GET /arena/shop --------------------------------------------------------

it('returns the arena shop catalog with current arenaPoints', function () {
    $c = asChar();
    asSave($c, arenaPoints: 1234);

    $res = $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->getJson("/api/v1/characters/{$c->id}/arena/shop");

    $res->assertOk()
        ->assertJsonPath('arenaPoints', 1234);

    $catalog = $res->json('catalog');
    $ids = array_column($catalog, 'id');

    // Kubełki: mythic (perLevel), stony, poteki, dynamiczne eliksiry.
    expect($ids)->toContain('arena_mythic_main')
        ->and($ids)->toContain('arena_stone_heroic')
        ->and($ids)->toContain('arena_hp_100')
        ->and($ids)->toContain('arena_elixir_xp_boost');

    // Kamień heroic = 12000 AP; poteka HP 25% mapuje na hp_potion_great.
    $heroic = collect($catalog)->firstWhere('id', 'arena_stone_heroic');
    expect($heroic['apPrice'])->toBe(12000);
    $hp25 = collect($catalog)->firstWhere('id', 'arena_hp_25');
    expect($hp25['payloadId'])->toBe('hp_potion_great');
});

// ---- POST /arena/shop/buy — stones ------------------------------------------

it('buys a stone: spends AP and grants one stone', function () {
    $c = asChar();
    asSave($c, arenaPoints: 500);

    $res = $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/shop/buy", [
            'itemId' => 'arena_stone_rare', 'requestId' => 'as-stone',
        ]);

    $res->assertOk()
        ->assertJsonPath('itemId', 'arena_stone_rare')
        ->assertJsonPath('granted.stoneType', 'rare_stone')
        ->assertJsonPath('arenaPoints', 300); // 500 - 200

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['stones']['rare_stone'])->toBe(1)
        ->and($blob['inventory']['arenaPoints'])->toBe(300);
});

// ---- POST /arena/shop/buy — potions (level gate) ----------------------------

it('rejects a potion above the character level (422) BEFORE spending AP', function () {
    // arena_hp_100 → hp_potion_divine (unlock lvl 700). Postać lvl 300.
    $c = asChar(level: 300);
    asSave($c, arenaPoints: 999999);

    $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/shop/buy", [
            'itemId' => 'arena_hp_100', 'requestId' => 'as-gate',
        ])
        ->assertStatus(422);

    // AP nienaruszone.
    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['arenaPoints'])->toBe(999999);
});

it('buys a potion when the level is high enough', function () {
    // arena_hp_25 → hp_potion_great (unlock lvl 200). Postać lvl 300 OK.
    $c = asChar(level: 300);
    asSave($c, arenaPoints: 500);

    $res = $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/shop/buy", [
            'itemId' => 'arena_hp_25', 'requestId' => 'as-pot',
        ]);

    $res->assertOk()
        ->assertJsonPath('granted.consumableId', 'hp_potion_great')
        ->assertJsonPath('arenaPoints', 200); // 500 - 300

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['consumables']['hp_potion_great'])->toBe(1);
});

// ---- POST /arena/shop/buy — mythic weapon -----------------------------------

it('buys a mythic weapon of the class type, priced level*1000, into the bag', function () {
    $c = asChar(level: 5, class: 'Knight');
    asSave($c, arenaPoints: 10000);

    $res = $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/shop/buy", [
            'itemId' => 'arena_mythic_main', 'requestId' => 'as-mythic',
        ]);

    $res->assertOk()
        ->assertJsonPath('arenaPoints', 5000)          // 10000 - 5*1000
        ->assertJsonPath('granted.kind', 'mythic_weapon')
        ->assertJsonPath('granted.item.rarity', 'mythic');

    // Knight → sword, itemLevel = poziom postaci.
    $item = $res->json('granted.item');
    expect($item['itemId'])->toContain('sword_lvl5_mythic')
        ->and($item['itemLevel'])->toBe(5);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['bag'])->toHaveCount(1);
});

// ---- Errors -----------------------------------------------------------------

it('rejects a buy with insufficient arena points (422)', function () {
    $c = asChar();
    asSave($c, arenaPoints: 10);

    $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/shop/buy", [
            'itemId' => 'arena_stone_rare', 'requestId' => 'as-broke', // 200 AP
        ])
        ->assertStatus(422);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['arenaPoints'])->toBe(10);
});

it('returns 404 for an unknown shop item', function () {
    $c = asChar();
    asSave($c, arenaPoints: 999);

    $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/shop/buy", [
            'itemId' => 'arena_nonexistent', 'requestId' => 'as-404',
        ])
        ->assertNotFound();
});

it('rejects a buy on another user\'s character (403)', function () {
    $c = asChar(AS_USER_B);
    asSave($c, arenaPoints: 999);

    $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/shop/buy", [
            'itemId' => 'arena_stone_rare', 'requestId' => 'as-403',
        ])
        ->assertForbidden();
});

it('is idempotent — replaying a buy requestId does not double-spend', function () {
    $c = asChar();
    asSave($c, arenaPoints: 500);
    $body = ['itemId' => 'arena_stone_rare', 'requestId' => 'as-idem'];

    $first = $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/shop/buy", $body);
    $first->assertOk()->assertJsonPath('arenaPoints', 300);

    $second = $this->withToken(TokenFactory::forUser(AS_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/arena/shop/buy", $body);
    $second->assertOk()->assertJsonPath('arenaPoints', 300);

    // Tylko jeden kamień + AP zeszło raz.
    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['stones']['rare_stone'])->toBe(1)
        ->and($blob['inventory']['arenaPoints'])->toBe(300);
});

it('requires authentication for the arena shop (401)', function () {
    $c = asChar();
    asSave($c);

    $this->getJson("/api/v1/characters/{$c->id}/arena/shop")->assertUnauthorized();
});
