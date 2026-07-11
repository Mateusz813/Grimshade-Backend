<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TokenFactory;

uses(RefreshDatabase::class);

const SCM_USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const SCM_USER_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

function scmChar(string $userId = SCM_USER): Character
{
    return Character::factory()->forUser($userId)->create([
        'class' => 'Mage', 'level' => 5, 'attack' => 10, 'gold' => 0,
    ]);
}

function scmToken(string $userId = SCM_USER): string
{
    return TokenFactory::forUser($userId);
}

/**
 * Pełny blob w kształcie GET /state.state — end-game „god save": 363M golda,
 * mityczny/heroiczny gear, wysoki level. MUSI przejść w trybie SOFT bez 422.
 *
 * @param  array<string, mixed>  $extraBag
 */
function godBlob(Character $c, int $gold = 363_000_000, array $extraBag = []): array
{
    return [
        '_ownerCharacterId' => $c->id,
        '_characterStats' => [
            'level' => 900, 'xp' => 1234, 'hp' => 50000, 'max_hp' => 50000,
            'mp' => 8000, 'max_mp' => 8000, 'attack' => 120000, 'defense' => 9000,
            'attack_speed' => 3.5, 'crit_chance' => 0.5, 'crit_damage' => 3.2,
            'magic_level' => 400, 'stat_points' => 12, 'highest_level' => 905,
            'gold' => $gold,
        ],
        'inventory' => [
            'gold' => $gold,
            'bag' => [
                [
                    'uuid' => 'heroic-1', 'itemId' => 'ring_lvl500_heroic', 'rarity' => 'heroic',
                    'bonuses' => ['attack' => 4000, 'critChance' => 30], 'itemLevel' => 500, 'upgradeLevel' => 20,
                ],
                ...$extraBag,
            ],
            'equipment' => [
                'ring1' => [
                    'uuid' => 'mythic-1', 'itemId' => 'ring_lvl400_mythic', 'rarity' => 'mythic',
                    'bonuses' => ['attack' => 900, 'critChance' => 12], 'itemLevel' => 400, 'upgradeLevel' => 15,
                ],
            ],
            'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0,
        ],
        'skills' => ['skillLevels' => ['magic_level' => 300]],
        'settings' => ['language' => 'pl'],
    ];
}

