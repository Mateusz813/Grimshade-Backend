<?php

declare(strict_types=1);

use App\Domain\Guild\GuildSystem;
use App\Domain\Support\Rng\Mulberry32Rng;
use App\Domain\Support\Rng\RngInterface;
use App\Models\Character;
use App\Models\GameSave;
use App\Models\Guild;
use App\Models\GuildBossAttempt;
use App\Models\GuildBossContribution;
use App\Models\GuildBossState;
use App\Models\GuildJoinRequest;
use App\Models\GuildMember;
use App\Models\GuildTreasuryItem;
use App\Models\GuildTreasuryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const GRE_USER_A = '11111111-1111-1111-1111-111111111111';
const GRE_USER_B = '22222222-2222-2222-2222-222222222222';

function greChar(string $userId, array $overrides = []): Character
{
    return Character::factory()->forUser($userId)->create(array_merge(['level' => 10], $overrides));
}

function greSave(Character $c, int $gold = 0): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id,
        'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => [
                'gold' => $gold,
                'bag' => [],
                'equipment' => [], 'deposit' => [],
                'consumables' => [], 'stones' => [], 'arenaPoints' => 0,
            ],
            'settings' => ['language' => 'pl'],
        ],
    ]);
}

function greGuild(Character $leader, array $overrides = []): Guild
{
    $guild = Guild::create(array_merge([
        'name' => 'Testowa Gildia',
        'tag' => 'TST',
        'logo' => '',
        'color' => '#fff',
        'leader_id' => $leader->id,
        'level' => 1,
        'xp' => 0,
        'boss_tier' => 1,
        'member_cap' => 20,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    GuildMember::create([
        'guild_id' => $guild->id,
        'character_id' => $leader->id,
        'character_name' => $leader->name,
        'character_class' => $leader->class,
        'character_level' => (int) $leader->level,
        'character_transform_tier' => 0,
        'joined_at' => now()->subMinutes(10),
    ]);

    return $guild;
}

function greTokenA(): string
{
    return TokenFactory::forUser(GRE_USER_A);
}

function greTokenB(): string
{
    return TokenFactory::forUser(GRE_USER_B);
}

function greWeekStart(): string
{
    return GuildSystem::getCurrentWeekStartIso((int) (now()->timestamp * 1000));
}

function greKilledBossWithContribution(Guild $guild, Character $char, int $totalDamage): void
{
    GuildBossState::create([
        'guild_id' => $guild->id, 'week_start' => greWeekStart(), 'boss_tier' => (int) $guild->boss_tier,
        'boss_max_hp' => 2_000_000, 'boss_current_hp' => 0, 'boss_killed' => true,
        'current_attacker_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    GuildBossContribution::create([
        'guild_id' => $guild->id, 'character_id' => $char->id, 'week_start' => greWeekStart(),
        'total_damage' => $totalDamage, 'rewards_claimed' => false, 'updated_at' => now(),
    ]);
}


it('lets the leader kick a member and returns the updated roster', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    $member = greChar(GRE_USER_B, ['name' => 'Rekrut']);
    GuildMember::create([
        'guild_id' => $guild->id, 'character_id' => $member->id, 'character_name' => $member->name,
        'character_class' => $member->class, 'character_level' => 10, 'character_transform_tier' => 0,
        'joined_at' => now()->subMinutes(2),
    ]);

    $this->withToken(greTokenA())
        ->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/kick/{$member->id}")
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(GuildMember::where('guild_id', $guild->id)->where('character_id', $member->id)->exists())->toBeFalse()
        ->and(GuildMember::where('guild_id', $guild->id)->count())->toBe(1);
});

it('forbids a non-leader from kicking (403)', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    $member = greChar(GRE_USER_B);
    GuildMember::create([
        'guild_id' => $guild->id, 'character_id' => $member->id, 'character_name' => $member->name,
        'character_class' => $member->class, 'character_level' => 10, 'character_transform_tier' => 0,
        'joined_at' => now(),
    ]);

    $this->withToken(greTokenB())
        ->postJson("/api/v1/characters/{$member->id}/guilds/{$guild->id}/kick/{$leader->id}")
        ->assertForbidden();

    expect(GuildMember::where('guild_id', $guild->id)->count())->toBe(2);
});

it('forbids the leader from kicking themselves (403)', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);

    $this->withToken(greTokenA())
        ->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/kick/{$leader->id}")
        ->assertForbidden();

    expect(GuildMember::where('guild_id', $guild->id)->where('character_id', $leader->id)->exists())->toBeTrue();
});


