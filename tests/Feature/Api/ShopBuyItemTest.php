<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const SHOP_USER = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
const SHOP_USER_B = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

function shopChar(int $level = 100): Character
{
    return Character::factory()->forUser(SHOP_USER)->create([
        'class' => 'Knight',
        'level' => $level,
    ]);
}

function shopSave(Character $c, int $gold = 0): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => ['_ownerCharacterId' => $c->id, 'inventory' => [
            'gold' => $gold, 'bag' => [], 'equipment' => [], 'deposit' => [],
            'consumables' => [], 'stones' => [], 'arenaPoints' => 0,
        ]],
    ]);
}

function shopToken(): string
{
    return TokenFactory::forUser(SHOP_USER);
}

it('buys a shop weapon: spends gold and adds the generated item to bag', function () {
    $c = shopChar(100);
    shopSave($c, 5000);

    $res = $this->withToken(shopToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-item", [
        'itemId' => 'shop_sword_100_common', 'requestId' => 'buy-1',
    ]);

    $res->assertOk()
        ->assertJson([
            'itemId' => 'shop_sword_100_common',
            'totalPrice' => 3020,
            'gold' => 1980,
        ]);

    expect($res->json('item.itemId'))->toBe('sword_lvl100_common')
        ->and($res->json('item.rarity'))->toBe('common')
        ->and($res->json('item.itemLevel'))->toBe(100);

    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['gold'])->toBe(1980)
        ->and(count($inv['bag']))->toBe(1)
        ->and($inv['bag'][0]['itemId'])->toBe('sword_lvl100_common');
});

it('rejects the buy with insufficient gold (422)', function () {
    $c = shopChar(100);
    shopSave($c, 100);

    $this->withToken(shopToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-item", [
        'itemId' => 'shop_sword_100_common', 'requestId' => 'buy-poor',
    ])->assertStatus(422);

    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['gold'])->toBe(100)->and($inv['bag'])->toBe([]);
});

it('rejects buying above the character level (422 level gate)', function () {
    $c = shopChar(5);
    shopSave($c, 999999);

    $this->withToken(shopToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-item", [
        'itemId' => 'shop_sword_100_common', 'requestId' => 'buy-lowlvl',
    ])->assertStatus(422);

    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['gold'])->toBe(999999)->and($inv['bag'])->toBe([]);
});

it('returns 404 for an item id not in this character\'s shop', function () {
    $c = shopChar(100);
    shopSave($c, 999999);

    $this->withToken(shopToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-item", [
        'itemId' => 'shop_staff_100_common', 'requestId' => 'buy-wrongclass',
    ])->assertStatus(404);

    $this->withToken(shopToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-item", [
        'itemId' => 'totally_bogus', 'requestId' => 'buy-bogus',
    ])->assertStatus(404);
});

it('blocks buying on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(SHOP_USER_B)->create(['class' => 'Knight', 'level' => 100]);

    $this->withToken(shopToken())->postJson("/api/v1/characters/{$other->id}/shop/buy-item", [
        'itemId' => 'shop_sword_100_common', 'requestId' => 'buy-notmine',
    ])->assertForbidden();
});

it('replays the same requestId without buying twice (idempotency)', function () {
    $c = shopChar(100);
    shopSave($c, 5000);

    $first = $this->withToken(shopToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-item", [
        'itemId' => 'shop_sword_100_common', 'requestId' => 'replay-1',
    ]);
    $first->assertOk();

    $second = $this->withToken(shopToken())->postJson("/api/v1/characters/{$c->id}/shop/buy-item", [
        'itemId' => 'shop_sword_100_common', 'requestId' => 'replay-1',
    ]);
    $second->assertOk();

    expect($second->json())->toBe($first->json())
        ->and($second->json('gold'))->toBe(1980);

    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['gold'])->toBe(1980)
        ->and(count($inv['bag']))->toBe(1);
});
