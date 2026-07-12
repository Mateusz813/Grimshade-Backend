<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const SU_USER = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
const SU_USER_B = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

function suChar(int $level = 100): Character
{
    return Character::factory()->forUser(SU_USER)->create(['class' => 'Knight', 'level' => $level]);
}

function suToken(): string
{
    return TokenFactory::forUser(SU_USER);
}

function suSave(Character $c, int $gold, array $consumables = [], array $skills = []): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => [
                'gold' => $gold, 'bag' => [], 'equipment' => [], 'deposit' => [],
                'consumables' => $consumables, 'stones' => [], 'arenaPoints' => 0,
            ],
            'skills' => $skills,
        ],
    ]);
}

it('unlocks a skill: 1 spell chest + gold deducted, flag set', function () {
    $c = suChar();
    suSave($c, gold: 1000, consumables: ['spell_chest_5' => 3]);

    $res = $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/shield_bash/unlock",
        ['requestId' => 'un-1'],
    );

    $res->assertOk()
        ->assertJsonPath('skillId', 'shield_bash')
        ->assertJsonPath('gold', 638)
        ->assertJsonPath('skills.unlockedSkills.shield_bash', true);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(638)
        ->and($blob['inventory']['consumables']['spell_chest_5'])->toBe(2)
        ->and($blob['skills']['unlockedSkills']['shield_bash'])->toBeTrue();
});

it('rejects unlock when character level is below unlockLevel (422), mutates nothing', function () {
    $c = suChar(level: 4);
    suSave($c, gold: 1000, consumables: ['spell_chest_5' => 3]);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/shield_bash/unlock",
        ['requestId' => 'un-2'],
    )->assertStatus(422);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(1000)
        ->and($blob['inventory']['consumables']['spell_chest_5'])->toBe(3)
        ->and($blob['skills']['unlockedSkills'] ?? [])->toBe([]);
});

it('rejects unlock with insufficient gold (422), mutates nothing', function () {
    $c = suChar();
    suSave($c, gold: 100, consumables: ['spell_chest_5' => 3]);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/shield_bash/unlock",
        ['requestId' => 'un-3'],
    )->assertStatus(422);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(100)
        ->and($blob['inventory']['consumables']['spell_chest_5'])->toBe(3)
        ->and($blob['skills']['unlockedSkills'] ?? [])->toBe([]);
});

it('rejects unlock with no spell chest (422)', function () {
    $c = suChar();
    suSave($c, gold: 100000, consumables: []);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/shield_bash/unlock",
        ['requestId' => 'un-4'],
    )->assertStatus(422);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(100000);
});

it('404 for an unknown skill id on unlock', function () {
    $c = suChar();
    suSave($c, gold: 100000, consumables: ['spell_chest_5' => 3]);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/nope_skill/unlock",
        ['requestId' => 'un-x'],
    )->assertNotFound();
});

it('unlock is idempotent per requestId (no double spend)', function () {
    $c = suChar();
    suSave($c, gold: 1000, consumables: ['spell_chest_5' => 3]);

    $one = $this->withToken(suToken())->postJson("/api/v1/characters/{$c->id}/skills/shield_bash/unlock", ['requestId' => 'dup'])->json();
    $two = $this->withToken(suToken())->postJson("/api/v1/characters/{$c->id}/skills/shield_bash/unlock", ['requestId' => 'dup'])->json();

    expect($two)->toBe($one);
    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(638)
        ->and($blob['inventory']['consumables']['spell_chest_5'])->toBe(2);
});

it('already-unlocked skill costs nothing on a fresh request', function () {
    $c = suChar();
    suSave($c, gold: 1000, consumables: ['spell_chest_5' => 3], skills: [
        'unlockedSkills' => ['shield_bash' => true],
    ]);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/shield_bash/unlock",
        ['requestId' => 'un-again'],
    )->assertOk()->assertJsonPath('skills.unlockedSkills.shield_bash', true);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(1000)
        ->and($blob['inventory']['consumables']['spell_chest_5'])->toBe(3);
});

it('assigns an unlocked skill to a slot', function () {
    $c = suChar();
    suSave($c, gold: 0, skills: ['unlockedSkills' => ['shield_bash' => true]]);

    $res = $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/slot",
        ['slot' => 2, 'skillId' => 'shield_bash', 'requestId' => 'sl-1'],
    );

    $res->assertOk()
        ->assertJsonPath('slot', 2)
        ->assertJsonPath('skillId', 'shield_bash')
        ->assertJsonPath('skills.activeSkillSlots', [null, null, 'shield_bash', null]);

    $slots = GameSave::where('character_id', $c->id)->first()->state['skills']['activeSkillSlots'];
    expect($slots)->toBe([null, null, 'shield_bash', null]);
});

it('clears a slot when skillId is null', function () {
    $c = suChar();
    suSave($c, gold: 0, skills: [
        'unlockedSkills' => ['shield_bash' => true],
        'activeSkillSlots' => ['shield_bash', null, null, null],
    ]);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/slot",
        ['slot' => 0, 'skillId' => null, 'requestId' => 'sl-2'],
    )->assertOk()->assertJsonPath('skills.activeSkillSlots', [null, null, null, null]);
});

it('moving a skill to a new slot clears its previous slot (no double occupancy)', function () {
    $c = suChar();
    suSave($c, gold: 0, skills: [
        'unlockedSkills' => ['shield_bash' => true],
        'activeSkillSlots' => ['shield_bash', null, null, null],
    ]);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/slot",
        ['slot' => 3, 'skillId' => 'shield_bash', 'requestId' => 'sl-3'],
    )->assertOk()->assertJsonPath('skills.activeSkillSlots', [null, null, null, 'shield_bash']);
});

it('rejects assigning a not-unlocked skill (422)', function () {
    $c = suChar();
    suSave($c, gold: 0, skills: []);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/slot",
        ['slot' => 0, 'skillId' => 'shield_bash', 'requestId' => 'sl-4'],
    )->assertStatus(422);
});

it('rejects an out-of-range slot index (422)', function () {
    $c = suChar();
    suSave($c, gold: 0, skills: ['unlockedSkills' => ['shield_bash' => true]]);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/slot",
        ['slot' => 4, 'skillId' => 'shield_bash', 'requestId' => 'sl-5'],
    )->assertStatus(422);
});

it('slot assignment is idempotent per requestId', function () {
    $c = suChar();
    suSave($c, gold: 0, skills: ['unlockedSkills' => ['shield_bash' => true]]);

    $one = $this->withToken(suToken())->postJson("/api/v1/characters/{$c->id}/skills/slot", ['slot' => 1, 'skillId' => 'shield_bash', 'requestId' => 'sl-dup'])->json();
    $two = $this->withToken(suToken())->postJson("/api/v1/characters/{$c->id}/skills/slot", ['slot' => 1, 'skillId' => 'shield_bash', 'requestId' => 'sl-dup'])->json();

    expect($two)->toBe($one);
});

it('blocks unlock/slot on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(SU_USER_B)->create(['class' => 'Knight', 'level' => 100]);

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$other->id}/skills/shield_bash/unlock",
        ['requestId' => 'un-403'],
    )->assertForbidden();

    $this->withToken(suToken())->postJson(
        "/api/v1/characters/{$other->id}/skills/slot",
        ['slot' => 0, 'skillId' => null, 'requestId' => 'sl-403'],
    )->assertForbidden();
});
