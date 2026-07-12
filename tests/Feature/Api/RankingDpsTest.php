<?php

declare(strict_types=1);

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const RK_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const RK_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function rkChar(array $dps = []): Character
{
    $c = Character::factory()->forUser(RK_USER)->create();
    foreach ($dps as $k => $v) {
        $c->{$k} = $v;
    }
    if ($dps !== []) {
        $c->save();
    }

    return $c->refresh();
}

function rkToken(): string
{
    return TokenFactory::forUser(RK_USER);
}

it('records a solo DPS high-water mark and returns fresh CharacterResource', function () {
    $c = rkChar();

    $res = $this->withToken(rkToken())->postJson("/api/v1/characters/{$c->id}/dps-record", [
        'dps' => 5000, 'inParty' => false, 'requestId' => 'r1',
    ]);

    $res->assertOk()
        ->assertJsonPath('id', $c->id)
        ->assertJsonPath('best_dps5_solo', 5000)
        ->assertJsonPath('best_dps5_party', 0)
        ->assertJsonPath('best_dps5_party_composition', null);

    $fresh = Character::find($c->id);
    expect($fresh->best_dps5_solo)->toBe(5000)
        ->and($fresh->best_dps5_party)->toBe(0);
});

it('records a party DPS mark and stores composition on improvement', function () {
    $c = rkChar();
    $comp = json_encode([['name' => 'Aro', 'class' => 'mage'], ['name' => 'Bem', 'class' => 'cleric']]);

    $res = $this->withToken(rkToken())->postJson("/api/v1/characters/{$c->id}/dps-record", [
        'dps' => 9000, 'inParty' => true, 'composition' => $comp, 'requestId' => 'p1',
    ]);

    $res->assertOk()
        ->assertJsonPath('best_dps5_party', 9000)
        ->assertJsonPath('best_dps5_party_composition', $comp)
        ->assertJsonPath('best_dps5_solo', 0);
});

it('clamps: a lower solo dps does not lower the existing high-water mark', function () {
    $c = rkChar(['best_dps5_solo' => 8000]);

    $this->withToken(rkToken())->postJson("/api/v1/characters/{$c->id}/dps-record", [
        'dps' => 3000, 'inParty' => false, 'requestId' => 'lo1',
    ])->assertOk()->assertJsonPath('best_dps5_solo', 8000);

    expect(Character::find($c->id)->best_dps5_solo)->toBe(8000);
});

it('does not overwrite party composition when the party score does not improve', function () {
    $keep = json_encode([['name' => 'Keep', 'class' => 'knight']]);
    $c = rkChar(['best_dps5_party' => 10000, 'best_dps5_party_composition' => $keep]);
    $newComp = json_encode([['name' => 'New', 'class' => 'rogue']]);

    $this->withToken(rkToken())->postJson("/api/v1/characters/{$c->id}/dps-record", [
        'dps' => 4000, 'inParty' => true, 'composition' => $newComp, 'requestId' => 'nc1',
    ])->assertOk()
        ->assertJsonPath('best_dps5_party', 10000)
        ->assertJsonPath('best_dps5_party_composition', $keep);

    expect(Character::find($c->id)->best_dps5_party_composition)->toBe($keep);
});

it('rejects non-positive dps (422)', function () {
    $c = rkChar();

    $this->withToken(rkToken())->postJson("/api/v1/characters/{$c->id}/dps-record", [
        'dps' => 0, 'inParty' => false, 'requestId' => 'z1',
    ])->assertStatus(422);
});

it('rejects absurd dps above the sane cap (422)', function () {
    $c = rkChar();

    $this->withToken(rkToken())->postJson("/api/v1/characters/{$c->id}/dps-record", [
        'dps' => 1_000_000_000_001, 'inParty' => false, 'requestId' => 'big1',
    ])->assertStatus(422);
});

it('is idempotent: replaying requestId returns cached result with no double-apply', function () {
    $c = rkChar();

    $first = $this->withToken(rkToken())->postJson("/api/v1/characters/{$c->id}/dps-record", [
        'dps' => 6000, 'inParty' => false, 'requestId' => 'dup1',
    ])->assertOk()->json();

    $second = $this->withToken(rkToken())->postJson("/api/v1/characters/{$c->id}/dps-record", [
        'dps' => 99999, 'inParty' => false, 'requestId' => 'dup1',
    ])->assertOk()->json();

    expect($second)->toBe($first);
    expect(Character::find($c->id)->best_dps5_solo)->toBe(6000);
});

it('blocks recording on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(RK_USER_B)->create();

    $this->withToken(rkToken())->postJson("/api/v1/characters/{$other->id}/dps-record", [
        'dps' => 5000, 'inParty' => false, 'requestId' => 'x1',
    ])->assertForbidden();
});
