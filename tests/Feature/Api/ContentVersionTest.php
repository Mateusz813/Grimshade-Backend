<?php

declare(strict_types=1);

it('exposes a content version hash without auth', function () {
    $response = $this->getJson('/api/v1/content/version');

    $response->assertOk();
    expect($response->json('version'))
        ->toBeString()
        ->toHaveLength(16);
});

it('returns a stable, deterministic version across calls', function () {
    $first = $this->getJson('/api/v1/content/version')->json('version');
    $second = $this->getJson('/api/v1/content/version')->json('version');

    expect($first)->toBe($second);
});
