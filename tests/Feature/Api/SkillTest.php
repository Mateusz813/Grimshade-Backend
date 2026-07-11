<?php

declare(strict_types=1);

use App\Domain\Skills\SkillSystem;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const SK_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const SK_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function skChar(): Character
{
    return Character::factory()->forUser(SK_USER)->create(['class' => 'Knight', 'level' => 100, 'skill_upgrades_done' => 0]);
}

function skToken(): string
{
    return TokenFactory::forUser(SK_USER);
}

/**
 * @param  array<string, int>  $consumables
 * @param  array<string, mixed>  $skills
 */
function skSave(Character $c, int $gold, array $consumables = [], array $skills = []): GameSave
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

/** RNG o ustalonym nextFloat — deterministyczny roll ulepszenia. */
function skFixedRng(float $value): RngInterface
{
    return new class($value) implements RngInterface
    {
        public function __construct(private float $value) {}

        public function nextFloat(): float
        {
            return $this->value;
        }

        public function nextInt(int $min, int $max): int
        {
            return $min;
        }

        public function shuffle(array $items): array
        {
            return $items;
        }
    };
}

// ---- Upgrade ---------------------------------------------------------------

it('upgrades a skill: chest + gold deducted, level bumped, ranking counter++', function () {
    $c = skChar();
    // shield_bash unlockLevel=5 → chestLevel=5; target lvl1 cost {chests:1, gold:100, rate:100}.
    skSave($c, gold: 1000, consumables: ['spell_chest_5' => 3]);

    $res = $this->withToken(skToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/shield_bash/upgrade",
        ['requestId' => 'up-1'],
    );

    $res->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('newLevel', 1)
        ->assertJsonPath('goldSpent', 100)
        ->assertJsonPath('chestsSpent', 1);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(900)                                  // 1000 - 100
        ->and($blob['inventory']['consumables']['spell_chest_5'])->toBe(2)         // 3 - 1
        ->and($blob['skills']['skillUpgradeLevels']['shield_bash'])->toBe(1);
    expect(Character::find($c->id)->skill_upgrades_done)->toBe(1);
});

it('rejects upgrade with insufficient gold (422) and mutates nothing', function () {
    $c = skChar();
    skSave($c, gold: 50, consumables: ['spell_chest_5' => 5]); // 50 < 100 gold cost

    $this->withToken(skToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/shield_bash/upgrade",
        ['requestId' => 'up-2'],
    )->assertStatus(422);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(50)                                   // nietknięte
        ->and($blob['inventory']['consumables']['spell_chest_5'])->toBe(5)
        ->and($blob['skills']['skillUpgradeLevels'] ?? [])->toBe([]);
    expect(Character::find($c->id)->skill_upgrades_done)->toBe(0);
});

it('rejects upgrade with no spell chests (422)', function () {
    $c = skChar();
    skSave($c, gold: 100000, consumables: []); // brak spell_chest_5

    $this->withToken(skToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/shield_bash/upgrade",
        ['requestId' => 'up-3'],
    )->assertStatus(422);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(100000);
});

it('failed roll still deducts cost but does not bump level or counter', function () {
    $c = skChar();
    // Start na upgradeLevel 1 → target lvl2 cost {chests:1, gold:500, rate:90}.
    skSave($c, gold: 1000, consumables: ['spell_chest_5' => 3], skills: [
        'skillUpgradeLevels' => ['shield_bash' => 1],
    ]);

    // nextFloat=0.999 → 99.9 < 90 == false → porażka.
    $this->app->bind(RngInterface::class, fn () => skFixedRng(0.999));

    $res = $this->withToken(skToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/shield_bash/upgrade",
        ['requestId' => 'up-4'],
    );

    $res->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('newLevel', 1)          // bez zmiany
        ->assertJsonPath('goldSpent', 500)
        ->assertJsonPath('chestsSpent', 1);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['inventory']['gold'])->toBe(500)                                  // koszt zszedł mimo porażki
        ->and($blob['inventory']['consumables']['spell_chest_5'])->toBe(2)
        ->and($blob['skills']['skillUpgradeLevels']['shield_bash'])->toBe(1);       // level bez zmiany
    expect(Character::find($c->id)->skill_upgrades_done)->toBe(0);
});

