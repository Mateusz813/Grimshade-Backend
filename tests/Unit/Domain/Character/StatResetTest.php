<?php

declare(strict_types=1);

use App\Domain\Character\StatReset;

it('resets a Knight to base + level-derived pools (parity handleStatReset)', function () {
    // Knight base: atk10 def5 hp120 mp30; perLevel hp8 mp2; 2 stat pts/level.
    // highestLevel 100 → levelsGained 99.
    $res = StatReset::compute('Knight', currentHp: 5000, currentMp: 5000, highestLevel: 100);

    expect($res)->toBe([
        'attack' => 10,
        'defense' => 5,
        'max_hp' => 912,          // 120 + 99*8
        'max_mp' => 228,          // 30 + 99*2
        'hp' => 912,              // min(5000, 912)
        'mp' => 228,              // min(5000, 228)
        'stat_points' => 198,     // 99 * 2
    ]);
});

it('clamps current hp/mp down to the reset maxima, never up', function () {
    // Level 1 highest → levelsGained 0 → maxima = class base only.
    $res = StatReset::compute('Mage', currentHp: 40, currentMp: 999, highestLevel: 1);

    expect($res['max_hp'])->toBe(80)          // Mage base
        ->and($res['max_mp'])->toBe(200)
        ->and($res['hp'])->toBe(40)           // min(40, 80) — stays low
        ->and($res['mp'])->toBe(200)          // min(999, 200) — clamped down
        ->and($res['stat_points'])->toBe(0);  // no levels gained
});

it('returns null for an unknown class (front no-op)', function () {
    expect(StatReset::compute('Paladin', 100, 100, 50))->toBeNull();
});
