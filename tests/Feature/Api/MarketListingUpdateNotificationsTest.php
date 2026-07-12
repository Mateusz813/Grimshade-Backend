<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\MarketListing;
use App\Models\MarketSaleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const MUN_USER_A = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
const MUN_USER_B = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

function munChar(string $userId, array $overrides = []): Character
{
    return Character::factory()->forUser($userId)->create(array_merge(['level' => 50], $overrides));
}

function munListing(Character $seller, array $overrides = []): MarketListing
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

function munNote(Character $seller, array $overrides = []): MarketSaleNotification
{
    return MarketSaleNotification::create(array_merge([
        'seller_id' => $seller->id,
        'item_id' => 'generated_rare_lvl50',
        'item_name' => 'Miecz',
        'rarity' => 'rare',
        'quantity_sold' => 1,
        'gold_received' => 950,
        'sold_at' => now(),
        'seen' => false,
    ], $overrides));
}

function munTokenA(): string
{
    return TokenFactory::forUser(MUN_USER_A);
}

function munTokenB(): string
{
    return TokenFactory::forUser(MUN_USER_B);
}


it('updates price and quantity of my own listing', function () {
    $seller = munChar(MUN_USER_A);
    $listing = munListing($seller, ['kind' => 'stone', 'item_id' => 'rare_stone', 'price' => 100, 'quantity' => 5, 'quantity_initial' => 5]);

    $res = $this->withToken(munTokenA())->putJson(
        "/api/v1/characters/{$seller->id}/market/listings/{$listing->id}",
        ['price' => 250, 'quantity' => 3, 'requestId' => 'upd-1'],
    );

    $res->assertOk()
        ->assertJsonPath('listing.price', 250)
        ->assertJsonPath('listing.quantity', 3);

    $fresh = MarketListing::find($listing->id);
    expect((int) $fresh->price)->toBe(250)->and((int) $fresh->quantity)->toBe(3);
});

it('updates only price when quantity omitted', function () {
    $seller = munChar(MUN_USER_A);
    $listing = munListing($seller, ['price' => 1000, 'quantity' => 1]);

    $this->withToken(munTokenA())->putJson(
        "/api/v1/characters/{$seller->id}/market/listings/{$listing->id}",
        ['price' => 777, 'requestId' => 'upd-price'],
    )->assertOk()->assertJsonPath('listing.price', 777)->assertJsonPath('listing.quantity', 1);
});

it('rejects invalid price on update (422, nothing changes)', function () {
    $seller = munChar(MUN_USER_A);
    $listing = munListing($seller, ['price' => 1000]);

    $this->withToken(munTokenA())->putJson(
        "/api/v1/characters/{$seller->id}/market/listings/{$listing->id}",
        ['price' => 0, 'requestId' => 'upd-bad'],
    )->assertStatus(422);

    expect((int) MarketListing::find($listing->id)->price)->toBe(1000);
});

it('rejects invalid quantity on update (422)', function () {
    $seller = munChar(MUN_USER_A);
    $listing = munListing($seller, ['kind' => 'stone', 'item_id' => 'rare_stone', 'quantity' => 5, 'quantity_initial' => 5]);

    $this->withToken(munTokenA())->putJson(
        "/api/v1/characters/{$seller->id}/market/listings/{$listing->id}",
        ['quantity' => 0, 'requestId' => 'upd-q'],
    )->assertStatus(422);

    expect((int) MarketListing::find($listing->id)->quantity)->toBe(5);
});

it('cannot update someone else\'s listing (403)', function () {
    $seller = munChar(MUN_USER_A);
    $other = munChar(MUN_USER_B);
    $listing = munListing($other, ['price' => 1000]);

    $this->withToken(munTokenA())->putJson(
        "/api/v1/characters/{$seller->id}/market/listings/{$listing->id}",
        ['price' => 5, 'requestId' => 'upd-forbid'],
    )->assertForbidden();

    expect((int) MarketListing::find($listing->id)->price)->toBe(1000);
});

it('updating a nonexistent listing is 404', function () {
    $seller = munChar(MUN_USER_A);

    $this->withToken(munTokenA())->putJson(
        "/api/v1/characters/{$seller->id}/market/listings/00000000-0000-0000-0000-000000000000",
        ['price' => 500, 'requestId' => 'upd-404'],
    )->assertNotFound();
});

