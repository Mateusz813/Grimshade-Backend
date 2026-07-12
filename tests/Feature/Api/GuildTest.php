<?php

declare(strict_types=1);

use App\Domain\Guild\GuildSystem;
use App\Models\Character;
use App\Models\GameSave;
use App\Models\Guild;
use App\Models\GuildBossState;
use App\Models\GuildJoinRequest;
use App\Models\GuildMember;
use App\Models\GuildTreasuryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const GD_USER_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const GD_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function gdChar(string $userId, array $overrides = []): Character
{
    return Character::factory()->forUser($userId)->create(array_merge(['level' => 10], $overrides));
}

function gdSave(Character $c, int $gold = 0, array $bag = []): GameSave
{
    return GameSave::create([
        'user_id' => $c->user_id,
        'character_id' => $c->id,
        'state' => [
            '_ownerCharacterId' => $c->id,
            'inventory' => [
                'gold' => $gold,
                'bag' => $bag,
                'equipment' => [], 'deposit' => [],
                'consumables' => [], 'stones' => [], 'arenaPoints' => 0,
            ],
            'settings' => ['language' => 'pl'],
        ],
    ]);
}

function gdGuild(Character $leader, array $overrides = []): Guild
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

function gdTokenA(): string
{
    return TokenFactory::forUser(GD_USER_A);
}

function gdTokenB(): string
{
    return TokenFactory::forUser(GD_USER_B);
}

function gdWeekStart(): string
{
    return GuildSystem::getCurrentWeekStartIso((int) (now()->timestamp * 1000));
}


it('creates a guild and charges the cost from the blob gold', function () {
    $leader = gdChar(GD_USER_A);
    gdSave($leader, 2_000_000);

    $res = $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds", [
        'name' => 'Rycerze', 'tag' => 'rycerze', 'logo' => '🛡', 'color' => '#e53935', 'requestId' => 'g-1',
    ]);

    $res->assertCreated()
        ->assertJsonPath('gold', 1_000_000)
        ->assertJsonPath('guild.tag', 'RYC')
        ->assertJsonPath('guild.leader_id', $leader->id)
        ->assertJsonPath('guild.level', 1)
        ->assertJsonPath('guild.member_cap', 20);

    $guildId = $res->json('guild.id');
    expect(GuildMember::where('guild_id', $guildId)->where('character_id', $leader->id)->exists())->toBeTrue()
        ->and(GameSave::where('character_id', $leader->id)->first()->state['inventory']['gold'])->toBe(1_000_000);
});

it('rejects creating a guild without enough gold (422, nothing charged)', function () {
    $leader = gdChar(GD_USER_A);
    gdSave($leader, 500);

    $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds", [
        'name' => 'Biedni', 'tag' => 'BIE', 'requestId' => 'g-poor',
    ])->assertStatus(422);

    expect(Guild::count())->toBe(0)
        ->and(GameSave::where('character_id', $leader->id)->first()->state['inventory']['gold'])->toBe(500);
});

it('create is idempotent per requestId (no double charge)', function () {
    $leader = gdChar(GD_USER_A);
    gdSave($leader, 2_000_000);
    $body = ['name' => 'Raz', 'tag' => 'RAZ', 'requestId' => 'g-idem'];

    $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds", $body)->assertCreated();
    $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds", $body)->assertCreated();

    expect(Guild::count())->toBe(1)
        ->and(GameSave::where('character_id', $leader->id)->first()->state['inventory']['gold'])->toBe(1_000_000);
});


it('runs the full create + join + accept flow', function () {
    $leader = gdChar(GD_USER_A);
    gdSave($leader, 2_000_000);
    $joiner = gdChar(GD_USER_B, ['name' => 'Rekrut']);

    $created = $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds", [
        'name' => 'Elita', 'tag' => 'ELI', 'requestId' => 'flow-create',
    ])->assertCreated();
    $guildId = $created->json('guild.id');

    $this->withToken(gdTokenB())->postJson("/api/v1/characters/{$joiner->id}/guilds/{$guildId}/join")
        ->assertOk()->assertJsonPath('ok', true);
    expect(GuildJoinRequest::where('guild_id', $guildId)->where('character_id', $joiner->id)->exists())->toBeTrue();

    $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds/{$guildId}/accept/{$joiner->id}")
        ->assertOk();

    expect(GuildMember::where('guild_id', $guildId)->count())->toBe(2)
        ->and(GuildMember::where('guild_id', $guildId)->where('character_id', $joiner->id)->exists())->toBeTrue()
        ->and(GuildJoinRequest::where('character_id', $joiner->id)->exists())->toBeFalse();
});

it('forbids a non-leader from accepting join requests (403)', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);
    $joiner = gdChar(GD_USER_B);

    GuildJoinRequest::create([
        'guild_id' => $guild->id, 'character_id' => $joiner->id, 'character_name' => $joiner->name,
        'character_class' => $joiner->class, 'character_level' => 10, 'requested_at' => now(),
    ]);

    $this->withToken(gdTokenB())
        ->postJson("/api/v1/characters/{$joiner->id}/guilds/{$guild->id}/accept/{$joiner->id}")
        ->assertForbidden();

    expect(GuildMember::where('guild_id', $guild->id)->where('character_id', $joiner->id)->exists())->toBeFalse();
});


