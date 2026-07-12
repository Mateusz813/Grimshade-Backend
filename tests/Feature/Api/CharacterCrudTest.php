<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const CC_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const CC_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function ccToken(string $user = CC_USER): string
{
    return TokenFactory::forUser($user);
}


it('creates a character with server-derived stats and seeds the starter save', function () {
    $res = $this->withToken(ccToken())->postJson('/api/v1/characters', [
        'requestId' => 'req-1',
        'name' => 'Krasek',
        'class' => 'Knight',
    ]);

    $res->assertCreated()
        ->assertJsonPath('name', 'Krasek')
        ->assertJsonPath('class', 'Knight')
        ->assertJsonPath('user_id', CC_USER)
        ->assertJsonPath('hp', 120)
        ->assertJsonPath('max_hp', 120)
        ->assertJsonPath('mp', 30)
        ->assertJsonPath('attack', 10)
        ->assertJsonPath('defense', 5)
        ->assertJsonPath('crit_chance', 0.03)
        ->assertJsonPath('crit_damage', 2)
        ->assertJsonPath('magic_level', 0)
        ->assertJsonPath('level', 1)
        ->assertJsonPath('xp', 0)
        ->assertJsonPath('gold', 0)
        ->assertJsonPath('highest_level', 1);

    $id = $res->json('id');

    $state = GameSave::where('character_id', $id)->firstOrFail()->state;
    expect($state['inventory']['equipment']['mainHand']['itemId'])->toBe('sword_of_beginnings');
    expect($state['inventory']['equipment']['mainHand']['rarity'])->toBe('common');
    expect($state['inventory']['equipment']['mainHand']['bonuses'])->toBe(['attack' => 4, 'dmg_min' => 4, 'dmg_max' => 8]);
    expect($state['inventory']['consumables']['hp_potion_sm'])->toBe(30);
    expect($state['inventory']['consumables']['mp_potion_sm'])->toBe(30);
    expect($state['inventory']['gold'])->toBe(0);
    expect($state['inventory']['bag'])->toBe([]);
});

it('ignores stat fields from the body and derives them server-side', function () {
    $res = $this->withToken(ccToken())->postJson('/api/v1/characters', [
        'requestId' => 'req-cheat',
        'name' => 'Cheater',
        'class' => 'Mage',
        'hp' => 999999,
        'max_hp' => 999999,
        'attack' => 999999,
        'gold' => 999999,
        'level' => 80,
    ]);

    $res->assertCreated()
        ->assertJsonPath('hp', 80)
        ->assertJsonPath('max_hp', 80)
        ->assertJsonPath('mp', 200)
        ->assertJsonPath('attack', 6)
        ->assertJsonPath('gold', 0)
        ->assertJsonPath('level', 1);
});


