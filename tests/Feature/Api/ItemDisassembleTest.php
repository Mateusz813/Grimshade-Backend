<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const ITM_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const ITM_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

/**
 * Mulberry32 (ten sam algorytm co front). Wartości nextFloat() dla seedów:
 *  - seed 7  → 0.0117, 0.062,  0.9769, 0.699  (pierwszy roll < 0.20 → kamień)
 *  - seed 1  → 0.6271, 0.0027, 0.5274, 0.9811 (pierwszy roll >= 0.20 → brak)
 *  - seed 3  → 0.7202, 0.0387, 0.4562, 0.0749 (skip, kamień, ...)
 */
function itmBind(int $seed): void
{
    app()->bind(RngInterface::class, fn () => new Mulberry32Rng($seed));
}

function itmChar(int $level = 100, string $userId = ITM_USER): Character
{
    return Character::factory()->forUser($userId)->create(['level' => $level]);
}

/**
 * @param  array<int, array<string, mixed>>  $bag
 * @param  array<string, int>  $stones
 */
function itmSave(Character $c, array $bag, array $stones = [], int $gold = 0): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => ['_ownerCharacterId' => $c->id, 'inventory' => [
            'gold' => $gold, 'bag' => $bag, 'equipment' => [], 'deposit' => [],
            'consumables' => [], 'stones' => $stones, 'arenaPoints' => 0,
        ]],
    ]);
}

function itmItem(string $uuid, string $rarity = 'rare', array $bonuses = ['hp' => 100], string $itemId = 'heavy_helmet_lvl50_rare'): array
{
    return ['uuid' => $uuid, 'itemId' => $itemId, 'rarity' => $rarity, 'bonuses' => $bonuses, 'itemLevel' => 50, 'upgradeLevel' => 0];
}

function itmToken(): string
{
    return TokenFactory::forUser(ITM_USER);
}

// ---- disassemble (single) ---------------------------------------------------

it('disassembles an item and grants a stone on a low roll', function () {
    itmBind(7); // pierwszy roll 0.0117 < 0.20 → kamień
    $c = itmChar();
    itmSave($c, [itmItem('itm-1', 'rare')]);

    $res = $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/items/disassemble", [
        'itemUuid' => 'itm-1', 'requestId' => 'req-d1',
    ]);

    $res->assertOk()
        ->assertJson(['success' => true, 'stoneGained' => true, 'stoneType' => 'rare_stone']);
    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['bag'])->toBe([])
        ->and($inv['stones']['rare_stone'])->toBe(1);
});

it('disassembles an item without a stone on a high roll (item still consumed)', function () {
    itmBind(1); // pierwszy roll 0.6271 >= 0.20 → brak kamienia
    $c = itmChar();
    itmSave($c, [itmItem('itm-1', 'epic')]);

    $res = $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/items/disassemble", [
        'itemUuid' => 'itm-1', 'requestId' => 'req-d2',
    ]);

    $res->assertOk()->assertJson(['success' => true, 'stoneGained' => false, 'stoneType' => 'epic_stone']);
    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['bag'])->toBe([])
        ->and($inv['stones']['epic_stone'] ?? 0)->toBe(0);
});

it('returns 404 disassembling an item not in the bag', function () {
    itmBind(7);
    $c = itmChar();
    itmSave($c, []);

    $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/items/disassemble", [
        'itemUuid' => 'ghost', 'requestId' => 'req-d3',
    ])->assertNotFound();
});

it('replays disassemble idempotently without double-applying', function () {
    itmBind(7);
    $c = itmChar();
    itmSave($c, [itmItem('itm-1', 'rare')]);

    $first = $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/items/disassemble", [
        'itemUuid' => 'itm-1', 'requestId' => 'req-same',
    ])->assertOk()->json();

    $second = $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/items/disassemble", [
        'itemUuid' => 'itm-1', 'requestId' => 'req-same',
    ])->assertOk()->json();

    expect($second)->toBe($first);
    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    // Kamień naliczony DOKŁADNIE raz (replay z cache, brak podwójnej aplikacji).
    expect($inv['stones']['rare_stone'])->toBe(1);
});

// ---- disassemble-mass -------------------------------------------------------

