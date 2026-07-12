<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;
use App\Domain\Items\StoneSystem;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Support\Rng\Mulberry32Rng;

function rerollGenerator(int $seed): ItemGenerator
{
    $content = new ContentRepository(dirname(__DIR__, 4).'/resources/game-content');

    return new ItemGenerator($content->get('itemTemplates'), new Mulberry32Rng($seed));
}

it('maps the stone conversion chain and costs (parity itemSystem.ts)', function () {
    expect(StoneSystem::higherTier('common_stone'))->toBe('rare_stone')
        ->and(StoneSystem::higherTier('rare_stone'))->toBe('epic_stone')
        ->and(StoneSystem::higherTier('epic_stone'))->toBe('legendary_stone')
        ->and(StoneSystem::higherTier('legendary_stone'))->toBe('mythic_stone')
        ->and(StoneSystem::higherTier('mythic_stone'))->toBe('heroic_stone')
        ->and(StoneSystem::higherTier('heroic_stone'))->toBeNull()
        ->and(StoneSystem::STONE_CONVERSION_COST)->toBe(100)
        ->and(StoneSystem::STONE_CONVERSION_GOLD)->toBe(1000);
});

it('returns the base stat keys per slot (parity itemSystem.ts)', function () {
    expect(ItemGenerator::getBaseStatKeysForSlot('mainHand'))->toBe(['dmg_min', 'dmg_max', 'attack', 'defense'])
        ->and(ItemGenerator::getBaseStatKeysForSlot('offHand'))->toBe(['dmg_min', 'dmg_max', 'attack', 'defense'])
        ->and(ItemGenerator::getBaseStatKeysForSlot('helmet'))->toBe(['hp'])
        ->and(ItemGenerator::getBaseStatKeysForSlot('boots'))->toBe(['hp'])
        ->and(ItemGenerator::getBaseStatKeysForSlot('gloves'))->toBe(['attack'])
        ->and(ItemGenerator::getBaseStatKeysForSlot('ring1'))->toBe(['attack'])
        ->and(ItemGenerator::getBaseStatKeysForSlot('necklace'))->toBe(['defense'])
        ->and(ItemGenerator::getBaseStatKeysForSlot('earrings'))->toBe(['defense'])
        ->and(ItemGenerator::getBaseStatKeysForSlot(null))->toBe([]);
});

it('preserves base stats and regenerates the right number of random bonuses', function () {
    $gen = rerollGenerator(7);
    $item = ['rarity' => 'rare', 'bonuses' => ['hp' => 123, 'speed' => 9]];

    $new = $gen->rerollItemBonuses($item, 'helmet');

    expect($new['hp'])->toBe(123)
        ->and(count($new))->toBe(2)
        ->and(array_key_exists('hp', $new))->toBeTrue();
    $randomKeys = array_values(array_diff(array_keys($new), ['hp']));
    expect($randomKeys)->toHaveCount(1)
        ->and($randomKeys[0])->not->toBe('hp');
});

it('returns bonuses unchanged when slot is null (parity TS)', function () {
    $gen = rerollGenerator(7);
    $item = ['rarity' => 'rare', 'bonuses' => ['hp' => 50, 'attack' => 10]];

    expect($gen->rerollItemBonuses($item, null))->toBe(['hp' => 50, 'attack' => 10]);
});