it('rejects a name that is too short', function () {
    $this->withToken(ccToken())->postJson('/api/v1/characters', [
        'requestId' => 'req-x', 'name' => 'ab', 'class' => 'Knight',
    ])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('rejects a name with special characters or double spaces', function () {
    $this->withToken(ccToken())->postJson('/api/v1/characters', [
        'requestId' => 'req-y', 'name' => 'Bad@Name', 'class' => 'Knight',
    ])->assertStatus(422)->assertJsonValidationErrors('name');

    $this->withToken(ccToken())->postJson('/api/v1/characters', [
        'requestId' => 'req-z', 'name' => 'two  spaces', 'class' => 'Knight',
    ])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('rejects an unknown class', function () {
    $this->withToken(ccToken())->postJson('/api/v1/characters', [
        'requestId' => 'req-c', 'name' => 'Someone', 'class' => 'Paladin',
    ])->assertStatus(422)->assertJsonValidationErrors('class');
});


it('enforces the 7-character cap and rejects the 8th', function () {
    Character::factory()->count(7)->forUser(CC_USER)->create();

    $this->withToken(ccToken())->postJson('/api/v1/characters', [
        'requestId' => 'req-8', 'name' => 'TooMany', 'class' => 'Rogue',
    ])->assertStatus(422)->assertJson(['message' => 'Osiągnięto limit 7 postaci.']);

    expect(Character::where('user_id', CC_USER)->count())->toBe(7);
});


it('replays the same requestId without creating a second character', function () {
    $first = $this->withToken(ccToken())->postJson('/api/v1/characters', [
        'requestId' => 'req-idem', 'name' => 'Once', 'class' => 'Archer',
    ])->assertCreated();

    $second = $this->withToken(ccToken())->postJson('/api/v1/characters', [
        'requestId' => 'req-idem', 'name' => 'Once', 'class' => 'Archer',
    ])->assertCreated();

    expect($second->json())->toBe($first->json());
    expect(Character::where('user_id', CC_USER)->count())->toBe(1);
    expect(GameSave::where('character_id', $first->json('id'))->count())->toBe(1);
});


it('deletes the character with its roster/market rows and game save, keeping chat + guild logs', function () {
    $c = Character::factory()->forUser(CC_USER)->create();

    GameSave::create(['user_id' => CC_USER, 'character_id' => $c->id, 'state' => ['_ownerCharacterId' => $c->id]]);
    DB::table('party_members')->insert(['id' => (string) Str::uuid(), 'party_id' => (string) Str::uuid(), 'character_id' => $c->id]);
    DB::table('guild_members')->insert(['id' => (string) Str::uuid(), 'guild_id' => (string) Str::uuid(), 'character_id' => $c->id, 'character_name' => $c->name, 'character_class' => $c->class]);
    DB::table('guild_boss_contributions')->insert(['id' => (string) Str::uuid(), 'guild_id' => (string) Str::uuid(), 'character_id' => $c->id, 'week_start' => '2026-07-06']);
    DB::table('market_listings')->insert(['id' => (string) Str::uuid(), 'seller_id' => $c->id, 'seller_name' => 'X', 'item_id' => 'i', 'price' => 10]);
    DB::table('market_sale_notifications')->insert(['id' => (string) Str::uuid(), 'seller_id' => $c->id, 'item_id' => 'i']);

    DB::table('messages')->insert(['id' => (string) Str::uuid(), 'user_id' => CC_USER, 'channel' => 'city', 'character_name' => $c->name, 'content' => 'hi']);
    DB::table('guild_treasury_logs')->insert(['id' => (string) Str::uuid(), 'guild_id' => (string) Str::uuid(), 'action' => 'deposit', 'character_id' => $c->id, 'character_name' => $c->name, 'item_name' => 'sword']);

    $this->withToken(ccToken())->deleteJson("/api/v1/characters/{$c->id}")->assertNoContent();

    expect(Character::whereKey($c->id)->exists())->toBeFalse();
    expect(GameSave::where('character_id', $c->id)->exists())->toBeFalse();
    expect(DB::table('party_members')->where('character_id', $c->id)->exists())->toBeFalse();
    expect(DB::table('guild_members')->where('character_id', $c->id)->exists())->toBeFalse();
    expect(DB::table('guild_boss_contributions')->where('character_id', $c->id)->exists())->toBeFalse();
    expect(DB::table('market_listings')->where('seller_id', $c->id)->exists())->toBeFalse();
    expect(DB::table('market_sale_notifications')->where('seller_id', $c->id)->exists())->toBeFalse();
    expect(DB::table('messages')->where('user_id', CC_USER)->exists())->toBeTrue();
    expect(DB::table('guild_treasury_logs')->where('character_id', $c->id)->exists())->toBeTrue();
});

it('blocks deleting another user\'s character (403)', function () {
    $other = Character::factory()->forUser(CC_USER_B)->create();

    $this->withToken(ccToken())->deleteJson("/api/v1/characters/{$other->id}")->assertForbidden();

    expect(Character::whereKey($other->id)->exists())->toBeTrue();
});

it('returns 404 when deleting a non-existent character', function () {
    $this->withToken(ccToken())
        ->deleteJson('/api/v1/characters/'.((string) Str::uuid()))
        ->assertNotFound();
});

it('rejects character creation without a token (401)', function () {
    $this->postJson('/api/v1/characters', [
        'requestId' => 'req-noauth', 'name' => 'Nope', 'class' => 'Knight',
    ])->assertUnauthorized();
});
