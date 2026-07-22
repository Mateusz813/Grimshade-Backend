<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\Party;
use App\Models\PartyMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const PT_USER_A = 'a1a1a1a1-aaaa-aaaa-aaaa-a1a1a1a1a1a1';
const PT_USER_B = 'b2b2b2b2-bbbb-bbbb-bbbb-b2b2b2b2b2b2';
const PT_USER_C = 'c3c3c3c3-cccc-cccc-cccc-c3c3c3c3c3c3';
const PT_USER_D = 'd4d4d4d4-dddd-dddd-dddd-d4d4d4d4d4d4';
const PT_USER_E = 'e5e5e5e5-eeee-eeee-eeee-e5e5e5e5e5e5';

function ptChar(string $userId, array $attrs = []): Character
{
    return Character::factory()->forUser($userId)->create($attrs);
}

function ptToken(string $userId): string
{
    return TokenFactory::forUser($userId);
}

it('creates a party with the leader as the first member (max 4)', function () {
    $leader = ptChar(PT_USER_A, ['name' => 'Aldric', 'class' => 'Knight', 'level' => 10]);

    $res = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", [
            'name' => 'Nocna Straz', 'description' => 'szukam tanka', 'isPublic' => true,
        ]);

    $res->assertCreated()
        ->assertJsonPath('leader_id', $leader->id)
        ->assertJsonPath('name', 'Nocna Straz')
        ->assertJsonPath('description', 'szukam tanka')
        ->assertJsonPath('max_members', 4)
        ->assertJsonPath('is_public', true)
        ->assertJsonPath('has_password', false)
        ->assertJsonPath('min_join_level', 1)
        ->assertJsonCount(1, 'members')
        ->assertJsonPath('members.0.character_id', $leader->id)
        ->assertJsonPath('members.0.character_class', 'Knight')
        ->assertJsonPath('members.0.character_level', 10);

    expect(PartyMember::where('character_id', $leader->id)->count())->toBe(1);
    expect(Party::find($res->json('id'))->leader_id)->toBe($leader->id);
});

it('defaults the party name from the leader when none is given', function () {
    $leader = ptChar(PT_USER_A, ['name' => 'Borin']);

    $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", [])
        ->assertCreated()
        ->assertJsonPath('name', "Borin's party");
});

it('rejects creating a second party while already in one (422)', function () {
    $leader = ptChar(PT_USER_A);

    $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'Pierwsze'])
        ->assertCreated();

    $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'Drugie'])
        ->assertStatus(422);

    expect(Party::query()->count())->toBe(1);
});

it('lets characters join and enforces the max of 4 members', function () {
    $leader = ptChar(PT_USER_A, ['class' => 'Knight']);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'Rajd'])
        ->json('id');

    foreach ([[PT_USER_B, 'Mage'], [PT_USER_C, 'Cleric'], [PT_USER_D, 'Archer']] as [$uid, $cls]) {
        $c = ptChar($uid, ['class' => $cls]);
        $this->withToken(ptToken($uid))
            ->postJson("/api/v1/characters/{$c->id}/parties/{$partyId}/join")
            ->assertOk();
    }

    expect(PartyMember::where('party_id', $partyId)->count())->toBe(4);

    $fifth = ptChar(PT_USER_E, ['class' => 'Rogue']);
    $this->withToken(ptToken(PT_USER_E))
        ->postJson("/api/v1/characters/{$fifth->id}/parties/{$partyId}/join")
        ->assertStatus(422);

    expect(PartyMember::where('party_id', $partyId)->count())->toBe(4);
});

it('is idempotent when the same character joins twice', function () {
    $leader = ptChar(PT_USER_A);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'X'])->json('id');

    $member = ptChar(PT_USER_B);
    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join")->assertOk();
    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join")->assertOk();

    expect(PartyMember::where('party_id', $partyId)->count())->toBe(2);
});

it('rejects joining an unknown party (404)', function () {
    $c = ptChar(PT_USER_A);

    $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$c->id}/parties/00000000-0000-0000-0000-000000000000/join")
        ->assertNotFound();
});

it('gates joining behind the party password (422 wrong, 200 right)', function () {
    $leader = ptChar(PT_USER_A);
    $create = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", [
            'name' => 'Tajne', 'password' => 'sezamie',
        ]);
    $create->assertCreated()->assertJsonPath('has_password', true);
    $partyId = $create->json('id');

    $member = ptChar(PT_USER_B);
    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join", ['password' => 'zle'])
        ->assertStatus(422);

    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join", ['password' => 'sezamie'])
        ->assertOk();

    expect(PartyMember::where('party_id', $partyId)->count())->toBe(2);
});

