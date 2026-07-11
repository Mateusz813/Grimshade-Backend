<?php

declare(strict_types=1);

namespace App\Domain\Support\Rng;

/**
 * Źródło losowości dla logiki gry. CAŁY kod w app/Domain bierze RNG przez ten
 * interfejs — żadnego mt_rand/random_int bezpośrednio — żeby:
 *  - produkcja losowała nieprzewidywalnie (SecureRng), a
 *  - testy/golden-vectory były deterministyczne (Mulberry32Rng z seedem).
 *
 * Uwaga o parytecie: `nextInt`/`shuffle` mają ustaloną konwencję (patrz impl.).
 * Portując konsumenta z TS, dopasuj KOLEJNOŚĆ i sposób wołania do oryginału.
 */
interface RngInterface
{
    /** Liczba zmiennoprzecinkowa w [0, 1). */
    public function nextFloat(): float;

    /** Liczba całkowita w [min, max] włącznie. */
    public function nextInt(int $min, int $max): int;

    /**
     * Zwraca NOWĄ przetasowaną tablicę (Fisher-Yates), bez mutacji wejścia.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array;
}