/**
 * Kompaktowy, kontrolowany blob do testów zdarzeń (małe, wiarygodne wartości —
 * w przeciwieństwie do god-save'a). $extra scala się rekurencyjnie (np. slice
 * dungeons/bosses/raid). NIE wkładaj tu inventory.bag — od tego jest $bag.
 *
 * @param  list<array<string, mixed>>  $bag
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function scmBlob(Character $c, int $level = 5, int $gold = 1000, array $bag = [], array $extra = []): array
{
    return array_replace_recursive([
        '_ownerCharacterId' => $c->id,
        '_characterStats' => [
            'level' => $level, 'xp' => 0, 'hp' => 100, 'max_hp' => 100,
            'mp' => 50, 'max_mp' => 50, 'attack' => 10, 'defense' => 5,
            'attack_speed' => 1.0, 'crit_chance' => 0.05, 'crit_damage' => 1.5,
            'magic_level' => 1, 'stat_points' => 0, 'highest_level' => $level,
            'gold' => $gold,
        ],
        'inventory' => [
            'gold' => $gold, 'bag' => $bag, 'equipment' => [],
            'deposit' => [], 'consumables' => [], 'stones' => [], 'arenaPoints' => 0,
        ],
        'skills' => ['skillLevels' => []],
        'settings' => ['language' => 'pl'],
    ], $extra);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scmItem(string $uuid, array $overrides = []): array
{
    return array_replace([
        'uuid' => $uuid, 'itemId' => 'sword_common', 'rarity' => 'common',
        'bonuses' => ['attack' => 3], 'itemLevel' => 5, 'upgradeLevel' => 0,
    ], $overrides);
}

// ---- Happy path: persist + round-trip --------------------------------------

it('persists the full blob and character row, gold survives round-trip', function () {
    $c = scmChar();

    $res = $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'commit-1',
        'state' => godBlob($c),
    ]);

    $res->assertOk()
        ->assertJsonPath('state.inventory.gold', 363_000_000)
        ->assertJsonPath('character.id', $c->id)
        ->assertJsonPath('character.level', 900)
        ->assertJsonPath('character.attack', 120000)
        ->assertJsonPath('character.gold', 363_000_000); // szczątkowa kolumna = inventory.gold

    // Blob zapisany w game_saves.
    $save = GameSave::where('character_id', $c->id)->first();
    expect($save->state['inventory']['gold'])->toBe(363_000_000)
        ->and($save->state['inventory']['equipment']['ring1']['itemId'])->toBe('ring_lvl400_mythic');

    // Kolumny characters zapisane z _characterStats.
    $fresh = Character::find($c->id);
    expect($fresh->level)->toBe(900)
        ->and($fresh->attack)->toBe(120000)
        ->and($fresh->magic_level)->toBe(400)
        ->and($fresh->gold)->toBe(363_000_000);

    // GET /state zwraca dokładnie to, co zapisaliśmy (hydracja frontu).
    $this->withToken(scmToken())->getJson("/api/v1/characters/{$c->id}/state")
        ->assertOk()
        ->assertJsonPath('state.inventory.gold', 363_000_000)
        ->assertJsonPath('character.level', 900);
});

// ---- SOFT mode: over-powered item persists (no 422) ------------------------

it('SOFT mode persists an over-powered item instead of rejecting (no 422)', function () {
    $c = scmChar();

    // Bonus rażąco ponad hojne legalne max dla common lvl1 → naruszenie SOFT (log),
    // ale zapis MUSI przejść (domyślnie config state_commit_strict = false).
    $overpowered = [
        'uuid' => 'cheat-1', 'itemId' => 'sword_lvl1_common', 'rarity' => 'common',
        'bonuses' => ['attack' => 999_999_999], 'itemLevel' => 1, 'upgradeLevel' => 0,
    ];

    $res = $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'commit-op',
        'state' => godBlob($c, 1000, [$overpowered]),
    ]);

    $res->assertOk(); // NIE 422 — właściciel nie może być fałszywie odrzucony
    $save = GameSave::where('character_id', $c->id)->first();
    expect(collect($save->state['inventory']['bag'])->firstWhere('uuid', 'cheat-1'))->not->toBeNull();
});

// ---- Sanitization ----------------------------------------------------------

it('sanitizes negative gold to zero', function () {
    $c = scmChar();
    $blob = godBlob($c);
    $blob['inventory']['gold'] = -500;
    $blob['_characterStats']['gold'] = -500;

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'commit-neg', 'state' => $blob,
    ])->assertOk()->assertJsonPath('state.inventory.gold', 0);

    expect(Character::find($c->id)->gold)->toBe(0);
});

// ---- Idempotency -----------------------------------------------------------

it('is idempotent per requestId — replay returns the cached first payload', function () {
    $c = scmChar();

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'commit-idem', 'state' => godBlob($c, 1000),
    ])->assertOk()->assertJsonPath('state.inventory.gold', 1000);

    // Ten sam requestId, INNY gold → serwer zwraca cache (1000), NIE zapisuje 9999.
    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'commit-idem', 'state' => godBlob($c, 9999),
    ])->assertOk()->assertJsonPath('state.inventory.gold', 1000);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(1000);
});

// ---- Auth / ownership ------------------------------------------------------

it('requires authentication (401)', function () {
    $c = scmChar();

    $this->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'commit-noauth', 'state' => godBlob($c),
    ])->assertUnauthorized();
});

it('rejects committing to another user\'s character (403)', function () {
    $other = scmChar(SCM_USER_B);

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$other->id}/state", [
        'requestId' => 'commit-forbidden', 'state' => godBlob($other),
    ])->assertForbidden();
});

it('validates the request body (requestId + state required)', function () {
    $c = scmChar();

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'only-id',
    ])->assertStatus(422);
});

// ---- Event validation: happy path ------------------------------------------

it('commits WITH an event (dungeon won) and persists the new state + drop', function () {
    $c = scmChar(); // level 5, fresh (no prev blob)
    $today = now()->toDateString();

    $blob = scmBlob($c, level: 6, gold: 1500, bag: [scmItem('drop-1')], extra: [
        'dungeons' => ['dailyAttempts' => ['dungeon_1' => ['used' => 1, 'date' => $today]]],
    ]);

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'ev-happy',
        'state' => $blob,
        'event' => ['type' => 'dungeon', 'sourceId' => 'dungeon_1', 'outcome' => 'won'],
    ])->assertOk()
        ->assertJsonPath('state.inventory.gold', 1500)
        ->assertJsonPath('character.level', 6);

    $save = GameSave::where('character_id', $c->id)->first();
    expect(collect($save->state['inventory']['bag'])->firstWhere('uuid', 'drop-1'))->not->toBeNull()
        ->and($save->state['dungeons']['dailyAttempts']['dungeon_1']['used'])->toBe(1);
});

// ---- HARD check: duplicate uuid -> 422 EVEN in default (soft) mode ----------

it('rejects a duplicate item uuid across bag + equipment with 422 in soft mode', function () {
    $c = scmChar();

    // config domyślny: event_validation_strict = false → HARD i tak egzekwowany.
    $dup = scmItem('dup-1');
    $blob = scmBlob($c);
    $blob['inventory']['bag'] = [$dup];
    $blob['inventory']['equipment']['mainHand'] = $dup; // ten sam uuid w dwóch miejscach

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'ev-dupe',
        'state' => $blob,
        'event' => ['type' => 'hunt'],
    ])->assertStatus(422);

    // Transakcja wycofana — nic się nie zapisało.
    expect(GameSave::where('character_id', $c->id)->first())->toBeNull();
});

it('rejects a duplicate item uuid with 422 EVEN WITHOUT an event (root-cause regression)', function () {
    $c = scmChar();

    // Regresja bypassu: atakujący POMIJA `event`, żeby ominąć walidację. Teraz
    // ALWAYS-RUN guardInvariants łapie dupe uuid na KAŻDYM commicie → 422, rollback.
    $dup = scmItem('dup-2');
    $blob = scmBlob($c);
    $blob['inventory']['bag'] = [$dup];
    $blob['inventory']['equipment']['mainHand'] = $dup; // ten sam uuid w dwóch miejscach

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'no-ev-dupe',
        'state' => $blob,
        // celowo BEZ 'event'
    ])->assertStatus(422);

    // Transakcja wycofana — nic się nie zapisało.
    expect(GameSave::where('character_id', $c->id)->first())->toBeNull();
});

// ---- ALWAYS-RUN HARD invariants (event obecny czy nie) ----------------------

it('rejects a level jump > 50 WITHOUT an event with 422', function () {
    $c = scmChar();

    // Baseline (bez eventu) ustala prev z poziomem 5.
    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'lvljump-base',
        'state' => scmBlob($c, level: 5),
    ])->assertOk();

    // Kolejny commit bez eventu skacze 5 -> 100 (+95 > 50) → HARD 422, rollback.
    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'lvljump-big',
        'state' => scmBlob($c, level: 100),
    ])->assertStatus(422);

    // Poprzedni (baseline) blob przetrwał — nadal poziom 5.
    expect(GameSave::where('character_id', $c->id)->first()->state['_characterStats']['level'])->toBe(5);
});

it('rejects absurd gold (9e15) with 422 WITHOUT an event', function () {
    $c = scmChar();

    $blob = scmBlob($c);
    $blob['inventory']['gold'] = 9_000_000_000_000_000; // 9e15, powyżej sufitu 1e12
    $blob['_characterStats']['gold'] = 9_000_000_000_000_000;

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'absurd-gold',
        'state' => $blob,
    ])->assertStatus(422);

    expect(GameSave::where('character_id', $c->id)->first())->toBeNull();
});

it('rejects an absurd consumable stack (> 100k) with 422 WITHOUT an event', function () {
    $c = scmChar();

    $blob = scmBlob($c);
    $blob['inventory']['consumables'] = ['health_potion' => 500_000];

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'absurd-consumable',
        'state' => $blob,
    ])->assertStatus(422);

    expect(GameSave::where('character_id', $c->id)->first())->toBeNull();
});

it('rejects an absurd stone stack (> 100k) with 422 WITHOUT an event', function () {
    $c = scmChar();

    $blob = scmBlob($c);
    $blob['inventory']['stones'] = ['power' => 250_000];

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'absurd-stone',
        'state' => $blob,
    ])->assertStatus(422);

    expect(GameSave::where('character_id', $c->id)->first())->toBeNull();
});

it('accepts a normal small commit WITHOUT an event (backward compat)', function () {
    $c = scmChar();

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'small-no-ev',
        'state' => scmBlob($c, level: 5, gold: 1234, bag: [scmItem('sole-1')]),
    ])->assertOk()->assertJsonPath('state.inventory.gold', 1234);

    expect(GameSave::where('character_id', $c->id)->first())->not->toBeNull();
});

// ---- SAFETY: realny end-game save właściciela MUSI przejść (zero false-reject) --

it('persists a realistic end-game save (363M gold, level 345, unique-uuid mythic gear) WITHOUT event', function () {
    $c = scmChar();

    // 365 unikatowych itemów w bag.
    $bag = [];
    for ($i = 0; $i < 365; $i++) {
        $bag[] = scmItem("bag-{$i}", [
            'itemId' => 'ring_lvl400_mythic', 'rarity' => 'mythic', 'itemLevel' => 400,
            'upgradeLevel' => 15, 'bonuses' => ['attack' => 800, 'critChance' => 10],
        ]);
    }

    // 12 założonych mitycznych/heroicznych itemów, każdy z unikatowym uuid.
    $slots = ['helmet', 'armor', 'pants', 'gloves', 'shoulders', 'boots',
        'mainHand', 'offHand', 'ring1', 'ring2', 'earrings', 'necklace'];
    $equipment = [];
    foreach ($slots as $idx => $slot) {
        $equipment[$slot] = scmItem("eq-{$slot}", [
            'itemId' => 'ring_lvl400_mythic', 'rarity' => $idx % 2 === 0 ? 'mythic' : 'heroic',
            'itemLevel' => 400, 'upgradeLevel' => 18, 'bonuses' => ['attack' => 1500, 'critChance' => 15],
        ]);
    }

    $blob = scmBlob($c, level: 345, gold: 363_637_692, bag: $bag, extra: [
        '_characterStats' => [
            'level' => 345, 'xp' => 5000, 'attack' => 90000, 'defense' => 8000,
            'max_hp' => 40000, 'magic_level' => 400, 'highest_level' => 345,
        ],
        'inventory' => [
            'equipment' => $equipment,
            'consumables' => ['health_potion' => 5000, 'mana_potion' => 5000],
            'stones' => ['power' => 20000], 'arenaPoints' => 50000,
        ],
        'skills' => ['skillLevels' => ['magic_level' => 450, 'sword_fighting' => 400]],
    ]);

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'endgame-safe',
        'state' => $blob,
        // celowo BEZ 'event' — realny zapis właściciela nie może być fałszywie odrzucony
    ])->assertOk()
        ->assertJsonPath('state.inventory.gold', 363_637_692)
        ->assertJsonPath('character.level', 345);

    $save = GameSave::where('character_id', $c->id)->first();
    expect($save)->not->toBeNull()
        ->and($save->state['inventory']['gold'])->toBe(363_637_692)
        ->and(count($save->state['inventory']['bag']))->toBe(365)
        ->and($save->state['inventory']['equipment']['necklace']['uuid'])->toBe('eq-necklace');

    expect(Character::find($c->id)->level)->toBe(345);
});

// ---- HARD check: gold ------------------------------------------------------

it('sanitizes negative gold to zero even with an event present (no 422)', function () {
    $c = scmChar();
    $blob = scmBlob($c);
    $blob['inventory']['gold'] = -500;
    $blob['_characterStats']['gold'] = -500;

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'ev-neg-gold',
        'state' => $blob,
        'event' => ['type' => 'hunt', 'outcome' => 'won'],
    ])->assertOk()->assertJsonPath('state.inventory.gold', 0);
});

// ---- SOFT check: new legal item accepted (no false reject) -----------------

it('accepts a genuinely-new item uuid (new drop) without false-rejecting', function () {
    $c = scmChar();

    // Baseline (bez eventu) ustawia prev z jednym itemem.
    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'ev-base',
        'state' => scmBlob($c, bag: [scmItem('old-1')]),
    ])->assertOk();

    // Commit ze zdarzeniem dokłada NOWY uuid (legalny drop) — akceptacja.
    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'ev-new-item',
        'state' => scmBlob($c, bag: [scmItem('old-1'), scmItem('new-2')]),
        'event' => ['type' => 'hunt', 'outcome' => 'won'],
    ])->assertOk();

    $save = GameSave::where('character_id', $c->id)->first();
    expect(collect($save->state['inventory']['bag'])->pluck('uuid')->all())
        ->toContain('old-1')->toContain('new-2');
});

// ---- SOFT vs STRICT: over-cap daily attempts -------------------------------

it('SOFT mode persists an over-cap daily-attempt transition (no 422)', function () {
    $c = scmChar();
    $today = now()->toDateString();

    $blob = scmBlob($c, extra: [
        'dungeons' => ['dailyAttempts' => ['dungeon_1' => ['used' => 12, 'date' => $today]]],
    ]);

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'ev-overcap-soft',
        'state' => $blob,
        'event' => ['type' => 'dungeon', 'sourceId' => 'dungeon_1', 'outcome' => 'won'],
    ])->assertOk(); // domyślnie SOFT — logujemy, ale zapisujemy

    expect(GameSave::where('character_id', $c->id)->first()->state['dungeons']['dailyAttempts']['dungeon_1']['used'])
        ->toBe(12);
});

it('STRICT mode rejects the same over-cap daily-attempt transition with 422', function () {
    config()->set('supabase.event_validation_strict', true);

    $c = scmChar();
    $today = now()->toDateString();

    $blob = scmBlob($c, extra: [
        'dungeons' => ['dailyAttempts' => ['dungeon_1' => ['used' => 12, 'date' => $today]]],
    ]);

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'ev-overcap-strict',
        'state' => $blob,
        'event' => ['type' => 'dungeon', 'sourceId' => 'dungeon_1', 'outcome' => 'won'],
    ])->assertStatus(422);

    // Rollback — nic nie zapisane.
    expect(GameSave::where('character_id', $c->id)->first())->toBeNull();
});

// ---- Idempotency with an event ---------------------------------------------

it('is idempotent per requestId when an event is present (replay returns cache)', function () {
    $c = scmChar();

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'ev-idem',
        'state' => scmBlob($c, gold: 1000),
        'event' => ['type' => 'hunt', 'outcome' => 'won'],
    ])->assertOk()->assertJsonPath('state.inventory.gold', 1000);

    $this->withToken(scmToken())->putJson("/api/v1/characters/{$c->id}/state", [
        'requestId' => 'ev-idem',
        'state' => scmBlob($c, gold: 9999),
        'event' => ['type' => 'hunt', 'outcome' => 'won'],
    ])->assertOk()->assertJsonPath('state.inventory.gold', 1000);

    expect(GameSave::where('character_id', $c->id)->first()->state['inventory']['gold'])->toBe(1000);
});
