<?php

declare(strict_types=1);

use App\Domain\Market\MarketMath;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('marketSystem.json');
});

it('matches isValidPrice', function () {
    foreach ($this->golden['isValidPrice'] as $case) {
        expect(MarketMath::isValidPrice($case['price']))
            ->toEqual($case['value'], "isValidPrice({$case['price']})");
    }
});

it('matches isValidQuantity (with/without max)', function () {
    foreach ($this->golden['isValidQuantity'] as $case) {
        $actual = $case['max'] === null
            ? MarketMath::isValidQuantity($case['qty'])
            : MarketMath::isValidQuantity($case['qty'], $case['max']);
        expect($actual)->toEqual($case['value'], 'isValidQuantity '.json_encode([$case['qty'], $case['max']]));
    }
});

it('matches calculateMarketTax', function () {
    foreach ($this->golden['calculateMarketTax'] as $case) {
        expect(MarketMath::calculateMarketTax($case['price']))->toEqual($case['value']);
    }
});

it('matches isStackKind', function () {
    foreach ($this->golden['isStackKind'] as $case) {
        expect(MarketMath::isStackKind($case['kind']))->toEqual($case['value']);
    }
});