it('lets the leader reject a join request and returns remaining requests', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    $applicant = greChar(GRE_USER_B);
    GuildJoinRequest::create([
        'guild_id' => $guild->id, 'character_id' => $applicant->id, 'character_name' => $applicant->name,
        'character_class' => $applicant->class, 'character_level' => 10, 'requested_at' => now(),
    ]);

    $this->withToken(greTokenA())
        ->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/reject/{$applicant->id}")
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('requests', []);

    expect(GuildJoinRequest::where('guild_id', $guild->id)->where('character_id', $applicant->id)->exists())->toBeFalse();
});

it('forbids a non-leader from rejecting requests (403)', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    $applicant = greChar(GRE_USER_B);
    GuildJoinRequest::create([
        'guild_id' => $guild->id, 'character_id' => $applicant->id, 'character_name' => $applicant->name,
        'character_class' => $applicant->class, 'character_level' => 10, 'requested_at' => now(),
    ]);

    $this->withToken(greTokenB())
        ->postJson("/api/v1/characters/{$applicant->id}/guilds/{$guild->id}/reject/{$applicant->id}")
        ->assertForbidden();

    expect(GuildJoinRequest::where('guild_id', $guild->id)->count())->toBe(1);
});


it('lets the leader disband the guild with the full cascade', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    GuildJoinRequest::create([
        'guild_id' => $guild->id, 'character_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff', 'character_name' => 'X',
        'character_class' => 'Mage', 'character_level' => 1, 'requested_at' => now(),
    ]);
    GuildBossState::create([
        'guild_id' => $guild->id, 'week_start' => greWeekStart(), 'boss_tier' => 1,
        'boss_max_hp' => 2_000_000, 'boss_current_hp' => 2_000_000, 'boss_killed' => false,
        'current_attacker_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->withToken(greTokenA())
        ->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/disband")
        ->assertOk()
        ->assertJsonPath('disbanded', true);

    expect(Guild::find($guild->id))->toBeNull()
        ->and(GuildMember::where('guild_id', $guild->id)->count())->toBe(0)
        ->and(GuildJoinRequest::where('guild_id', $guild->id)->count())->toBe(0)
        ->and(GuildBossState::where('guild_id', $guild->id)->count())->toBe(0);
});

it('forbids a non-leader from disbanding the guild (403)', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    $member = greChar(GRE_USER_B);
    GuildMember::create([
        'guild_id' => $guild->id, 'character_id' => $member->id, 'character_name' => $member->name,
        'character_class' => $member->class, 'character_level' => 10, 'character_transform_tier' => 0,
        'joined_at' => now(),
    ]);

    $this->withToken(greTokenB())
        ->postJson("/api/v1/characters/{$member->id}/guilds/{$guild->id}/disband")
        ->assertForbidden();

    expect(Guild::find($guild->id))->not->toBeNull();
});


it('claims boss rewards server-side, crediting gold/stones/potions to the blob and XP to the character', function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(999));

    $leader = greChar(GRE_USER_A, ['level' => 10, 'xp' => 0]);
    greSave($leader, 0);
    $guild = greGuild($leader, ['boss_tier' => 1]);
    greKilledBossWithContribution($guild, $leader, 500_000);

    $res = $this->withToken(greTokenA())->postJson(
        "/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/claim-reward",
        ['requestId' => 'claim-1'],
    );

    $res->assertOk()->assertJsonPath('ok', true);
    $rewards = $res->json('rewards');
    $kinds = collect($rewards)->pluck('kind')->all();

    expect($kinds)->toContain('gold')
        ->and($kinds)->toContain('xp')
        ->and($kinds)->toContain('stones')
        ->and($kinds)->toContain('potion')
        ->and((int) $res->json('gold'))->toBeGreaterThan(0)
        ->and((int) $res->json('xp'))->toBeGreaterThan(0);

    $inv = GameSave::where('character_id', $leader->id)->first()->state['inventory'];
    expect((int) $inv['gold'])->toBe((int) $res->json('gold'))
        ->and((int) ($inv['stones']['common_stone'] ?? 0))->toBeGreaterThan(0)
        ->and((int) ($inv['consumables']['hp_potion_small'] ?? 0))->toBeGreaterThan(0)
        ->and((int) ($inv['consumables']['mp_potion_small'] ?? 0))->toBeGreaterThan(0);

    $contribution = GuildBossContribution::where('guild_id', $guild->id)->where('character_id', $leader->id)->first();
    expect((bool) $contribution->rewards_claimed)->toBeTrue()
        ->and($contribution->rewards_json)->not->toBeNull();
});