it('computes boss damage server-side and credits guild XP (1 HP = 1 XP)', function () {
    $leader = gdChar(GD_USER_A, ['attack' => 1000, 'level' => 10]);
    $guild = gdGuild($leader);

    $res = $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/damage", [
        'damage' => 999_999_999, 'requestId' => 'boss-1',
    ]);

    $res->assertOk()
        ->assertJsonPath('damageDealt', 1083)
        ->assertJsonPath('killed', false)
        ->assertJsonPath('boss.boss_max_hp', 2_000_000)
        ->assertJsonPath('boss.boss_current_hp', 2_000_000 - 1083)
        ->assertJsonPath('guild.xp', 1083);

    $boss = GuildBossState::where('guild_id', $guild->id)->where('week_start', gdWeekStart())->first();
    expect((int) $boss->boss_current_hp)->toBe(2_000_000 - 1083);
});

it('caps a single boss attack at 5% of boss max HP', function () {
    $leader = gdChar(GD_USER_A, ['attack' => 100_000, 'level' => 5]);
    $guild = gdGuild($leader);

    $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/damage")
        ->assertOk()
        ->assertJsonPath('damageDealt', 100_000);
});

it('kills the boss and bumps the tier for next week', function () {
    $leader = gdChar(GD_USER_A, ['attack' => 1000, 'level' => 10]);
    $guild = gdGuild($leader);

    GuildBossState::create([
        'guild_id' => $guild->id, 'week_start' => gdWeekStart(), 'boss_tier' => 1,
        'boss_max_hp' => 2_000_000, 'boss_current_hp' => 50, 'boss_killed' => false,
        'current_attacker_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/damage");

    $res->assertOk()
        ->assertJsonPath('damageDealt', 50)
        ->assertJsonPath('killed', true)
        ->assertJsonPath('guild.boss_tier', 2);

    expect((int) Guild::find($guild->id)->boss_tier)->toBe(2);
});

it('rejects attacking an already-killed boss (422)', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);
    GuildBossState::create([
        'guild_id' => $guild->id, 'week_start' => gdWeekStart(), 'boss_tier' => 1,
        'boss_max_hp' => 2_000_000, 'boss_current_hp' => 0, 'boss_killed' => true,
        'current_attacker_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/damage")
        ->assertStatus(422);
});

it('forbids a non-member from attacking the guild boss (403)', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);
    $outsider = gdChar(GD_USER_B);

    $this->withToken(gdTokenB())->postJson("/api/v1/characters/{$outsider->id}/guilds/{$guild->id}/boss/damage")
        ->assertForbidden();
});


it('disbands the guild when the last (leader) member leaves', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);

    $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/leave")
        ->assertOk()->assertJsonPath('disbanded', true);

    expect(Guild::find($guild->id))->toBeNull()
        ->and(GuildMember::where('guild_id', $guild->id)->count())->toBe(0);
});

it('hands leadership to the oldest remaining member when the leader leaves', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);
    GuildMember::create([
        'guild_id' => $guild->id, 'character_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        'character_name' => 'Drugi', 'character_class' => 'Mage', 'character_level' => 5,
        'character_transform_tier' => 0, 'joined_at' => now()->subMinutes(5),
    ]);

    $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/leave")
        ->assertOk()->assertJsonPath('disbanded', false);

    expect(Guild::find($guild->id)->leader_id)->toBe('cccccccc-cccc-cccc-cccc-cccccccccccc');
});


it('deposits an item from the bag into the treasury and withdraws it back', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);
    gdSave($leader, 0, [[
        'uuid' => 'sword-1', 'itemId' => 'generated_rare_lvl50', 'name' => 'Miecz',
        'slot' => 'mainHand', 'rarity' => 'rare', 'bonuses' => ['attack' => 10],
        'itemLevel' => 50, 'upgradeLevel' => 2,
    ]]);

    $dep = $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/treasury/deposit", [
        'itemUuid' => 'sword-1',
    ]);
    $dep->assertCreated()->assertJsonPath('ok', true);
    $treasuryItemId = $dep->json('treasuryItemId');

    $inv = GameSave::where('character_id', $leader->id)->first()->state['inventory'];
    expect($inv['bag'])->toBe([])
        ->and(GuildTreasuryItem::where('guild_id', $guild->id)->count())->toBe(1);

    $this->withToken(gdTokenA())->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/treasury/withdraw", [
        'treasuryItemId' => $treasuryItemId,
    ])->assertOk()->assertJsonPath('ok', true);

    $inv = GameSave::where('character_id', $leader->id)->first()->state['inventory'];
    expect(collect($inv['bag'])->pluck('uuid')->all())->toBe(['sword-1'])
        ->and(GuildTreasuryItem::where('guild_id', $guild->id)->count())->toBe(0);
});

it('forbids a non-member from depositing into the treasury (403)', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);
    $outsider = gdChar(GD_USER_B);
    gdSave($outsider, 0, [['uuid' => 'x-1', 'itemId' => 'foo', 'rarity' => 'common', 'itemLevel' => 1, 'upgradeLevel' => 0]]);

    $this->withToken(gdTokenB())->postJson("/api/v1/characters/{$outsider->id}/guilds/{$guild->id}/treasury/deposit", [
        'itemUuid' => 'x-1',
    ])->assertForbidden();
});


it('shows guild metadata + roster + requests', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);

    $this->withToken(gdTokenA())->getJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}")
        ->assertOk()
        ->assertJsonPath('guild.id', $guild->id)
        ->assertJsonPath('members.0.character_id', $leader->id);
});

it('requires authentication (401)', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);

    $this->postJson("/api/v1/characters/{$leader->id}/guilds/{$guild->id}/boss/damage")
        ->assertUnauthorized();
});

it('blocks acting on another user\'s character (403)', function () {
    $leader = gdChar(GD_USER_A);
    $guild = gdGuild($leader);
    $other = gdChar(GD_USER_B);

    $this->withToken(gdTokenA())->getJson("/api/v1/characters/{$other->id}/guilds/{$guild->id}")
        ->assertForbidden();
});
