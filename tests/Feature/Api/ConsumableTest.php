<?php

declare(strict_types=1);

use App\Domain\Character\StatReset;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const CN_USER = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
const CN_USER_B = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

function cnChar(array $attrs = []): Character
{
    return Character::factory()->forUser(CN_USER)->create(array_merge(
        ['class' => 'Knight', 'level' => 100, 'highest_level' => 100],
        $attrs,
    ));
}

function cnToken(): string
{
    return TokenFactory::forUser(CN_USER);
}

function cnSave(Character $c, array $consumables = []): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => [
                'gold' => 0, 'bag' => [], 'equipment' => [], 'deposit' => [],
                'consumables' => $consumables, 'stones' => [], 'arenaPoints' => 0,
            ],
        ],
    ]);
}

it('converts potions: consumes inputCount*batches, produces batches (FREE)', function () {
    $c = cnChar();
    cnSave($c, ['hp_potion_sm' => 12]);

    $res = $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/potions/convert",
        ['inputId' => 'hp_potion_sm', 'batches' => 2, 'requestId' => 'cv-1'],
    );

    $res->assertOk()
        ->assertJsonPath('inputId', 'hp_potion_sm')
        ->assertJsonPath('outputId', 'hp_potion_md')
        ->assertJsonPath('produced', 2)
        ->assertJsonPath('consumed', 10);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['consumables']['hp_potion_sm'])->toBe(2)
        ->and($blob['inventory']['consumables']['hp_potion_md'])->toBe(2);
});

it('disambiguates a shared inputId via outputId (lg -> mega branch)', function () {
    $c = cnChar();
    cnSave($c, ['hp_potion_lg' => 25]);

    $res = $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/potions/convert",
        ['inputId' => 'hp_potion_lg', 'outputId' => 'hp_potion_mega', 'batches' => 1, 'requestId' => 'cv-mega'],
    );

    $res->assertOk()
        ->assertJsonPath('outputId', 'hp_potion_mega')
        ->assertJsonPath('consumed', 25)
        ->assertJsonPath('produced', 1);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['consumables']['hp_potion_lg'])->toBe(0)
        ->and($blob['inventory']['consumables']['hp_potion_mega'])->toBe(1);
});

it('rejects convert with insufficient inputs (422) and mutates nothing', function () {
    $c = cnChar();
    cnSave($c, ['hp_potion_sm' => 9]);

    $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/potions/convert",
        ['inputId' => 'hp_potion_sm', 'batches' => 2, 'requestId' => 'cv-2'],
    )->assertStatus(422);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['consumables']['hp_potion_sm'])->toBe(9)
        ->and($blob['inventory']['consumables']['hp_potion_md'] ?? 0)->toBe(0);
});

it('rejects convert when the output tier is level-locked (422)', function () {
    $c = cnChar(['level' => 1]);
    cnSave($c, ['hp_potion_lg' => 334]);

    $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/potions/convert",
        ['inputId' => 'hp_potion_lg', 'outputId' => 'hp_potion_great', 'batches' => 1, 'requestId' => 'cv-lock'],
    )->assertStatus(422);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['consumables']['hp_potion_lg'])->toBe(334)
        ->and($blob['inventory']['consumables']['hp_potion_great'] ?? 0)->toBe(0);
});

it('rejects convert for an unknown recipe (422)', function () {
    $c = cnChar();
    cnSave($c, ['hp_potion_mega' => 100]);

    $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/potions/convert",
        ['inputId' => 'hp_potion_mega', 'batches' => 1, 'requestId' => 'cv-x'],
    )->assertStatus(422);
});

it('convert is idempotent per requestId (no double apply)', function () {
    $c = cnChar();
    cnSave($c, ['hp_potion_sm' => 12]);

    $one = $this->withToken(cnToken())->postJson("/api/v1/characters/{$c->id}/potions/convert", ['inputId' => 'hp_potion_sm', 'batches' => 2, 'requestId' => 'cv-dup'])->json();
    $two = $this->withToken(cnToken())->postJson("/api/v1/characters/{$c->id}/potions/convert", ['inputId' => 'hp_potion_sm', 'batches' => 2, 'requestId' => 'cv-dup'])->json();

    expect($two)->toBe($one);
    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['consumables']['hp_potion_sm'])->toBe(2);
});

it('uses a consumable: decrements the stack by exactly 1', function () {
    $c = cnChar();
    cnSave($c, ['xp_boost' => 3]);

    $res = $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/consumables/use",
        ['consumableId' => 'xp_boost', 'requestId' => 'use-1'],
    );

    $res->assertOk()
        ->assertJsonPath('consumableId', 'xp_boost')
        ->assertJsonPath('consumables.xp_boost', 2);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['consumables']['xp_boost'])->toBe(2);
});