it('is idempotent per requestId — replaying returns the identical result without double-crediting', function () {
    $this->app->bind(RngInterface::class, fn () => new Mulberry32Rng(999));

    $leader = greChar(GRE_USER_A, ['level' => 10, 'xp' => 0]);
    greSave($leader, 0);
    $guild = greGuild($leader, ['boss_tier' => 1]);
    greKilledBossWithContribution($guild, $leader, 500_000);

    $first = $this->withToken(greTokenA())->postJson(
        "/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/claim-reward",
        ['requestId' => 'claim-replay'],
    )->assertOk();

    $goldAfterFirst = (int) GameSave::where('character_id', $leader->id)->first()->state['inventory']['gold'];

    $second = $this->withToken(greTokenA())->postJson(
        "/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/claim-reward",
        ['requestId' => 'claim-replay'],
    )->assertOk();

    expect($second->json('gold'))->toBe($first->json('gold'))
        ->and($second->json('rewards'))->toBe($first->json('rewards'))
        ->and((int) GameSave::where('character_id', $leader->id)->first()->state['inventory']['gold'])->toBe($goldAfterFirst);
});

it('rejects claiming when the boss was not killed this week (422)', function () {
    $leader = greChar(GRE_USER_A);
    greSave($leader, 0);
    $guild = greGuild($leader);
    GuildBossState::create([
        'guild_id' => $guild->id, 'week_start' => greWeekStart(), 'boss_tier' => 1,
        'boss_max_hp' => 2_000_000, 'boss_current_hp' => 1_000_000, 'boss_killed' => false,
        'current_attacker_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    GuildBossContribution::create([
        'guild_id' => $guild->id, 'character_id' => $leader->id, 'week_start' => greWeekStart(),
        'total_damage' => 1_000_000, 'rewards_claimed' => false, 'updated_at' => now(),
    ]);

    $this->withToken(greTokenA())->postJson(
        "/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/claim-reward",
        ['requestId' => 'claim-nokill'],
    )->assertStatus(422);
});

it('rejects a second claim once rewards are already claimed (422)', function () {
    $leader = greChar(GRE_USER_A);
    greSave($leader, 0);
    $guild = greGuild($leader);
    GuildBossState::create([
        'guild_id' => $guild->id, 'week_start' => greWeekStart(), 'boss_tier' => 1,
        'boss_max_hp' => 2_000_000, 'boss_current_hp' => 0, 'boss_killed' => true,
        'current_attacker_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    GuildBossContribution::create([
        'guild_id' => $guild->id, 'character_id' => $leader->id, 'week_start' => greWeekStart(),
        'total_damage' => 500_000, 'rewards_claimed' => true, 'rewards_json' => '[]', 'updated_at' => now(),
    ]);

    $this->withToken(greTokenA())->postJson(
        "/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/claim-reward",
        ['requestId' => 'claim-again'],
    )->assertStatus(422);
});

it('forbids a non-member from claiming boss rewards (403)', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    $outsider = greChar(GRE_USER_B);
    greSave($outsider, 0);

    $this->withToken(greTokenB())->postJson(
        "/api/v1/characters/{$outsider->id}/guilds/{$guild->id}/boss/claim-reward",
        ['requestId' => 'claim-outsider'],
    )->assertForbidden();
});


