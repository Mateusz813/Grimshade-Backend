<?php

declare(strict_types=1);

namespace App\Domain\Support\Rng;

use Random\RandomException;

/**
 * Produkcyjne RNG — nieprzewidywalne dla klienta (CSPRNG przez random_int).
 * Używane w realnej rozgrywce: gracz nie może przewidzieć/wymusić dropów.
 * Precyzja floatów 32-bit, spójna z Mulberry32Rng (uint32 / 2^32).
 */
final class SecureRng implements RngInterface
{
    private const UINT32 = 4294967296; // 2^32

    public function nextFloat(): float
    {
        return $this->uint32() / self::UINT32;
    }

    public function nextInt(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return random_int($min, $max);
    }

    public function shuffle(array $items): array
    {
        $result = array_values($items);
        for ($i = count($result) - 1; $i > 0; $i--) {
            $j = $this->nextInt(0, $i);
            [$result[$i], $result[$j]] = [$result[$j], $result[$i]];
        }

        return $result;
    }

    private function uint32(): int
    {
        try {
            return random_int(0, self::UINT32 - 1);
        } catch (RandomException $e) {
            // Brak źródła entropii to błąd środowiska, nie ścieżka gry.
            throw new \RuntimeException('Brak bezpiecznego źródła losowości: '.$e->getMessage(), 0, $e);
        }
    }
}