it('rejects use with an empty stack (422)', function () {
    $c = cnChar();
    cnSave($c, ['xp_boost' => 0]);

    $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/consumables/use",
        ['consumableId' => 'xp_boost', 'requestId' => 'use-empty'],
    )->assertStatus(422);
});

it('rejects using the stat_reset elixir via /use (422 → use stat-reset)', function () {
    $c = cnChar();
    cnSave($c, ['stat_reset' => 5]);

    $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/consumables/use",
        ['consumableId' => 'stat_reset', 'requestId' => 'use-sr'],
    )->assertStatus(422);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['consumables']['stat_reset'])->toBe(5);
});

it('use is idempotent per requestId (no double decrement)', function () {
    $c = cnChar();
    cnSave($c, ['xp_boost' => 3]);

    $one = $this->withToken(cnToken())->postJson("/api/v1/characters/{$c->id}/consumables/use", ['consumableId' => 'xp_boost', 'requestId' => 'use-dup'])->json();
    $two = $this->withToken(cnToken())->postJson("/api/v1/characters/{$c->id}/consumables/use", ['consumableId' => 'xp_boost', 'requestId' => 'use-dup'])->json();

    expect($two)->toBe($one);
    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['consumables']['xp_boost'])->toBe(2);
});

it('resets stats to class base + consumes the stat_reset elixir', function () {
    $c = cnChar(['hp' => 5000, 'mp' => 5000]);
    cnSave($c, ['stat_reset' => 2]);

    $expected = StatReset::compute('Knight', currentHp: 5000, currentMp: 5000, highestLevel: 100);

    $res = $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/character/stat-reset",
        ['consumableId' => 'stat_reset', 'requestId' => 'sr-1'],
    );

    $res->assertOk()
        ->assertJsonPath('character.attack', $expected['attack'])
        ->assertJsonPath('character.max_hp', $expected['max_hp'])
        ->assertJsonPath('character.max_mp', $expected['max_mp'])
        ->assertJsonPath('character.hp', $expected['hp'])
        ->assertJsonPath('character.mp', $expected['mp'])
        ->assertJsonPath('character.stat_points', $expected['stat_points'])
        ->assertJsonPath('consumables.stat_reset', 1);

    $fresh = Character::find($c->id);
    expect($fresh->max_hp)->toBe(942)
        ->and($fresh->max_mp)->toBe(238)
        ->and($fresh->stat_points)->toBe(10)
        ->and($fresh->attack)->toBe(12)
        ->and($fresh->defense)->toBe(8);
    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['consumables']['stat_reset'])->toBe(1);
});

it('rejects stat-reset when the elixir stack is empty (422) and mutates nothing', function () {
    $c = cnChar(['hp' => 5000, 'mp' => 5000, 'attack' => 777, 'stat_points' => 3]);
    cnSave($c, []);

    $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$c->id}/character/stat-reset",
        ['consumableId' => 'stat_reset', 'requestId' => 'sr-empty'],
    )->assertStatus(422);

    $fresh = Character::find($c->id);
    expect($fresh->attack)->toBe(777)
        ->and($fresh->stat_points)->toBe(3);
});

it('stat-reset is idempotent per requestId (no double consume)', function () {
    $c = cnChar(['hp' => 5000, 'mp' => 5000]);
    cnSave($c, ['stat_reset' => 2]);

    $one = $this->withToken(cnToken())->postJson("/api/v1/characters/{$c->id}/character/stat-reset", ['consumableId' => 'stat_reset', 'requestId' => 'sr-dup'])->json();
    $two = $this->withToken(cnToken())->postJson("/api/v1/characters/{$c->id}/character/stat-reset", ['consumableId' => 'stat_reset', 'requestId' => 'sr-dup'])->json();

    expect($two)->toBe($one);
    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['consumables']['stat_reset'])->toBe(1);
});

it('blocks acting on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(CN_USER_B)->create(['class' => 'Knight', 'level' => 100]);

    $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$other->id}/potions/convert",
        ['inputId' => 'hp_potion_sm', 'batches' => 1, 'requestId' => 'cv-403'],
    )->assertForbidden();

    $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$other->id}/consumables/use",
        ['consumableId' => 'xp_boost', 'requestId' => 'use-403'],
    )->assertForbidden();

    $this->withToken(cnToken())->postJson(
        "/api/v1/characters/{$other->id}/character/stat-reset",
        ['requestId' => 'sr-403'],
    )->assertForbidden();
});
