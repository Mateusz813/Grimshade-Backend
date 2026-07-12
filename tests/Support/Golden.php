<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class Golden
{
    public static function load(string $relativePath): array
    {
        $path = dirname(__DIR__).'/Golden/fixtures/'.$relativePath;
        if (! is_file($path)) {
            throw new RuntimeException("Brak golden fixture: {$relativePath} ({$path}).");
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