it('returns the weekly boss view (fetch-or-create) with contributions and attempts', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader, ['boss_tier' => 3]);
    GuildBossContribution::create([
        'guild_id' => $guild->id, 'character_id' => $leader->id, 'week_start' => greWeekStart(),
        'total_damage' => 1234, 'rewards_claimed' => false, 'updated_at' => now(),
    ]);
    GuildBossAttempt::create([
        'guild_id' => $guild->id, 'character_id' => $leader->id, 'character_name' => $leader->name,
        'attempt_date' => GuildSystem::getTodayIso((int) (now()->timestamp * 1000)),
        'damage_dealt' => 1234, 'created_at' => now(),
    ]);

    $res = $this->withToken(greTokenA())->getJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss");

    $res->assertOk()
        ->assertJsonPath('boss.boss_tier', 3)
        ->assertJsonPath('boss.boss_max_hp', GuildSystem::getGuildBossMaxHp(3))
        ->assertJsonPath('contribution.total_damage', 1234)
        ->assertJsonPath('contributions.0.total_damage', 1234)
        ->assertJsonPath('attemptsToday.0.damage_dealt', 1234)
        ->assertJsonPath('weeklyAttempts.0.damage_dealt', 1234);

    expect(GuildBossState::where('guild_id', $guild->id)->where('week_start', greWeekStart())->exists())->toBeTrue();
});

it('forbids a non-member from viewing the guild boss (403)', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    $outsider = greChar(GRE_USER_B);

    $this->withToken(greTokenB())->getJson("/api/v1/characters/{$outsider->id}/guilds/{$guild->id}/boss")
        ->assertForbidden();
});


it('returns treasury items and logs', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    GuildTreasuryItem::create([
        'guild_id' => $guild->id, 'item_data' => json_encode(['uuid' => 'i-1', 'name' => 'Miecz']),
        'deposited_by' => $leader->id, 'deposited_by_name' => $leader->name, 'deposited_at' => now(),
    ]);
    GuildTreasuryLog::create([
        'guild_id' => $guild->id, 'action' => 'deposit', 'character_id' => $leader->id,
        'character_name' => $leader->name, 'item_name' => 'Miecz',
        'item_data' => json_encode(['uuid' => 'i-1']), 'created_at' => now(),
    ]);

    $this->withToken(greTokenA())->getJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/treasury")
        ->assertOk()
        ->assertJsonPath('items.0.deposited_by_name', $leader->name)
        ->assertJsonPath('logs.0.action', 'deposit')
        ->assertJsonPath('logs.0.item_name', 'Miecz');
});

it('forbids a non-member from viewing the treasury (403)', function () {
    $leader = greChar(GRE_USER_A);
    $guild = greGuild($leader);
    $outsider = greChar(GRE_USER_B);

    $this->withToken(greTokenB())->getJson("/api/v1/characters/{$outsider->id}/guilds/{$guild->id}/treasury")
        ->assertForbidden();
});


it('lists guilds paginated with member counts, leader names and total count', function () {
    $leaderA = greChar(GRE_USER_A, ['name' => 'Krasek']);
    $guildA = greGuild($leaderA, ['name' => 'Alfa', 'level' => 5]);
    GuildMember::create([
        'guild_id' => $guildA->id, 'character_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
        'character_name' => 'Drugi', 'character_class' => 'Mage', 'character_level' => 5,
        'character_transform_tier' => 0, 'joined_at' => now(),
    ]);
    $leaderB = greChar(GRE_USER_B, ['name' => 'Bob']);
    greGuild($leaderB, ['name' => 'Beta', 'level' => 9]);

    $res = $this->withToken(greTokenA())->getJson('/api/v1/guilds?offset=0&limit=10');

    $res->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('guilds.0.name', 'Beta')
        ->assertJsonPath('guilds.1.name', 'Alfa')
        ->assertJsonPath("summaries.{$guildA->id}.memberCount", 2)
        ->assertJsonPath("summaries.{$guildA->id}.leaderName", 'Krasek');
});

it('filters the guild browser by case-insensitive name search', function () {
    $leaderA = greChar(GRE_USER_A);
    greGuild($leaderA, ['name' => 'Rycerze Świtu', 'level' => 3]);
    $leaderB = greChar(GRE_USER_B);
    greGuild($leaderB, ['name' => 'Cienie Nocy', 'level' => 3]);

    $res = $this->withToken(greTokenA())->getJson('/api/v1/guilds?search=rycerze');

    $res->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('guilds.0.name', 'Rycerze Świtu');
});

it('requires authentication for the guild browser (401)', function () {
    $this->getJson('/api/v1/guilds')->assertUnauthorized();
});
