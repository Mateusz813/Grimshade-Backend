<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;

function contentFixtureDir(string $name = 'content'): string
{
    return dirname(__DIR__, 3).'/Fixtures/'.$name;
}

it('loads and decodes a content file', function () {
    $repo = new ContentRepository(contentFixtureDir());

    expect($repo->get('alpha'))->toBe(['name' => 'alpha', 'value' => 1, 'list' => [1, 2, 3]])
        ->and($repo->get('beta'))->toBe([['id' => 10], ['id' => 20]]);
});

it('reports presence of content files', function () {
    $repo = new ContentRepository(contentFixtureDir());

    expect($repo->has('alpha'))->toBeTrue()
        ->and($repo->has('nope'))->toBeFalse();
});

it('throws when a content file is missing', function () {
    (new ContentRepository(contentFixtureDir()))->get('missing');
})->throws(RuntimeException::class);

it('throws on malformed JSON', function () {
    (new ContentRepository(contentFixtureDir('content-bad')))->get('broken');
})->throws(RuntimeException::class);

it('produces a stable 16-char version hash', function () {
    $repo = new ContentRepository(contentFixtureDir());

    $version = $repo->version();
    expect($version)->toBeString()->toHaveLength(16)
        ->and($repo->version())->toBe($version); // memoized, stable
});

it('produces a different version for different content', function () {
    $good = (new ContentRepository(contentFixtureDir()))->version();
    $bad = (new ContentRepository(contentFixtureDir('content-bad')))->version();

    expect($good)->not->toBe($bad);
});
