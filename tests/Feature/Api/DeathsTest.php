<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\Death;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const DTH_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const DTH_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

it('logs a death with server-sourced character identity (anti-forge)', function () {
    $c = Character::factory()->forUser(DTH_USER)->create(['name' => 'Krasek', 'class' => 'Archer', 'level' => 308]);

    $res = $this->withToken(TokenFactory::forUser(DTH_USER))
        ->postJson("/api/v1/characters/{$c->id}/deaths", [
            'source' => 'boss', 'source_name' => 'Smoczy Wladca', 'source_level' => 350,
            // Próba fałszu tożsamości — serwer NIE czyta:
            'character_name' => 'HACKER', 'character_level' => 1,
        ]);

    $res->assertCreated()
        ->assertJsonPath('character_name', 'Krasek')   // z serwera
        ->assertJsonPath('character_class', 'Archer')
        ->assertJsonPath('character_level', 308)
        ->assertJsonPath('source', 'boss')
        ->assertJsonPath('result', 'killed');          // domyślnie killed

    expect(Death::count())->toBe(1);
});

it('persists result=fled when the character fled (soft flee)', function () {
    $c = Character::factory()->forUser(DTH_USER)->create(['name' => 'Krasek', 'class' => 'Archer', 'level' => 50]);

    $res = $this->withToken(TokenFactory::forUser(DTH_USER))
        ->postJson("/api/v1/characters/{$c->id}/deaths", [
            'source' => 'transform', 'source_name' => 'Wilkolak', 'source_level' => 60,
            'result' => 'fled',
        ]);

    $res->assertCreated()->assertJsonPath('result', 'fled');

    expect(Death::query()->value('result'))->toBe('fled');
});

it('defaults result to killed when omitted', function () {
    $c = Character::factory()->forUser(DTH_USER)->create();

    $this->withToken(TokenFactory::forUser(DTH_USER))
        ->postJson("/api/v1/characters/{$c->id}/deaths", [
            'source' => 'monster', 'source_name' => 'Rat', 'source_level' => 1,
        ])->assertCreated()->assertJsonPath('result', 'killed');

    expect(Death::query()->value('result'))->toBe('killed');
});

it('rejects an unknown result value (422)', function () {
    $c = Character::factory()->forUser(DTH_USER)->create();

    $this->withToken(TokenFactory::forUser(DTH_USER))
        ->postJson("/api/v1/characters/{$c->id}/deaths", [
            'source' => 'monster', 'source_name' => 'Rat', 'source_level' => 1,
            'result' => 'escaped',
        ])->assertStatus(422);
});

it('cannot log a fled death on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(DTH_USER_B)->create();

    $this->withToken(TokenFactory::forUser(DTH_USER))
        ->postJson("/api/v1/characters/{$other->id}/deaths", [
            'source' => 'transform', 'source_name' => 'Wilkolak', 'source_level' => 60,
            'result' => 'fled',
        ])->assertForbidden();

    expect(Death::count())->toBe(0);
});

it('validates the death source (422)', function () {
    $c = Character::factory()->forUser(DTH_USER)->create();

    $this->withToken(TokenFactory::forUser(DTH_USER))
        ->postJson("/api/v1/characters/{$c->id}/deaths", [
            'source' => 'god_mode', 'source_name' => 'x', 'source_level' => 1,
        ])->assertStatus(422);
});

it('lists recent deaths newest-first', function () {
    $c = Character::factory()->forUser(DTH_USER)->create();
    Death::create(['character_id' => $c->id, 'character_name' => 'A', 'character_class' => 'Mage', 'character_level' => 5, 'source' => 'monster', 'source_name' => 'Rat', 'source_level' => 1, 'died_at' => now()->subHour()]);
    Death::create(['character_id' => $c->id, 'character_name' => 'B', 'character_class' => 'Mage', 'character_level' => 6, 'source' => 'dungeon', 'source_name' => 'Cave', 'source_level' => 10, 'died_at' => now()]);

    $names = collect($this->withToken(TokenFactory::forUser(DTH_USER))->getJson('/api/v1/deaths')->json())->pluck('character_name')->all();

    expect($names)->toBe(['B', 'A']);
});

it('cannot log a death on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(DTH_USER_B)->create();

    $this->withToken(TokenFactory::forUser(DTH_USER))
        ->postJson("/api/v1/characters/{$other->id}/deaths", [
            'source' => 'monster', 'source_name' => 'Rat', 'source_level' => 1,
        ])->assertForbidden();
});

it('requires auth (401)', function () {
    $c = Character::factory()->forUser(DTH_USER)->create();
    $this->getJson('/api/v1/deaths')->assertUnauthorized();
    $this->postJson("/api/v1/characters/{$c->id}/deaths", [])->assertUnauthorized();
});
