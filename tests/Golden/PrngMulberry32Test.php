<?php

declare(strict_types=1);

use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

it('reproduces the canonical JS mulberry32 uint32 sequence for every seed', function () {
    $golden = Golden::load('prng/mulberry32.json');

    foreach ($golden['sequences'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);

        $actual = [];
        foreach ($case['u32'] as $ignored) {
            $actual[] = $rng->nextUint32();
        }

        expect($actual)->toBe(
            $case['u32'],
            "mulberry32 rozjechał się dla seed {$case['seed']}",
        );
    }
});

it('derives nextFloat as uint32 / 2^32 (in [0,1))', function () {
    $golden = Golden::load('prng/mulberry32.json');
    $case = $golden['sequences'][0];

    $rng = new Mulberry32Rng($case['seed']);
    foreach ($case['u32'] as $expectedU32) {
        $f = $rng->nextFloat();
        expect($f)->toBe($expectedU32 / 4294967296.0)
            ->and($f)->toBeGreaterThanOrEqual(0.0)
            ->and($f)->toBeLessThan(1.0);
    }
});

it('is fully deterministic — same seed, same sequence', function () {
    $a = new Mulberry32Rng(999);
    $b = new Mulberry32Rng(999);

    $seqA = array_map(fn () => $a->nextUint32(), range(1, 20));
    $seqB = array_map(fn () => $b->nextUint32(), range(1, 20));

    expect($seqA)->toBe($seqB);
});

it('nextInt stays within the inclusive range and shuffle preserves elements', function () {
    $rng = new Mulberry32Rng(42);

    foreach (range(1, 100) as $ignored) {
        $n = $rng->nextInt(3, 7);
        expect($n)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(7);
    }

    $shuffled = (new Mulberry32Rng(42))->shuffle([1, 2, 3, 4, 5]);
    expect($shuffled)->toHaveCount(5)
        ->and(collect($shuffled)->sort()->values()->all())->toBe([1, 2, 3, 4, 5]);
});
