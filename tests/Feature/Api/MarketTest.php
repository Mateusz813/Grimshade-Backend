<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use App\Models\MarketListing;
use App\Models\MarketSaleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const MK_USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const MK_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function mkChar(string $userId, array $overrides = []): Character
{
    return Character::factory()->forUser($userId)->create(array_merge(['level' => 50], $overrides));
}

function mkSave(Character $c, int $gold = 0, array $extraInv = []): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id,
        'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => array_merge([
                'gold' => $gold,
                'bag' => [[
                    'uuid' => 'itm-1', 'itemId' => 'generated_rare_lvl50', 'name' => 'Miecz',
                    'slot' => 'mainHand', 'rarity' => 'rare', 'bonuses' => ['attack' => 10],
                    'itemLevel' => 50, 'upgradeLevel' => 2,
                ]],
                'equipment' => [], 'deposit' => [],
                'consumables' => ['hp_potion_sm' => 5],
                'stones' => ['rare_stone' => 20],
                'arenaPoints' => 100,
            ], $extraInv),
            'settings' => ['language' => 'pl'],
        ],
    ]);
}

function mkListing(Character $seller, array $overrides = []): MarketListing
{
    return MarketListing::create(array_merge([
        'seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'kind' => 'item',
        'item_id' => 'generated_rare_lvl50',
        'item_name' => 'Miecz',
        'item_level' => 50,
        'rarity' => 'rare',
        'slot' => 'mainHand',
        'price' => 1000,
        'quantity' => 1,
        'quantity_initial' => 1,
        'bonuses' => ['attack' => 10],
        'upgrade_level' => 2,
        'listed_at' => now(),
    ], $overrides));
}

function mkTokenA(): string
{
    return TokenFactory::forUser(MK_USER_A);
}

function mkTokenB(): string
{
    return TokenFactory::forUser(MK_USER_B);
}


it('lists active listings and filters out empty ones', function () {
    $seller = mkChar(MK_USER_A);
    mkListing($seller, ['item_name' => 'Miecz', 'price' => 1000]);
    mkListing($seller, ['item_name' => 'Tarcza', 'price' => 500, 'quantity' => 0]);

    $res = $this->withToken(mkTokenA())->getJson('/api/v1/market/listings');

    $res->assertOk()->assertJsonCount(1);
    expect($res->json('0.itemName'))->toBe('Miecz')
        ->and($res->json('0.price'))->toBe(1000);
});

it('returns only my listings on the mine endpoint', function () {
    $seller = mkChar(MK_USER_A);
    $other = mkChar(MK_USER_B);
    mkListing($seller, ['item_name' => 'Moje']);
    mkListing($other, ['item_name' => 'Cudze']);

    $res = $this->withToken(mkTokenA())->getJson("/api/v1/characters/{$seller->id}/market/mine");

    $res->assertOk()->assertJsonCount(1);
    expect($res->json('0.itemName'))->toBe('Moje');
});


it('escrows an item: it leaves the bag ATOMICALLY with the listing insert', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 100);

    $res = $this->withToken(mkTokenA())->postJson("/api/v1/characters/{$seller->id}/market/listings", [
        'kind' => 'item', 'itemUuid' => 'itm-1', 'price' => 1000, 'quantity' => 1, 'requestId' => 'list-1',
    ]);

    $res->assertCreated()
        ->assertJsonPath('listing.itemId', 'generated_rare_lvl50')
        ->assertJsonPath('listing.rarity', 'rare')
        ->assertJsonPath('listing.upgradeLevel', 2)
        ->assertJsonPath('listing.quantity', 1);

    $bag = GameSave::where('character_id', $seller->id)->first()->state['inventory']['bag'];
    expect($bag)->toBe([])
        ->and(MarketListing::where('seller_id', $seller->id)->count())->toBe(1);
});

it('escrow does NOT duplicate the item: second list of same uuid is 404', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 100);
    $body = ['kind' => 'item', 'itemUuid' => 'itm-1', 'price' => 1000, 'quantity' => 1];

    $this->withToken(mkTokenA())->postJson("/api/v1/characters/{$seller->id}/market/listings",
        [...$body, 'requestId' => 'list-a'])->assertCreated();

    $this->withToken(mkTokenA())->postJson("/api/v1/characters/{$seller->id}/market/listings",
        [...$body, 'requestId' => 'list-b'])->assertNotFound();

    expect(MarketListing::where('seller_id', $seller->id)->count())->toBe(1);
});