it('upgrade is idempotent per requestId (no double spend)', function () {
    $c = skChar();
    skSave($c, gold: 1000, consumables: ['spell_chest_5' => 3]);

    $one = $this->withToken(skToken())->postJson("/api/v1/characters/{$c->id}/skills/shield_bash/upgrade", ['requestId' => 'dup'])->json();
    $two = $this->withToken(skToken())->postJson("/api/v1/characters/{$c->id}/skills/shield_bash/upgrade", ['requestId' => 'dup'])->json();

    expect($two)->toBe($one);
    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(900); // pojedyncze zejście
});

it('404 for an unknown skill id', function () {
    $c = skChar();
    skSave($c, gold: 1000, consumables: ['spell_chest_5' => 3]);

    $this->withToken(skToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/nope_skill/upgrade",
        ['requestId' => 'up-x'],
    )->assertNotFound();
});

// ---- Offline training ------------------------------------------------------

it('collects offline training XP computed from server elapsed time', function () {
    $c = skChar();
    // Start > 24h temu → elapsed capowany do MAX (deterministyczne, bez dryfu zegara).
    skSave($c, gold: 0, skills: [
        'offlineTrainingSkillId' => 'sword_fighting',
        'trainingStartedAt' => now()->subDays(2)->toIso8601String(),
        'skillLevels' => ['sword_fighting' => 0],
        'skillXp' => ['sword_fighting' => 0],
    ]);

    $expectedXp = SkillSystem::calculateOfflineSkillXp(SkillSystem::MAX_OFFLINE_TRAINING_SECONDS, 0, 'sword_fighting');
    $processed = SkillSystem::processSkillXp(0, 0, $expectedXp);

    $res = $this->withToken(skToken())->postJson("/api/v1/characters/{$c->id}/skills/train/collect");

    $res->assertOk()
        ->assertJsonPath('skillId', 'sword_fighting')
        ->assertJsonPath('xpEarned', $expectedXp)
        ->assertJsonPath('newLevel', $processed['newLevel']);

    expect($expectedXp)->toBeGreaterThan(0);

    $blob = GameSave::where('character_id', $c->id)->first()->state;
    expect($blob['skills']['skillLevels']['sword_fighting'])->toBe($processed['newLevel'])
        ->and($blob['skills']['skillXp']['sword_fighting'])->toBe($processed['remainingXp']);
});

it('rejects collect when no training is active (422)', function () {
    $c = skChar();
    skSave($c, gold: 0, skills: []);

    $this->withToken(skToken())->postJson("/api/v1/characters/{$c->id}/skills/train/collect")->assertStatus(422);
});

it('starts training: selects the skill and stamps a server start time', function () {
    $c = skChar();
    skSave($c, gold: 0, skills: []);

    $res = $this->withToken(skToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/train/start",
        ['skillId' => 'max_hp'],
    );

    $res->assertOk()->assertJsonPath('offlineTrainingSkillId', 'max_hp');

    $skills = GameSave::where('character_id', $c->id)->first()->state['skills'];
    expect($skills['offlineTrainingSkillId'])->toBe('max_hp')
        ->and($skills['trainingStartedAt'])->not->toBeNull();
});

it('rejects training a stat not trainable for the class (422)', function () {
    $c = skChar(); // Knight → dagger_fighting nie jest jego weapon skillem
    skSave($c, gold: 0, skills: []);

    $this->withToken(skToken())->postJson(
        "/api/v1/characters/{$c->id}/skills/train/start",
        ['skillId' => 'dagger_fighting'],
    )->assertStatus(422);
});

// ---- Authority -------------------------------------------------------------

it('blocks acting on another user\'s character (403)', function () {
    $other = Character::factory()->forUser(SK_USER_B)->create(['class' => 'Knight']);

    $this->withToken(skToken())->postJson(
        "/api/v1/characters/{$other->id}/skills/shield_bash/upgrade",
        ['requestId' => 'up-403'],
    )->assertForbidden();

    $this->withToken(skToken())->postJson("/api/v1/characters/{$other->id}/skills/train/collect")->assertForbidden();
});