it('enforces the minimum join level (422 too low, 200 ok)', function () {
    $leader = ptChar(PT_USER_A, ['level' => 50]);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", [
            'name' => 'Endgame', 'minJoinLevel' => 20,
        ])->json('id');

    $low = ptChar(PT_USER_B, ['level' => 5]);
    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$low->id}/parties/{$partyId}/join")
        ->assertStatus(422);

    $high = ptChar(PT_USER_C, ['level' => 30]);
    $this->withToken(ptToken(PT_USER_C))
        ->postJson("/api/v1/characters/{$high->id}/parties/{$partyId}/join")
        ->assertOk();

    expect(PartyMember::where('party_id', $partyId)->count())->toBe(2);
});

it('hands over leadership to another member', function () {
    $leader = ptChar(PT_USER_A);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'X'])->json('id');

    $member = ptChar(PT_USER_B);
    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join")->assertOk();

    $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties/{$partyId}/handover", [
            'newLeaderId' => $member->id,
        ])
        ->assertOk()
        ->assertJsonPath('leader_id', $member->id);

    expect(Party::find($partyId)->leader_id)->toBe($member->id);
    expect(PartyMember::where('party_id', $partyId)->count())->toBe(2);
});

it('rejects a handover attempted by a non-leader (403)', function () {
    $leader = ptChar(PT_USER_A);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'X'])->json('id');

    $member = ptChar(PT_USER_B);
    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join")->assertOk();

    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/handover", [
            'newLeaderId' => $member->id,
        ])
        ->assertForbidden();

    expect(Party::find($partyId)->leader_id)->toBe($leader->id);
});

it('rejects a handover to a character that is not a member (422)', function () {
    $leader = ptChar(PT_USER_A);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'X'])->json('id');

    $outsider = ptChar(PT_USER_B);
    $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties/{$partyId}/handover", [
            'newLeaderId' => $outsider->id,
        ])
        ->assertStatus(422);
});

it('lets a member leave without dissolving the party', function () {
    $leader = ptChar(PT_USER_A);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'X'])->json('id');

    $member = ptChar(PT_USER_B);
    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join")->assertOk();

    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/leave")
        ->assertOk()
        ->assertJsonPath('dissolved', false);

    expect(PartyMember::where('party_id', $partyId)->count())->toBe(1);
    expect(PartyMember::where('character_id', $member->id)->exists())->toBeFalse();
    expect(Party::find($partyId))->not->toBeNull();
});

it('dissolves the party when the leader leaves', function () {
    $leader = ptChar(PT_USER_A);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'X'])->json('id');

    $member = ptChar(PT_USER_B);
    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$member->id}/parties/{$partyId}/join")->assertOk();

    $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties/{$partyId}/leave")
        ->assertOk()
        ->assertJsonPath('dissolved', true);

    expect(Party::find($partyId))->toBeNull();
    expect(PartyMember::where('party_id', $partyId)->count())->toBe(0);

    $this->withToken(ptToken(PT_USER_B))
        ->getJson("/api/v1/characters/{$member->id}/parties/{$partyId}")
        ->assertNotFound();
});

it('dissolves the party when the last member leaves', function () {
    $leader = ptChar(PT_USER_A);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'Solo'])->json('id');

    $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties/{$partyId}/leave")
        ->assertOk()
        ->assertJsonPath('dissolved', true);

    expect(Party::find($partyId))->toBeNull();
});

it('returns a party snapshot and 404 for an unknown party', function () {
    $leader = ptChar(PT_USER_A, ['class' => 'Cleric']);
    $partyId = $this->withToken(ptToken(PT_USER_A))
        ->postJson("/api/v1/characters/{$leader->id}/parties", ['name' => 'Widok'])->json('id');

    $this->withToken(ptToken(PT_USER_A))
        ->getJson("/api/v1/characters/{$leader->id}/parties/{$partyId}")
        ->assertOk()
        ->assertJsonPath('id', $partyId)
        ->assertJsonPath('name', 'Widok')
        ->assertJsonCount(1, 'members')
        ->assertJsonMissingPath('password');

    $this->withToken(ptToken(PT_USER_A))
        ->getJson("/api/v1/characters/{$leader->id}/parties/00000000-0000-0000-0000-000000000000")
        ->assertNotFound();
});

it('blocks acting on another user\'s character (403)', function () {
    $charA = ptChar(PT_USER_A);

    $this->withToken(ptToken(PT_USER_B))
        ->postJson("/api/v1/characters/{$charA->id}/parties", ['name' => 'Cudze'])
        ->assertForbidden();

    expect(Party::query()->count())->toBe(0);
});

it('requires authentication (401)', function () {
    $charA = ptChar(PT_USER_A);

    $this->postJson("/api/v1/characters/{$charA->id}/parties", ['name' => 'X'])
        ->assertUnauthorized();
});