it('escrows a stone stack, decrementing the blob store', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 0);

    $res = $this->withToken(mkTokenA())->postJson("/api/v1/characters/{$seller->id}/market/listings", [
        'kind' => 'stone', 'itemId' => 'rare_stone', 'price' => 50, 'quantity' => 8, 'requestId' => 'list-s',
    ]);

    $res->assertCreated()->assertJsonPath('listing.quantity', 8);
    expect(GameSave::where('character_id', $seller->id)->first()->state['inventory']['stones']['rare_stone'])->toBe(12);
});

it('rejects listing more stones than owned (422, nothing escrowed)', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 0);

    $this->withToken(mkTokenA())->postJson("/api/v1/characters/{$seller->id}/market/listings", [
        'kind' => 'stone', 'itemId' => 'rare_stone', 'price' => 50, 'quantity' => 999, 'requestId' => 'list-x',
    ])->assertStatus(422);

    expect(GameSave::where('character_id', $seller->id)->first()->state['inventory']['stones']['rare_stone'])->toBe(20)
        ->and(MarketListing::count())->toBe(0);
});

it('rejects invalid price (422)', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 0);

    $this->withToken(mkTokenA())->postJson("/api/v1/characters/{$seller->id}/market/listings", [
        'kind' => 'item', 'itemUuid' => 'itm-1', 'price' => 0, 'quantity' => 1, 'requestId' => 'list-p',
    ])->assertStatus(422);
});


it('buy recomputes gold SERVER-side: buyer −total, seller +net(after 5% tax)', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 100);
    $buyer = mkChar(MK_USER_B);
    mkSave($buyer, 5000, ['bag' => []]);
    $listing = mkListing($seller, ['price' => 1000, 'quantity' => 1]);

    $res = $this->withToken(mkTokenB())->postJson(
        "/api/v1/characters/{$buyer->id}/market/listings/{$listing->id}/buy",
        ['quantity' => 1, 'requestId' => 'buy-1'],
    );

    $res->assertOk()
        ->assertJsonPath('totalPaid', 1000)
        ->assertJsonPath('tax', 50)
        ->assertJsonPath('sellerNet', 950)
        ->assertJsonPath('gold', 4000)
        ->assertJsonPath('quantityPurchased', 1)
        ->assertJsonPath('remainingQty', 0);

    $buyerBlob = GameSave::where('character_id', $buyer->id)->first()->state['inventory'];
    expect($buyerBlob['gold'])->toBe(4000)
        ->and($buyerBlob['bag'])->toHaveCount(1)
        ->and($buyerBlob['bag'][0]['itemId'])->toBe('generated_rare_lvl50');

    expect(GameSave::where('character_id', $seller->id)->first()->state['inventory']['gold'])->toBe(1050);

    expect(MarketListing::find($listing->id))->toBeNull();
    $note = MarketSaleNotification::where('seller_id', $seller->id)->first();
    expect($note->gold_received)->toBe(950)->and($note->quantity_sold)->toBe(1);

    expect(Character::find($buyer->id)->market_items_bought)->toBe(1)
        ->and(Character::find($buyer->id)->market_gold_spent)->toBe(1000)
        ->and(Character::find($seller->id)->market_items_sold)->toBe(1)
        ->and(Character::find($seller->id)->market_gold_earned)->toBe(950);
});

it('CANNOT buy without enough gold (422, nothing changes)', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 100);
    $buyer = mkChar(MK_USER_B);
    mkSave($buyer, 10, ['bag' => []]);
    $listing = mkListing($seller, ['price' => 1000, 'quantity' => 1]);

    $this->withToken(mkTokenB())->postJson(
        "/api/v1/characters/{$buyer->id}/market/listings/{$listing->id}/buy",
        ['quantity' => 1, 'requestId' => 'buy-poor'],
    )->assertStatus(422);

    expect(GameSave::where('character_id', $buyer->id)->first()->state['inventory']['gold'])->toBe(10)
        ->and(GameSave::where('character_id', $buyer->id)->first()->state['inventory']['bag'])->toBe([])
        ->and(GameSave::where('character_id', $seller->id)->first()->state['inventory']['gold'])->toBe(100)
        ->and((int) MarketListing::find($listing->id)->quantity)->toBe(1)
        ->and(MarketSaleNotification::count())->toBe(0);
});

