<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\Party;
use App\Models\PartyMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const PB_USER_A = 'a1b1c1d1-aaaa-bbbb-cccc-a1b1c1d1e1f1';
const PB_USER_B = 'a2b2c2d2-aaaa-bbbb-cccc-a2b2c2d2e2f2';
const PB_USER_C = 'a3b3c3d3-aaaa-bbbb-cccc-a3b3c3d3e3f3';
const PB_USER_D = 'a4b4c4d4-aaaa-bbbb-cccc-a4b4c4d4e4f4';
const PB_USER_E = 'a5b5c5d5-aaaa-bbbb-cccc-a5b5c5d5e5f5';

function pbChar(string $userId, array $attrs = []): Character
{
    return Character::factory()->forUser($userId)->create($attrs);
}

function pbToken(string $userId): string
{
    return TokenFactory::forUser($userId);
}

function pbCreateParty(string $userId, array $body = []): array
{
    $leader = pbChar($userId);
    $partyId = test()->withToken(pbToken($userId))
        ->postJson("/api/v1/characters/{$leader->id}/parties", $body + ['name' => 'Party'])
        ->json('id');

    return [$partyId, $leader];
}

it('lets the leader kick a member by row id', function () {
    [$partyId, $leader] = pbCreateParty(PB_USER_A);

    $member = pbChar(PB_USER_B);
    $joinSnap = $this->withToken(pbToken(PB_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join")
        ->assertOk()
        ->json();

    $rowId = collect($joinSnap['members'])->firstWhere('character_id', $member->id)['id'];

    $this->withToken(pbToken(PB_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties/{$partyId}/kick", [
            'memberRowId' => $rowId,
        ])
        ->assertOk()
        ->assertJsonCount(1, 'members')
        ->assertJsonPath('members.0.character_id', $leader->id)
        ->assertJsonMissingPath('password');

    expect(PartyMember::where('character_id', $member->id)->exists())->toBeFalse();
    expect(PartyMember::where('party_id', $partyId)->count())->toBe(1);
});

it('rejects a kick attempted by a non-leader (403)', function () {
    [$partyId, $leader] = pbCreateParty(PB_USER_A);

    $member = pbChar(PB_USER_B);
    $this->withToken(pbToken(PB_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join")->assertOk();

    $leaderRowId = PartyMember::where('character_id', $leader->id)->first()->id;

    $this->withToken(pbToken(PB_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/kick", [
            'memberRowId' => $leaderRowId,
        ])
        ->assertForbidden();

    expect(PartyMember::where('party_id', $partyId)->count())->toBe(2);
});

it('refuses to kick the leader/self (422)', function () {
    [$partyId, $leader] = pbCreateParty(PB_USER_A);
    $leaderRowId = PartyMember::where('character_id', $leader->id)->first()->id;

    $this->withToken(pbToken(PB_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties/{$partyId}/kick", [
            'memberRowId' => $leaderRowId,
        ])
        ->assertStatus(422);

    expect(PartyMember::where('character_id', $leader->id)->exists())->toBeTrue();
});

it('returns 404 kicking an unknown member row', function () {
    [$partyId, $leader] = pbCreateParty(PB_USER_A);

    $this->withToken(pbToken(PB_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties/{$partyId}/kick", [
            'memberRowId' => '00000000-0000-0000-0000-000000000000',
        ])
        ->assertNotFound();
});

it('lets the leader edit party meta and clamps values', function () {
    [$partyId, $leader] = pbCreateParty(PB_USER_A, ['password' => 'stare']);

    $this->withToken(pbToken(PB_USER_A))
        ->putJson("/api/v1/characters/{$leader->id}/parties/{$partyId}", [
            'name' => str_repeat('N', 60),
            'description' => str_repeat('D', 140),
            'isPublic' => false,
            'minJoinLevel' => 15,
        ])
        ->assertOk()
        ->assertJsonPath('is_public', false)
        ->assertJsonPath('min_join_level', 15)
        ->assertJsonPath('has_password', true)
        ->assertJsonMissingPath('password');

    $party = Party::find($partyId);
    expect(mb_strlen($party->name))->toBe(60);
    expect(mb_strlen((string) $party->description))->toBe(140);
    expect($party->password)->toBe('stare');
});

it('nulls the password when set to empty and derives has_password=false', function () {
    [$partyId, $leader] = pbCreateParty(PB_USER_A, ['password' => 'sezam']);

    $this->withToken(pbToken(PB_USER_A))
        ->putJson("/api/v1/characters/{$leader->id}/parties/{$partyId}", [
            'password' => '',
        ])
        ->assertOk()
        ->assertJsonPath('has_password', false);

    expect(Party::find($partyId)->password)->toBeNull();
});

it('rejects a meta edit attempted by a non-leader (403)', function () {
    [$partyId] = pbCreateParty(PB_USER_A);

    $member = pbChar(PB_USER_B);
    $this->withToken(pbToken(PB_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join")->assertOk();

    $this->withToken(pbToken(PB_USER_B))
        ->putJson("/api/v1/characters/{$member->id}/parties/{$partyId}", [
            'description' => 'hax',
        ])
        ->assertForbidden();
});

it('lists public non-full parties ordered by newest first', function () {
    [$p1] = pbCreateParty(PB_USER_A, ['name' => 'Alpha']);
    Party::where('id', $p1)->update(['created_at' => now()->subMinutes(5)]);
    [$p2] = pbCreateParty(PB_USER_B, ['name' => 'Beta']);

    $res = $this->withToken(pbToken(PB_USER_C))
        ->getJson('/api/v1/parties')
        ->assertOk();

    $ids = collect($res->json())->pluck('id')->all();
    expect($ids)->toContain($p1)->toContain($p2);
    expect(array_search($p2, $ids, true))->toBeLessThan(array_search($p1, $ids, true));
    $res->assertJsonMissingPath('0.password');
    expect($res->json('0.members'))->toBeArray();
});

it('excludes private and full parties from the browser', function () {
    [$priv] = pbCreateParty(PB_USER_A, ['name' => 'Sekret', 'isPublic' => false]);

    [$full, $leader] = pbCreateParty(PB_USER_B, ['name' => 'Full']);
    foreach ([[PB_USER_C, 'Mage'], [PB_USER_D, 'Cleric'], [PB_USER_E, 'Archer']] as [$uid, $cls]) {
        $c = pbChar($uid, ['class' => $cls]);
        $this->withToken(pbToken($uid))
            ->postJson("/api/v1/characters/{$c->id}/parties/{$full}/join")->assertOk();
    }

    $ids = collect(
        $this->withToken(pbToken(PB_USER_A))->getJson('/api/v1/parties')->assertOk()->json()
    )->pluck('id')->all();

    expect($ids)->not->toContain($priv);
    expect($ids)->not->toContain($full);
});

it('garbage-collects empty parties when listing', function () {
    $ghost = Party::create([
        'leader_id' => '00000000-0000-0000-0000-0000000000ff',
        'name' => 'Ghost',
        'max_members' => 4,
        'is_public' => true,
        'min_join_level' => 1,
    ]);

    $this->withToken(pbToken(PB_USER_A))
        ->getJson('/api/v1/parties')
        ->assertOk();

    expect(Party::find($ghost->id))->toBeNull();
});

it('returns the active party snapshot for the acting character', function () {
    [$partyId, $leader] = pbCreateParty(PB_USER_A, ['name' => 'Moje']);

    $this->withToken(pbToken(PB_USER_A))
        ->getJson("/api/v1/characters/{$leader->id}/parties/active")
        ->assertOk()
        ->assertJsonPath('id', $partyId)
        ->assertJsonPath('name', 'Moje')
        ->assertJsonPath('leader_id', $leader->id)
        ->assertJsonMissingPath('password');
});

it('returns null when the character is in no party', function () {
    $solo = pbChar(PB_USER_A);

    $res = $this->withToken(pbToken(PB_USER_A))
        ->getJson("/api/v1/characters/{$solo->id}/parties/active")
        ->assertOk();

    expect($res->getContent())->toBe('null');
});

it('requires authentication for the public browser (401)', function () {
    $this->getJson('/api/v1/parties')->assertUnauthorized();
});
