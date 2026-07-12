<?php

declare(strict_types=1);

use App\Domain\Arena\ArenaShop;
use App\Domain\Content\ContentRepository;

function arenaShopElixirs(): array
{
    return (new ContentRepository(dirname(__DIR__, 4).'/resources/game-content'))->get('shop')['elixirs'];
}

it('lists mythic, stones, potions then elixirs in the catalog', function () {
    $catalog = ArenaShop::catalog(arenaShopElixirs());
    $ids = array_column($catalog, 'id');

    expect($ids[0])->toBe('arena_mythic_main')
        ->and($ids)->toContain('arena_stone_common')
        ->and($ids)->toContain('arena_stone_mythic')
        ->and($ids)->toContain('arena_mp_100');

    expect($ids)->not->toContain('arena_elixir_hp_potion_sm')
        ->and($ids)->not->toContain('arena_elixir_mp_potion_divine')
        ->and($ids)->toContain('arena_elixir_xp_boost');
});

it('prices elixirs as max(50, floor(price/10))', function () {
    $catalog = ArenaShop::catalog(arenaShopElixirs());

    $xp = collect($catalog)->firstWhere('id', 'arena_elixir_xp_boost');
    expect($xp['apPrice'])->toBe(10000)->and($xp['payloadId'])->toBe('xp_boost');

    $hpBoost = collect($catalog)->firstWhere('id', 'arena_elixir_hp_boost_elixir');
    expect($hpBoost['apPrice'])->toBe(500);
});

it('applies the 50-AP floor to cheap elixirs', function () {
    $cheap = [['id' => 'micro_elixir', 'price' => 100, 'minLevel' => 1, 'effect' => 'x']];
    $item = ArenaShop::findItem('arena_elixir_micro_elixir', $cheap);
    expect($item['apPrice'])->toBe(50);
});

it('scales mythic price by clamped level and keeps flat prices flat', function () {
    $mythic = ArenaShop::findItem('arena_mythic_main', []);
    expect(ArenaShop::apPrice($mythic, 5))->toBe(5000)
        ->and(ArenaShop::apPrice($mythic, 0))->toBe(1000)
        ->and(ArenaShop::apPrice($mythic, 5000))->toBe(1_000_000);

    $stone = ArenaShop::findItem('arena_stone_legendary', []);
    expect(ArenaShop::apPrice($stone, 999))->toBe(3000);
});

it('gates potions by their real potion payload min level', function () {
    expect(ArenaShop::getPotionMinLevel('hp_potion_great'))->toBe(200)
        ->and(ArenaShop::getPotionMinLevel('hp_potion_divine'))->toBe(700)
        ->and(ArenaShop::getPotionMinLevel('mp_potion_ultimate'))->toBe(500)
        ->and(ArenaShop::getPotionMinLevel('xp_boost'))->toBe(1);
});

it('resolves class weapon/offhand types with a template fallback', function () {
    expect(ArenaShop::weaponTypeForClass('Knight', 'sword'))->toBe('sword')
        ->and(ArenaShop::weaponTypeForClass('Mage', 'sword'))->toBe('staff')
        ->and(ArenaShop::offhandTypeForClass('Archer', 'shield'))->toBe('quiver')
        ->and(ArenaShop::weaponTypeForClass('Unknown', 'sword'))->toBe('sword');
});

it('returns null for an unknown catalog id', function () {
    expect(ArenaShop::findItem('nope', arenaShopElixirs()))->toBeNull();
});
