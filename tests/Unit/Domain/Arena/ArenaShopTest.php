<?php

declare(strict_types=1);

use App\Domain\Arena\ArenaShop;
use App\Domain\Content\ContentRepository;

/** Realna treść sklepu — shop.json ['elixirs'] (jedno źródło prawdy z frontem). */
function arenaShopElixirs(): array
{
    // tests/Unit/Domain/Arena → root: cztery poziomy w górę (Unit tests nie bootują Laravela).
    return (new ContentRepository(dirname(__DIR__, 4).'/resources/game-content'))->get('shop')['elixirs'];
}

it('lists mythic, stones, potions then elixirs in the catalog', function () {
    $catalog = ArenaShop::catalog(arenaShopElixirs());
    $ids = array_column($catalog, 'id');

    // Kolejność: mythic first, potem stony, poteki, na końcu eliksiry.
    expect($ids[0])->toBe('arena_mythic_main')
        ->and($ids)->toContain('arena_stone_common')
        ->and($ids)->toContain('arena_stone_mythic')
        ->and($ids)->toContain('arena_mp_100');

    // HP/MP poteki są WYKLUCZONE z dynamicznych eliksirów.
    expect($ids)->not->toContain('arena_elixir_hp_potion_sm')
        ->and($ids)->not->toContain('arena_elixir_mp_potion_divine')
        ->and($ids)->toContain('arena_elixir_xp_boost');
});

it('prices elixirs as max(50, floor(price/10))', function () {
    $catalog = ArenaShop::catalog(arenaShopElixirs());

    // xp_boost gold price 100000 → 10000 AP.
    $xp = collect($catalog)->firstWhere('id', 'arena_elixir_xp_boost');
    expect($xp['apPrice'])->toBe(10000)->and($xp['payloadId'])->toBe('xp_boost');

    // hp_boost_elixir gold price 5000 → floor(500) = 500 AP (nad floorem 50).
    $hpBoost = collect($catalog)->firstWhere('id', 'arena_elixir_hp_boost_elixir');
    expect($hpBoost['apPrice'])->toBe(500);
});

it('applies the 50-AP floor to cheap elixirs', function () {
    // Sztuczny tani eliksir: price 100 → floor(10) = 10 < 50 → 50.
    $cheap = [['id' => 'micro_elixir', 'price' => 100, 'minLevel' => 1, 'effect' => 'x']];
    $item = ArenaShop::findItem('arena_elixir_micro_elixir', $cheap);
    expect($item['apPrice'])->toBe(50);
});

it('scales mythic price by clamped level and keeps flat prices flat', function () {
    $mythic = ArenaShop::findItem('arena_mythic_main', []);
    expect(ArenaShop::apPrice($mythic, 5))->toBe(5000)     // 1000 × 5
        ->and(ArenaShop::apPrice($mythic, 0))->toBe(1000)  // clamp min 1
        ->and(ArenaShop::apPrice($mythic, 5000))->toBe(1_000_000); // clamp max 1000

    $stone = ArenaShop::findItem('arena_stone_legendary', []);
    expect(ArenaShop::apPrice($stone, 999))->toBe(3000);   // flat, ignoruje level
});

it('gates potions by their real potion payload min level', function () {
    // arena_hp_25 → hp_potion_great (200), arena_hp_100 → hp_potion_divine (700).
    expect(ArenaShop::getPotionMinLevel('hp_potion_great'))->toBe(200)
        ->and(ArenaShop::getPotionMinLevel('hp_potion_divine'))->toBe(700)
        ->and(ArenaShop::getPotionMinLevel('mp_potion_ultimate'))->toBe(500)
        // Nie-poteki (eliksiry) nie są bramkowane tym systemem → 1.
        ->and(ArenaShop::getPotionMinLevel('xp_boost'))->toBe(1);
});

it('resolves class weapon/offhand types with a template fallback', function () {
    expect(ArenaShop::weaponTypeForClass('Knight', 'sword'))->toBe('sword')
        ->and(ArenaShop::weaponTypeForClass('Mage', 'sword'))->toBe('staff')
        ->and(ArenaShop::offhandTypeForClass('Archer', 'shield'))->toBe('quiver')
        // Nieznana klasa → fallback z szablonu.
        ->and(ArenaShop::weaponTypeForClass('Unknown', 'sword'))->toBe('sword');
});

it('returns null for an unknown catalog id', function () {
    expect(ArenaShop::findItem('nope', arenaShopElixirs()))->toBeNull();
});
