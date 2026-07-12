<?php

declare(strict_types=1);

use App\Domain\Chat\SystemChatMessages;
use App\Models\Character;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const CH_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const CH_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function chChar(array $attrs = []): Character
{
    return Character::factory()->forUser(CH_USER)->create(array_merge([
        'name' => 'Krasek', 'class' => 'Archer', 'level' => 42,
    ], $attrs));
}

function chToken(string $userId = CH_USER): string
{
    return TokenFactory::forUser($userId);
}

function chSeed(string $channel, string $content, string $name, mixed $createdAt, array $attrs = []): Message
{
    return Message::create(array_merge([
        'user_id' => CH_USER,
        'channel' => $channel,
        'character_name' => $name,
        'character_class' => 'Mage',
        'character_level' => 5,
        'content' => $content,
        'created_at' => $createdAt,
    ], $attrs));
}


it('posts a message with server-sourced identity (anti-forge)', function () {
    $c = chChar();

    $res = $this->withToken(chToken())->postJson("/api/v1/characters/{$c->id}/chat/messages", [
        'channel' => 'city',
        'content' => 'Witajcie w Grimshade!',
        'character_name' => 'HACKER', 'character_level' => 999, 'user_id' => CH_USER_B,
    ]);

    $res->assertCreated()
        ->assertJsonPath('character_name', 'Krasek')
        ->assertJsonPath('character_class', 'Archer')
        ->assertJsonPath('character_level', 42)
        ->assertJsonPath('channel', 'city')
        ->assertJsonPath('content', 'Witajcie w Grimshade!')
        ->assertJsonPath('user_id', CH_USER);

    expect(Message::count())->toBe(1);
});

it('rejects a message over the length cap (422, nothing inserted)', function () {
    $c = chChar();

    $this->withToken(chToken())->postJson("/api/v1/characters/{$c->id}/chat/messages", [
        'channel' => 'city',
        'content' => str_repeat('a', 301),
    ])->assertStatus(422);

    expect(Message::count())->toBe(0);
});

it('rejects a whitespace-only message (422)', function () {
    $c = chChar();

    $this->withToken(chToken())->postJson("/api/v1/characters/{$c->id}/chat/messages", [
        'channel' => 'city', 'content' => '    ',
    ])->assertStatus(422);

    expect(Message::count())->toBe(0);
});

it('enforces a simple rate limit (429) on rapid posts', function () {
    $c = chChar();
    $body = ['channel' => 'city', 'content' => 'spam'];
    $url = "/api/v1/characters/{$c->id}/chat/messages";

    $this->withToken(chToken())->postJson($url, $body)->assertCreated();
    $this->withToken(chToken())->postJson($url, $body)->assertStatus(429);

    $this->travel(5)->seconds();
    $this->withToken(chToken())->postJson($url, $body)->assertCreated();

    expect(Message::count())->toBe(2);
});


it('reads the channel feed newest-first, filtered by channel', function () {
    chSeed('city', 'stary', 'A', now()->subMinutes(2));
    chSeed('city', 'nowy', 'B', now());
    chSeed('system', 'inny kanal', 'C', now());

    $rows = $this->withToken(chToken())->getJson('/api/v1/chat/messages?channel=city')->json();

    expect(collect($rows)->pluck('content')->all())->toBe(['nowy', 'stary']);
});

it('honours the limit parameter (newest N)', function () {
    foreach (range(1, 5) as $i) {
        chSeed('city', "m{$i}", 'X', now()->addSeconds($i));
    }

    $rows = $this->withToken(chToken())->getJson('/api/v1/chat/messages?channel=city&limit=2')->json();

    expect(count($rows))->toBe(2)
        ->and(collect($rows)->pluck('content')->all())->toBe(['m5', 'm4']);
});

it('requires a channel on the feed (422)', function () {
    $this->withToken(chToken())->getJson('/api/v1/chat/messages')->assertStatus(422);
});


it('broadcasts an upgrade system event in SystemChatMessages format', function () {
    $c = chChar();

    $res = $this->withToken(chToken())->postJson("/api/v1/characters/{$c->id}/chat/system-event", [
        'type' => 'upgrade',
        'itemId' => 'luk',
        'rarity' => 'common',
        'upgradeLevel' => 5,
        'itemName' => 'Krótki Łuk',
    ]);

    $expected = SystemChatMessages::formatSystemMessage([
        'type' => 'upgrade',
        'itemId' => 'luk',
        'rarity' => 'common',
        'upgradeLevel' => 5,
        'itemName' => 'Krótki Łuk',
    ]);

    $res->assertCreated()
        ->assertJsonPath('channel', 'system')
        ->assertJsonPath('character_name', 'Krasek')
        ->assertJsonPath('content', $expected);

    expect(SystemChatMessages::parseSystemMessage($res->json('content')))->toBe([
        'type' => 'upgrade',
        'itemId' => 'luk',
        'rarity' => 'common',
        'upgradeLevel' => 5,
        'itemName' => 'Krótki Łuk',
    ]);
});

it('broadcasts a skillUpgrade system event', function () {
    $c = chChar();

    $res = $this->withToken(chToken())->postJson("/api/v1/characters/{$c->id}/chat/system-event", [
        'type' => 'skillUpgrade',
        'skillId' => 'power_strike',
        'skillName' => 'Potężny Cios',
        'upgradeLevel' => 10,
    ]);

    $res->assertCreated()->assertJsonPath('channel', 'system');

    expect(SystemChatMessages::parseSystemMessage($res->json('content')))->toBe([
        'type' => 'skillUpgrade',
        'skillId' => 'power_strike',
        'skillName' => 'Potężny Cios',
        'upgradeLevel' => 10,
    ]);
});

it('rejects a non-milestone upgrade system event (422)', function () {
    $c = chChar();

    $this->withToken(chToken())->postJson("/api/v1/characters/{$c->id}/chat/system-event", [
        'type' => 'upgrade', 'itemId' => 'luk', 'rarity' => 'common',
        'upgradeLevel' => 4, 'itemName' => 'Krótki Łuk',
    ])->assertStatus(422);

    expect(Message::count())->toBe(0);
});

it('rejects an unknown system event type (422)', function () {
    $c = chChar();

    $this->withToken(chToken())->postJson("/api/v1/characters/{$c->id}/chat/system-event", [
        'type' => 'god_mode', 'upgradeLevel' => 10,
    ])->assertStatus(422);
});


it('cannot post to another user\'s character (403)', function () {
    $other = Character::factory()->forUser(CH_USER_B)->create();

    $this->withToken(chToken())->postJson("/api/v1/characters/{$other->id}/chat/messages", [
        'channel' => 'city', 'content' => 'hej',
    ])->assertForbidden();
});

it('requires auth (401)', function () {
    $c = chChar();

    $this->getJson('/api/v1/chat/messages?channel=city')->assertUnauthorized();
    $this->postJson("/api/v1/characters/{$c->id}/chat/messages", [
        'channel' => 'city', 'content' => 'hej',
    ])->assertUnauthorized();
});