it('update is idempotent per requestId (replay returns cached, no re-apply)', function () {
    $seller = munChar(MUN_USER_A);
    $listing = munListing($seller, ['price' => 1000]);
    $body = ['price' => 300, 'requestId' => 'upd-idem'];

    $this->withToken(munTokenA())->putJson(
        "/api/v1/characters/{$seller->id}/market/listings/{$listing->id}", $body)->assertOk();

    MarketListing::where('id', $listing->id)->update(['price' => 999]);

    $this->withToken(munTokenA())->putJson(
        "/api/v1/characters/{$seller->id}/market/listings/{$listing->id}", $body)
        ->assertOk()->assertJsonPath('listing.price', 300);
});


it('lists my unseen sale notifications, newest first', function () {
    $seller = munChar(MUN_USER_A);
    $other = munChar(MUN_USER_B);
    munNote($seller, ['item_name' => 'Stary', 'sold_at' => now()->subHour()]);
    munNote($seller, ['item_name' => 'Nowy', 'sold_at' => now()]);
    munNote($seller, ['item_name' => 'Odczytany', 'seen' => true]);
    munNote($other, ['item_name' => 'Cudze']);

    $res = $this->withToken(munTokenA())->getJson("/api/v1/characters/{$seller->id}/market/notifications");

    $res->assertOk()->assertJsonCount(2, 'notifications');
    expect($res->json('notifications.0.itemName'))->toBe('Nowy')
        ->and($res->json('notifications.1.itemName'))->toBe('Stary')
        ->and($res->json('notifications.0.goldReceived'))->toBe(950);
});


it('dismisses my notification (marks it seen)', function () {
    $seller = munChar(MUN_USER_A);
    $note = munNote($seller);

    $res = $this->withToken(munTokenA())->postJson(
        "/api/v1/characters/{$seller->id}/market/notifications/{$note->id}/dismiss",
        ['requestId' => 'dis-1'],
    );

    $res->assertOk()->assertJsonPath('dismissed', $note->id);
    expect(MarketSaleNotification::find($note->id)->seen)->toBeTrue();

    $this->withToken(munTokenA())->getJson("/api/v1/characters/{$seller->id}/market/notifications")
        ->assertOk()->assertJsonCount(0, 'notifications');
});

it('cannot dismiss someone else\'s notification (403)', function () {
    $seller = munChar(MUN_USER_A);
    $other = munChar(MUN_USER_B);
    $note = munNote($other);

    $this->withToken(munTokenA())->postJson(
        "/api/v1/characters/{$seller->id}/market/notifications/{$note->id}/dismiss",
        ['requestId' => 'dis-forbid'],
    )->assertForbidden();

    expect(MarketSaleNotification::find($note->id)->seen)->toBeFalse();
});

it('dismissing a nonexistent notification is 404', function () {
    $seller = munChar(MUN_USER_A);

    $this->withToken(munTokenA())->postJson(
        "/api/v1/characters/{$seller->id}/market/notifications/00000000-0000-0000-0000-000000000000/dismiss",
        ['requestId' => 'dis-404'],
    )->assertNotFound();
});

it('dismiss is idempotent per requestId (replay returns cached result)', function () {
    $seller = munChar(MUN_USER_A);
    $note = munNote($seller);
    $body = ['requestId' => 'dis-idem'];

    $this->withToken(munTokenA())->postJson(
        "/api/v1/characters/{$seller->id}/market/notifications/{$note->id}/dismiss", $body)
        ->assertOk()->assertJsonPath('dismissed', $note->id);

    MarketSaleNotification::where('id', $note->id)->delete();

    $this->withToken(munTokenA())->postJson(
        "/api/v1/characters/{$seller->id}/market/notifications/{$note->id}/dismiss", $body)
        ->assertOk()->assertJsonPath('dismissed', $note->id);
});

it('blocks acting on another user\'s character for notifications (403)', function () {
    $seller = munChar(MUN_USER_A);

    $this->withToken(munTokenB())->getJson("/api/v1/characters/{$seller->id}/market/notifications")
        ->assertForbidden();
});
