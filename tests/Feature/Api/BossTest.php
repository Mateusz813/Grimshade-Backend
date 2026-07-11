<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const BO_USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const BO_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

// sewer_king (bosses.json): level 10, dailyAttempts 3, dropTable rare+epic.
const BO_BOSS_ID = 'sewer_king';
const BO_BOSS_LEVEL = 10;

// Deterministyczne RNG (ten sam algorytm co front) + potęga na 1-shot bossa.
beforeEach(function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(12345));
});

/** Postać zdolna pokonać bossa (level >= boss.level, ogromny atak = 1-shot). */
function bossChar(string $userId = BO_USER_A, int $level = 60): Character
{
    return Character::factory()->forUser($userId)->create([
        'class' => 'Knight',
        'level' => $level, 'xp' => 0, 'attack' => 100000, 'defense' => 50,
        'hp' => 500, 'max_hp' => 500, 'gold' => 0, 'stat_points' => 0, 'highest_level' => $level,
    ]);
}

/** Blob z gotowym wpisem bosses.dailyAttempts (do testu limitu). */
function bossSaveWithAttempts(Character $c, int $used, string $date): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => ['gold' => 0, 'bag' => [], 'equipment' => [], 'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0],
            'bosses' => ['dailyAttempts' => [BO_BOSS_ID => ['used' => $used, 'date' => $date]], 'clearedIds' => [], 'lastResult' => null],
        ],
    ]);
}

it('resolves a boss authoritatively and grants server-computed rewards', function () {
    $char = bossChar();

    $res = $this->withToken(TokenFactory::forUser(BO_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/boss/".BO_BOSS_ID.'/resolve', [
            'requestId' => 'bo-req-1',
        ]);

    $res->assertOk()
        ->assertJsonPath('result.won', true)
        ->assertJsonPath('attemptsUsed', 1)
        ->assertJsonPath('attemptsMax', 3);
    expect($res->json('result.gold'))->toBeGreaterThan(0)
        ->and($res->json('result.xp'))->toBeGreaterThan(0);

    // Gold ląduje w BLOBIE (prawdziwa waluta), nie w characters.gold.
    $save = GameSave::where('character_id', $char->id)->first();
    expect($save->state['inventory']['gold'])->toBe($res->json('result.gold'))
        ->and(Character::find($char->id)->gold)->toBe(0);

    // XP na postaci; slice bosses zaktualizowany.
    expect(Character::find($char->id)->xp)->toBeGreaterThan(0)
        ->and($save->state['bosses']['dailyAttempts'][BO_BOSS_ID]['used'])->toBe(1)
        ->and($save->state['bosses']['clearedIds'])->toContain(BO_BOSS_ID);
});

it('IGNORES forged reward fields in the body (anti-cheat)', function () {
    $char = bossChar();

    $res = $this->withToken(TokenFactory::forUser(BO_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/boss/".BO_BOSS_ID.'/resolve', [
            'requestId' => 'bo-cheat',
            'gold' => 999999999, 'xp' => 999999999, 'level' => 999, 'won' => true,
        ]);

    $res->assertOk();
    $blobGold = GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'];
    expect($blobGold)->toBe($res->json('result.gold'))
        ->and($blobGold)->toBeLessThan(999999999)
        ->and(Character::find($char->id)->level)->toBeLessThan(999);
});

it('rejects a boss above the character level (422)', function () {
    $char = bossChar(BO_USER_A, level: 5); // < boss level 10

    $this->withToken(TokenFactory::forUser(BO_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/boss/".BO_BOSS_ID.'/resolve', [
            'requestId' => 'bo-gate',
        ])
        ->assertStatus(422);

    // Brak nagrody — żaden blob nie powstał.
    expect(GameSave::where('character_id', $char->id)->exists())->toBeFalse();
});

it('rejects a boss when the daily attempt limit is exhausted (422)', function () {
    $char = bossChar();
    bossSaveWithAttempts($char, used: 3, date: now()->toDateString());

    $this->withToken(TokenFactory::forUser(BO_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/boss/".BO_BOSS_ID.'/resolve', [
            'requestId' => 'bo-limit',
        ])
        ->assertStatus(422);

    // Gold nietknięty — limit blokuje przed jakąkolwiek mutacją.
    expect(GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'])->toBe(0);
});

it('resets the daily limit on a new day (yesterday\'s attempts do not count)', function () {
    $char = bossChar();
    bossSaveWithAttempts($char, used: 3, date: now()->subDay()->toDateString());

    $res = $this->withToken(TokenFactory::forUser(BO_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/boss/".BO_BOSS_ID.'/resolve', [
            'requestId' => 'bo-newday',
        ]);

    $res->assertOk()->assertJsonPath('result.won', true)->assertJsonPath('attemptsUsed', 1);
});

it('rejects resolving a boss on another user\'s character (403)', function () {
    $char = bossChar(BO_USER_B);

    $this->withToken(TokenFactory::forUser(BO_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/boss/".BO_BOSS_ID.'/resolve', [
            'requestId' => 'bo-403',
        ])
        ->assertForbidden();
});

it('returns 404 for an unknown boss', function () {
    $char = bossChar();

    $this->withToken(TokenFactory::forUser(BO_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/boss/no_such_boss/resolve", [
            'requestId' => 'bo-404',
        ])
        ->assertNotFound();
});

it('is idempotent — replaying a requestId does not double-grant rewards', function () {
    $char = bossChar();
    $body = ['requestId' => 'bo-idem'];

    $first = $this->withToken(TokenFactory::forUser(BO_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/boss/".BO_BOSS_ID.'/resolve', $body);
    $goldAfterFirst = GameSave::where('character_id', $char->id)->first()->state['inventory']['gold'];

    $second = $this->withToken(TokenFactory::forUser(BO_USER_A))
        ->postJson("/api/v1/characters/{$char->id}/boss/".BO_BOSS_ID.'/resolve', $body);

    $second->assertOk();
    expect($second->json('result.gold'))->toBe($first->json('result.gold'));
    // Gold NIE podwojony + attempt NIE podbity drugi raz (cache short-circuit).
    $save = GameSave::where('character_id', $char->id)->first();
    expect($save->state['inventory']['gold'])->toBe($goldAfterFirst)
        ->and($save->state['bosses']['dailyAttempts'][BO_BOSS_ID]['used'])->toBe(1);
});

it('requires authentication (401)', function () {
    $char = bossChar();

    $this->postJson("/api/v1/characters/{$char->id}/boss/".BO_BOSS_ID.'/resolve', [
        'requestId' => 'bo-noauth',
    ])->assertUnauthorized();
});
