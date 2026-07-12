<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const CR_USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const CR_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

beforeEach(function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(12345));
});

function strongChar(string $userId = CR_USER_A): Character
{
    return Character::factory()->forUser($userId)->create([
        'level' => 5, 'xp' => 0, 'attack' => 100000, 'defense' => 50,
        'hp' => 500, 'max_hp' => 500, 'gold' => 0, 'stat_points' => 0, 'highest_level' => 5,
    ]);
}

function highestMonsterId(): array
{
    $monsters = json_decode((string) file_get_contents(resource_path('game-content/monsters.json')), true);
    usort($monsters, fn ($a, $b) => $b['level'] <=> $a['level']);

    return $monsters[0];
}

it('resolves a hunt authoritatively and grants server-computed rewards', function () {
    $char = strongChar();

    $res = $this->withToken(TokenFactory::forUser(CR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/combat/resolve", [
            'monsterId' => 'rat', 'requestId' => 'req-1',
        ]);

    $res->assertOk()
        ->assertJsonPath('result.won', true);
    expect($res->json('result.goldGained'))->toBeGreaterThan(0)
        ->and($res->json('result.xpGained'))->toBeGreaterThan(0);

    $blobGold = GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'];
    expect($blobGold)->toBe($res->json('result.goldGained'))
        ->and(Character::find($char->id)->gold)->toBe(0);
});

it('IGNORES forged reward fields in the body (anti-cheat)', function () {
    $char = strongChar();

    $res = $this->withToken(TokenFactory::forUser(CR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/combat/resolve", [
            'monsterId' => 'rat', 'requestId' => 'req-cheat',
            'gold' => 999999999, 'xp' => 999999999, 'level' => 999, 'goldGained' => 999999999,
        ]);

    $res->assertOk();
    $blobGold = GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'];
    expect($blobGold)->toBe($res->json('result.goldGained'))
        ->and($blobGold)->toBeLessThan(999999999)
        ->and(Character::find($char->id)->level)->toBeLessThan(999);
});

it('rejects resolving combat on another user\'s character (403)', function () {
    $char = strongChar(CR_USER_B);

    $this->withToken(TokenFactory::forUser(CR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/combat/resolve", [
            'monsterId' => 'rat', 'requestId' => 'req-x',
        ])
        ->assertForbidden();
});

it('rejects a monster above the character level (422)', function () {
    $char = strongChar();
    $boss = highestMonsterId();

    $this->withToken(TokenFactory::forUser(CR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/combat/resolve", [
            'monsterId' => $boss['id'], 'requestId' => 'req-gate',
        ])
        ->assertStatus(422);
});

it('returns 404 for an unknown monster', function () {
    $char = strongChar();

    $this->withToken(TokenFactory::forUser(CR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/combat/resolve", [
            'monsterId' => 'no_such_monster', 'requestId' => 'req-404',
        ])
        ->assertNotFound();
});

it('is idempotent — replaying a requestId does not double-grant rewards', function () {
    $char = strongChar();
    $body = ['monsterId' => 'rat', 'requestId' => 'req-idem'];

    $first = $this->withToken(TokenFactory::forUser(CR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/combat/resolve", $body);
    $goldAfterFirst = GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'];

    $second = $this->withToken(TokenFactory::forUser(CR_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/combat/resolve", $body);

    $second->assertOk();
    expect($second->json('result.goldGained'))->toBe($first->json('result.goldGained'));
    expect(GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'])->toBe($goldAfterFirst);
});

it('requires authentication (401)', function () {
    $char = strongChar();

    $this->postJson("/api/v1/characters/{$char->id}/combat/resolve", [
        'monsterId' => 'rat', 'requestId' => 'req-noauth',
    ])->assertUnauthorized();
});
