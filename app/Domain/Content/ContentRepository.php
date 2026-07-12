<?php

declare(strict_types=1);

namespace App\Domain\Content;

use JsonException;
use RuntimeException;

final class ContentRepository
{
    private array $memo = [];

    private ?string $version = null;

    public function __construct(private readonly string $basePath) {}

    public function get(string $name): array
    {
        if (isset($this->memo[$name])) {
            return $this->memo[$name];
        }

        $path = $this->pathFor($name);
        if (! is_file($path)) {
            throw new RuntimeException("Brak pliku treści: {$name} ({$path}).");
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Niepoprawny JSON treści: {$name} — {$e->getMessage()}", 0, $e);
        }

        return $this->memo[$name] = $decoded;
    }

    public function has(string $name): bool
    {
        return is_file($this->pathFor($name));
    }

    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $files = glob($this->basePath.'/*.json') ?: [];
        sort($files);

        $hashes = array_map(
            static fn (string $file): string => basename($file).':'.hash_file('sha256', $file),
            $files,
        );

        return $this->version = substr(hash('sha256', implode("\n", $hashes)), 0, 16);
    }

    private function pathFor(string $name): string
    {
        return $this->basePath.'/'.$name.'.json';
    }
}