it('CANNOT buy the same listing twice — second buy is 404 (no dupe)', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 0);
    $buyer = mkChar(MK_USER_B);
    mkSave($buyer, 5000, ['bag' => []]);
    $listing = mkListing($seller, ['price' => 1000, 'quantity' => 1]);

    $this->withToken(mkTokenB())->postJson(
        "/api/v1/characters/{$buyer->id}/market/listings/{$listing->id}/buy",
        ['quantity' => 1, 'requestId' => 'first'],
    )->assertOk();

    $this->withToken(mkTokenB())->postJson(
        "/api/v1/characters/{$buyer->id}/market/listings/{$listing->id}/buy",
        ['quantity' => 1, 'requestId' => 'second'],
    )->assertNotFound();

    expect(GameSave::where('character_id', $buyer->id)->first()->state['inventory']['gold'])->toBe(4000)
        ->and(GameSave::where('character_id', $buyer->id)->first()->state['inventory']['bag'])->toHaveCount(1);
});

it('buy is idempotent per requestId (no double charge / double item)', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 0);
    $buyer = mkChar(MK_USER_B);
    mkSave($buyer, 5000, ['bag' => []]);
    $listing = mkListing($seller, ['kind' => 'stone', 'item_id' => 'rare_stone', 'price' => 100, 'quantity' => 5, 'quantity_initial' => 5]);
    $body = ['quantity' => 2, 'requestId' => 'idem-1'];

    $this->withToken(mkTokenB())->postJson(
        "/api/v1/characters/{$buyer->id}/market/listings/{$listing->id}/buy", $body)->assertOk();
    $this->withToken(mkTokenB())->postJson(
        "/api/v1/characters/{$buyer->id}/market/listings/{$listing->id}/buy", $body)->assertOk();

    expect(GameSave::where('character_id', $buyer->id)->first()->state['inventory']['gold'])->toBe(4800)
        ->and(GameSave::where('character_id', $buyer->id)->first()->state['inventory']['stones']['rare_stone'])->toBe(22)
        ->and((int) MarketListing::find($listing->id)->quantity)->toBe(3);
});

it('partial buy decrements the stack and transfers only the bought slice', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 0);
    $buyer = mkChar(MK_USER_B);
    mkSave($buyer, 5000, ['bag' => [], 'consumables' => ['hp_potion_sm' => 1]]);
    $listing = mkListing($seller, ['kind' => 'potion', 'item_id' => 'hp_potion_sm', 'price' => 100, 'quantity' => 10, 'quantity_initial' => 10]);

    $res = $this->withToken(mkTokenB())->postJson(
        "/api/v1/characters/{$buyer->id}/market/listings/{$listing->id}/buy",
        ['quantity' => 3, 'requestId' => 'partial'],
    );

    $res->assertOk()->assertJsonPath('remainingQty', 7)->assertJsonPath('totalPaid', 300);
    expect((int) MarketListing::find($listing->id)->quantity)->toBe(7)
        ->and(GameSave::where('character_id', $buyer->id)->first()->state['inventory']['consumables']['hp_potion_sm'])->toBe(4);
});

it('CANNOT buy your own listing (422)', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 5000);
    $listing = mkListing($seller, ['price' => 1000]);

    $this->withToken(mkTokenA())->postJson(
        "/api/v1/characters/{$seller->id}/market/listings/{$listing->id}/buy",
        ['quantity' => 1, 'requestId' => 'self'],
    )->assertStatus(422);

    expect((int) MarketListing::find($listing->id)->quantity)->toBe(1);
});


it('cancels a listing and returns the escrowed item to the seller bag', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 0, ['bag' => []]);
    $listing = mkListing($seller, ['price' => 1000, 'quantity' => 1]);

    $res = $this->withToken(mkTokenA())->deleteJson("/api/v1/characters/{$seller->id}/market/listings/{$listing->id}");

    $res->assertOk()->assertJsonPath('returnedQty', 1);
    expect(MarketListing::find($listing->id))->toBeNull()
        ->and(GameSave::where('character_id', $seller->id)->first()->state['inventory']['bag'])->toHaveCount(1);
});

it('cancelling a nonexistent listing is 404', function () {
    $seller = mkChar(MK_USER_A);
    mkSave($seller, 0);

    $this->withToken(mkTokenA())->deleteJson(
        "/api/v1/characters/{$seller->id}/market/listings/00000000-0000-0000-0000-000000000000"
    )->assertNotFound();
});


it('blocks acting on another user\'s character (403)', function () {
    $seller = mkChar(MK_USER_A);
    $listing = mkListing($seller);

    $this->withToken(mkTokenB())->postJson(
        "/api/v1/characters/{$seller->id}/market/listings/{$listing->id}/buy",
        ['quantity' => 1, 'requestId' => 'x'],
    )->assertForbidden();

    $this->withToken(mkTokenB())->getJson("/api/v1/characters/{$seller->id}/market/mine")->assertForbidden();
});
