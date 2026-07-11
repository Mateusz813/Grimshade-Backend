<?php

declare(strict_types=1);

namespace App\Domain\Content;

use JsonException;
use RuntimeException;

/**
 * Dostęp do treści gry (monsters/items/skills/... z src/data/*.json, skopiowane
 * do resources/game-content/). Wspólne ŹRÓDŁO PRAWDY balansu z frontem.
 *
 * Czysty (bez zależności od frameworka) — memoizacja w pamięci procesu, żeby
 * łatwo testować i trzymać się reguły „Domain nie zna Laravela".
 */
final class ContentRepository
{
    /** @var array<string, array<mixed>> */
    private array $memo = [];

    private ?string $version = null;

    public function __construct(private readonly string $basePath) {}

    /**
     * Zwraca zdekodowaną zawartość pliku treści (np. 'monsters', 'skills').
     *
     * @return array<mixed>
     */
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
            /** @var array<mixed> $decoded */
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

    /**
     * Deterministyczny hash CAŁEJ treści. Front porównuje swój hash z tym na
     * starcie — mismatch = klient i serwer nie zgadzają się co do balansu.
     */
    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $files = glob($this->basePath.'/*.json') ?: [];
        sort($files); // stabilna kolejność niezależna od systemu plików

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
