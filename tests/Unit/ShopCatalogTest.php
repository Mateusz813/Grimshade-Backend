<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;
use App\Domain\Shop\ShopCatalog;

function shopTemplates(): array
{
    return (new ContentRepository(dirname(__DIR__, 2).'/resources/game-content'))->get('itemTemplates');
}

it('computes shop price bit-for-bit with the TS calculateShopPrice', function () {
    expect(ShopCatalog::calculateShopPrice(100, 'common', 'weapon'))->toBe(3020)
        ->and(ShopCatalog::calculateShopPrice(100, 'rare', 'weapon'))->toBe(36240)
        ->and(ShopCatalog::calculateShopPrice(50, 'common', 'offhand'))->toBe(1270)
        ->and(ShopCatalog::calculateShopPrice(50, 'common', 'armor'))->toBe(1020)
        ->and(ShopCatalog::calculateShopPrice(5, 'common', 'accessory'))->toBe(100);
});

it('generates the class-correct weapon/offhand/armor/accessory ids for a Knight', function () {
    $catalog = (new ShopCatalog(shopTemplates()))->generate('Knight', 100);

    expect($catalog)->toHaveKey('shop_sword_100_common')
        ->and($catalog)->toHaveKey('shop_sword_100_rare')
        ->and($catalog)->toHaveKey('shop_shield_100_common')
        ->and($catalog)->toHaveKey('shop_heavy_helmet_100_common')
        ->and($catalog)->toHaveKey('shop_ring_100_common')
        ->and($catalog)->not->toHaveKey('shop_staff_100_common');

    expect($catalog['shop_sword_100_common']['price'])->toBe(3020)
        ->and($catalog['shop_sword_100_common']['templateType'])->toBe('weapon')
        ->and($catalog['shop_sword_100_common']['type'])->toBe('sword')
        ->and($catalog['shop_heavy_helmet_100_common']['templateType'])->toBe('armor')
        ->and($catalog['shop_heavy_helmet_100_common']['armorPrefix'])->toBe('heavy')
        ->and($catalog['shop_heavy_helmet_100_common']['slot'])->toBe('helmet');
});

it('caps generated item level at SHOP_ITEM_LEVEL_CAP (100)', function () {
    $catalog = (new ShopCatalog(shopTemplates()))->generate('Mage', 500);

    expect($catalog)->toHaveKey('shop_staff_100_common')
        ->and($catalog)->not->toHaveKey('shop_staff_500_common')
        ->and($catalog['shop_staff_100_common']['level'])->toBe(100);
});

it('maps each class to its correct armor prefix', function () {
    $gen = new ShopCatalog(shopTemplates());

    expect($gen->generate('Archer', 10))->toHaveKey('shop_light_armor_10_common')
        ->and($gen->generate('Mage', 10))->toHaveKey('shop_magic_armor_10_common')
        ->and($gen->generate('Knight', 10))->toHaveKey('shop_heavy_armor_10_common');
});
