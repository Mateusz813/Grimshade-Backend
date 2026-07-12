<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const INV_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const INV_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function invChar(int $level = 100): Character
{
    return Character::factory()->forUser(INV_USER)->create(['level' => $level]);
}

function invSave(Character $c, int $itemLevel = 50): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => ['_ownerCharacterId' => $c->id, 'inventory' => [
            'gold' => 0,
            'bag' => [['uuid' => 'helm-1', 'itemId' => "heavy_helmet_lvl{$itemLevel}_rare", 'rarity' => 'rare', 'bonuses' => ['hp' => 100], 'itemLevel' => $itemLevel, 'upgradeLevel' => 0]],
            'equipment' => [], 'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0,
        ]],
    ]);
}

function invToken(): string
{
    return TokenFactory::forUser(INV_USER);
}

it('equips an item from bag into the slot', function () {
    $c = invChar(100);
    invSave($c, 50);

    $res = $this->withToken(invToken())->postJson("/api/v1/characters/{$c->id}/inventory/equip", [
        'itemUuid' => 'helm-1', 'slot' => 'helmet',
    ]);

    $res->assertOk();
    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['bag'])->toBe([])
        ->and($inv['equipment']['helmet']['uuid'])->toBe('helm-1');
});

it('swaps: equipping into occupied slot returns previous item to bag', function () {
    $c = invChar(100);
    $save = invSave($c, 50);
    $s = $save->state;
    $s['inventory']['bag'][] = ['uuid' => 'helm-2', 'itemId' => 'heavy_helmet_lvl60_epic', 'rarity' => 'epic', 'bonuses' => ['hp' => 200], 'itemLevel' => 60, 'upgradeLevel' => 0];
    $save->state = $s;
    $save->save();

    $this->withToken(invToken())->postJson("/api/v1/characters/{$c->id}/inventory/equip", ['itemUuid' => 'helm-1', 'slot' => 'helmet'])->assertOk();
    $this->withToken(invToken())->postJson("/api/v1/characters/{$c->id}/inventory/equip", ['itemUuid' => 'helm-2', 'slot' => 'helmet'])->assertOk();

    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['equipment']['helmet']['uuid'])->toBe('helm-2')
        ->and(collect($inv['bag'])->pluck('uuid')->all())->toBe(['helm-1']);
});

it('rejects equipping above the character level (422)', function () {
    $c = invChar(10);
    invSave($c, 50);

    $this->withToken(invToken())->postJson("/api/v1/characters/{$c->id}/inventory/equip", [
        'itemUuid' => 'helm-1', 'slot' => 'helmet',
    ])->assertStatus(422);
});

it('rejects an invalid slot (422 validation)', function () {
    $c = invChar();
    invSave($c);

    $this->withToken(invToken())->postJson("/api/v1/characters/{$c->id}/inventory/equip", [
        'itemUuid' => 'helm-1', 'slot' => 'not_a_slot',
    ])->assertStatus(422);
});

it('unequips back to bag', function () {
    $c = invChar(100);
    invSave($c, 50);
    $this->withToken(invToken())->postJson("/api/v1/characters/{$c->id}/inventory/equip", ['itemUuid' => 'helm-1', 'slot' => 'helmet'])->assertOk();

    $this->withToken(invToken())->postJson("/api/v1/characters/{$c->id}/inventory/unequip", ['slot' => 'helmet'])->assertOk();

    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['equipment']['helmet'])->toBeNull()
        ->and(collect($inv['bag'])->pluck('uuid')->all())->toBe(['helm-1']);
});

it('moves items to deposit and back', function () {
    $c = invChar(100);
    invSave($c, 50);

    $this->withToken(invToken())->postJson("/api/v1/characters/{$c->id}/inventory/deposit", ['itemUuid' => 'helm-1'])->assertOk();
    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['bag'])->toBe([])->and(collect($inv['deposit'])->pluck('uuid')->all())->toBe(['helm-1']);

    $this->withToken(invToken())->postJson("/api/v1/characters/{$c->id}/inventory/withdraw", ['itemUuid' => 'helm-1'])->assertOk();
    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['deposit'])->toBe([])->and(collect($inv['bag'])->pluck('uuid')->all())->toBe(['helm-1']);
});

it('blocks inventory ops on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(INV_USER_B)->create();

    $this->withToken(invToken())->postJson("/api/v1/characters/{$other->id}/inventory/equip", [
        'itemUuid' => 'x', 'slot' => 'helmet',
    ])->assertForbidden();
});