it('mass-disassembles: consumes all listed items and aggregates stones', function () {
    itmBind(3); // rolls: 0.7202 (skip), 0.0387 (kamień) — kolejność torby
    $c = itmChar();
    itmSave($c, [itmItem('a', 'rare'), itmItem('b', 'rare')]);

    $res = $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/items/disassemble-mass", [
        'itemUuids' => ['a', 'b'], 'requestId' => 'req-m1',
    ]);

    $res->assertOk()->assertJson(['disassembled' => 2, 'stonesGained' => ['rare_stone' => 1]]);
    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['bag'])->toBe([])
        ->and($inv['stones']['rare_stone'])->toBe(1);
});

// ---- reroll -----------------------------------------------------------------

it('rerolls item bonuses, preserves base stat, and consumes 2 stones', function () {
    itmBind(7);
    $c = itmChar();
    itmSave($c, [itmItem('itm-1', 'rare', ['hp' => 100, 'speed' => 5])], ['rare_stone' => 3]);

    $res = $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/items/reroll", [
        'itemUuid' => 'itm-1', 'requestId' => 'req-r1',
    ]);

    $res->assertOk()->assertJson(['stonesUsed' => 2, 'stoneType' => 'rare_stone']);
    $item = $res->json('item');
    expect($item['bonuses']['hp'])->toBe(100)   // bazowy stat slotu 'helmet' zachowany
        ->and(count($item['bonuses']))->toBe(2); // hp + 1 nowy bonus (rare → 1 slot)
    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['stones']['rare_stone'])->toBe(1); // 3 - 2
});

it('rejects reroll for a common item (422)', function () {
    itmBind(7);
    $c = itmChar();
    itmSave($c, [itmItem('itm-1', 'common', ['hp' => 50], 'heavy_helmet_lvl50_common')], ['common_stone' => 10]);

    $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/items/reroll", [
        'itemUuid' => 'itm-1', 'requestId' => 'req-r2',
    ])->assertStatus(422);
});

it('rejects reroll with insufficient stones (422)', function () {
    itmBind(7);
    $c = itmChar();
    itmSave($c, [itmItem('itm-1', 'rare')], ['rare_stone' => 1]); // < 2

    $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/items/reroll", [
        'itemUuid' => 'itm-1', 'requestId' => 'req-r3',
    ])->assertStatus(422);
});

// ---- stones/convert ---------------------------------------------------------

it('converts 100 stones + 1000 gold into 1 higher-tier stone', function () {
    $c = itmChar();
    itmSave($c, [], ['common_stone' => 100], 1000);

    $res = $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/stones/convert", [
        'stoneType' => 'common_stone', 'requestId' => 'req-c1',
    ]);

    $res->assertOk()->assertJson(['stoneType' => 'common_stone', 'higherStoneType' => 'rare_stone', 'gold' => 0]);
    $inv = GameSave::where('character_id', $c->id)->first()->state['inventory'];
    expect($inv['stones']['common_stone'])->toBe(0)
        ->and($inv['stones']['rare_stone'])->toBe(1)
        ->and($inv['gold'])->toBe(0);
});

it('rejects converting the top-tier stone (no higher tier, 422)', function () {
    $c = itmChar();
    itmSave($c, [], ['heroic_stone' => 100], 1000);

    $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/stones/convert", [
        'stoneType' => 'heroic_stone', 'requestId' => 'req-c2',
    ])->assertStatus(422);
});

it('rejects convert with insufficient stones (422)', function () {
    $c = itmChar();
    itmSave($c, [], ['common_stone' => 99], 1000);

    $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/stones/convert", [
        'stoneType' => 'common_stone', 'requestId' => 'req-c3',
    ])->assertStatus(422);
});

it('rejects convert with insufficient gold (422)', function () {
    $c = itmChar();
    itmSave($c, [], ['common_stone' => 100], 999);

    $this->withToken(itmToken())->postJson("/api/v1/characters/{$c->id}/stones/convert", [
        'stoneType' => 'common_stone', 'requestId' => 'req-c4',
    ])->assertStatus(422);
});

it('blocks item ops on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(ITM_USER_B)->create();

    $this->withToken(itmToken())->postJson("/api/v1/characters/{$other->id}/items/disassemble", [
        'itemUuid' => 'x', 'requestId' => 'req-x',
    ])->assertForbidden();
});
