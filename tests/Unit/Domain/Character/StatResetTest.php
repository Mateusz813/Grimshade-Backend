<?php

declare(strict_types=1);

use App\Domain\Character\StatReset;

it('resets a Knight to base + level-derived pools (parity handleStatReset)', function () {
    $res = StatReset::compute('Knight', currentHp: 5000, currentMp: 5000, highestLevel: 100);

    expect($res)->toBe([
        'attack' => 12,
        'defense' => 8,
        'max_hp' => 942,
        'max_mp' => 238,
        'hp' => 942,
        'mp' => 238,
        'stat_points' => 10,
    ]);
});

it('clamps current hp/mp down to the reset maxima, never up', function () {
    $res = StatReset::compute('Mage', currentHp: 40, currentMp: 999, highestLevel: 1);

    expect($res['max_hp'])->toBe(90)
        ->and($res['max_mp'])->toBe(200)
        ->and($res['hp'])->toBe(40)
        ->and($res['mp'])->toBe(200)
        ->and($res['stat_points'])->toBe(0);
});

it('returns null for an unknown class (front no-op)', function () {
    expect(StatReset::compute('Paladin', 100, 100, 50))->toBeNull();
});
