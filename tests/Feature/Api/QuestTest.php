<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const QU_USER = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
const QU_USER_B = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

// Deterministyczny RNG (generacja itemów: reward-item + „gift").
beforeEach(function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(777));
});

function quChar(int $level = 20, string $userId = QU_USER): Character
{
    return Character::factory()->forUser($userId)->create([
        'class' => 'Knight', 'level' => $level, 'xp' => 0,
        'stat_points' => 0, 'highest_level' => $level,
    ]);
}

/** Blob z jednym aktywnym questem. progress>=count → ukończony. */
function quSaveWithQuest(Character $c, string $questId, int $progress = 1, int $count = 1, int $gold = 0): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id, 'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => ['gold' => $gold, 'bag' => [], 'equipment' => [], 'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0],
            'quests' => [
                'activeQuests' => [[
                    'questId' => $questId,
                    'goals' => [['type' => 'kill', 'count' => $count, 'progress' => $progress]],
                    'startedAt' => '2026-01-01T00:00:00Z',
                ]],
                'completedQuestIds' => [],
            ],
        ],
    ]);
}

function quToken(): string
{
    return TokenFactory::forUser(QU_USER);
}

function quClaim(Character $c, string $questId)
{
    return test()->withToken(quToken())->postJson("/api/v1/characters/{$c->id}/quests/{$questId}/claim");
}

it('claims gold + elixir: gold to blob, elixir resolved+stacked, quest moved, oneshot bumped', function () {
    $c = quChar(level: 10);
    quSaveWithQuest($c, 'quest_first_steps'); // gold 100, elixir hp_sm x5

    $res = quClaim($c, 'quest_first_steps');

    $res->assertOk()
        ->assertJsonPath('rewards.gold', 100)
        ->assertJsonPath('questsOneshotDone', 1);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(100)
        ->and($blob['inventory']['consumables']['hp_potion_sm'])->toBe(5)   // hp_sm → hp_potion_sm
        ->and($blob['quests']['activeQuests'])->toBe([])                     // usunięty
        ->and($blob['quests']['completedQuestIds'])->toBe(['quest_first_steps'])
        ->and($blob['inventory']['bag'])->toHaveCount(1);                    // gift item (brak jawnego item-rewarda)
    expect((int) Character::find($c->id)->quests_oneshot_done)->toBe(1);
});

it('claims a stone reward (singular stoneType) into the blob', function () {
    $c = quChar(level: 10);
    quSaveWithQuest($c, 'quest_spider_infestation'); // gold 100, stone common_stone x2

    quClaim($c, 'quest_spider_infestation')->assertOk();

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['stones']['common_stone'])->toBe(2)
        ->and($blob['inventory']['gold'])->toBe(100);
});

it('claims xp (to character) + gold + stones (plural stoneId) + elixir', function () {
    $c = quChar(level: 20);
    quSaveWithQuest($c, 'q_wolfpack_20'); // gold 20000, xp 10000, elixir amulet_of_loss x1, stones rare_stone x5

    quClaim($c, 'q_wolfpack_20')->assertOk();

    // xpToNextLevel(20)=26832 > 10000 → brak level-upa, xp na postaci.
    $fresh = Character::find($c->id);
    expect($fresh->xp)->toBe(10000)
        ->and($fresh->level)->toBe(20);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(20000)
        ->and($blob['inventory']['stones']['rare_stone'])->toBe(5)
        ->and($blob['inventory']['consumables']['amulet_of_loss'])->toBe(1); // brak aliasu → id bez zmiany
});

it('claims an explicit item reward: server-generated item in bag, NO gift item', function () {
    $c = quChar(level: 55);
    quSaveWithQuest($c, 'q_bandit_bounty_55'); // gold 75000, xp 50000, item epic x1, elixir amulet_of_loss x3

    $res = quClaim($c, 'q_bandit_bounty_55');
    $res->assertOk()->assertJsonPath('rewards.giftItem', null); // jawny item ⇒ zero giftu

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['bag'])->toHaveCount(1);                        // sam reward, bez giftu
    $item = $blob['inventory']['bag'][0];
    expect($item['rarity'])->toBe('epic')
        ->and($item['itemLevel'])->toBe(55)
        ->and($blob['inventory']['gold'])->toBe(75000)
        ->and($blob['inventory']['consumables']['amulet_of_loss'])->toBe(3);
    expect(Character::find($c->id)->xp)->toBe(50000);
});

it('claims stat_points reward onto the character (+ xp_elixir alias)', function () {
    $c = quChar(level: 30);
    quSaveWithQuest($c, 'quest_demon_invasion'); // gold 1200, elixir xp_elixir x2, stat_points 1

    quClaim($c, 'quest_demon_invasion')
        ->assertOk()
        ->assertJsonPath('rewards.statPoints', 1);

    expect((int) Character::find($c->id)->stat_points)->toBe(1);
    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['consumables']['xp_boost'])->toBe(2)           // xp_elixir → xp_boost
        ->and($blob['inventory']['gold'])->toBe(1200);
});

it('rejects claiming an unfinished quest (422) and grants nothing', function () {
    $c = quChar(level: 10);
    quSaveWithQuest($c, 'quest_first_steps', progress: 0, count: 1); // goal nieukończony

    quClaim($c, 'quest_first_steps')->assertStatus(422);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(0)
        ->and($blob['quests']['activeQuests'])->toHaveCount(1)               // nadal aktywny
        ->and($blob['quests']['completedQuestIds'])->toBe([]);
    expect((int) Character::find($c->id)->quests_oneshot_done)->toBe(0);
});

it('is naturally idempotent: second claim is 404 (no double reward)', function () {
    $c = quChar(level: 10);
    quSaveWithQuest($c, 'quest_first_steps');

    quClaim($c, 'quest_first_steps')->assertOk();
    quClaim($c, 'quest_first_steps')->assertNotFound();

    // Gold NIE podwojony, licznik one-shotów NIE podbity drugi raz.
    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(100);
    expect((int) Character::find($c->id)->quests_oneshot_done)->toBe(1);
});

it('returns 404 for an unknown quest id', function () {
    $c = quChar(level: 10);
    quSaveWithQuest($c, 'quest_first_steps');

    quClaim($c, 'quest_does_not_exist')->assertNotFound();
});

it('blocks claiming on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(QU_USER_B)->create(['class' => 'Knight']);
    quSaveWithQuest($other, 'quest_first_steps');

    quClaim($other, 'quest_first_steps')->assertForbidden();
});

it('requires authentication (401)', function () {
    $c = quChar(level: 10);
    quSaveWithQuest($c, 'quest_first_steps');

    $this->postJson("/api/v1/characters/{$c->id}/quests/quest_first_steps/claim")->assertUnauthorized();
});
