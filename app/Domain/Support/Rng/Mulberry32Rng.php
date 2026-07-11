<?php

declare(strict_types=1);

namespace App\Domain\Support\Rng;

/**
 * Deterministyczny PRNG mulberry32 — PORT 1:1 kanonicznego JS. Ten sam seed
 * daje tę samą sekwencję w PHP i w JS, więc golden-vectory wygenerowane w TS
 * odtwarzają się bajt-w-bajt w PHP (fundament parytetu RNG).
 *
 * Semantyka 32-bitowa JS odtworzona ręcznie: Math.imul (mnożenie mod 2^32),
 * uint32 (& 0xFFFFFFFF), logiczne przesunięcie w prawo (>>> na nieujemnych).
 * Referencja JS:
 *   a |= 0; a = a + 0x6D2B79F5 | 0;
 *   let t = Math.imul(a ^ a >>> 15, 1 | a);
 *   t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
 *   return ((t ^ t >>> 14) >>> 0) / 4294967296;
 */
final class Mulberry32Rng implements RngInterface
{
    private const MASK = 0xFFFFFFFF;

    /** Stan jako uint32 (0 .. 2^32-1). */
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & self::MASK;
    }

    /** Surowy uint32 z sekwencji — używany też przez golden-vectory. */
    public function nextUint32(): int
    {
        // a = a + 0x6D2B79F5 | 0
        $this->state = ($this->state + 0x6D2B79F5) & self::MASK;
        $a = $this->state;

        // t = imul(a ^ (a >>> 15), 1 | a)
        $t = $this->imul($a ^ ($a >> 15), 1 | $a);

        // t = (t + imul(t ^ (t >>> 7), 61 | t)) ^ t
        $t = (($t + $this->imul($t ^ ($t >> 7), 61 | $t)) & self::MASK) ^ $t;

        // (t ^ (t >>> 14)) >>> 0
        return ($t ^ ($t >> 14)) & self::MASK;
    }

    public function nextFloat(): float
    {
        return $this->nextUint32() / 4294967296.0;
    }

    public function nextInt(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (int) floor($this->nextFloat() * ($max - $min + 1));
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

    /**
     * Math.imul: dolne 32 bity iloczynu dwóch uint32. Liczone przez rozbicie na
     * połówki 16-bitowe, żeby nie przekroczyć 64-bit int PHP (produkt 2^32 × 2^32
     * by się przelał). Sign-agnostic — modularnie dolne bity są identyczne.
     */
    private function imul(int $a, int $b): int
    {
        $a &= self::MASK;
        $b &= self::MASK;

        $al = $a & 0xFFFF;
        $ah = ($a >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;

        $low = $al * $bl;
        $mid = ($al * $bh + $ah * $bl) & self::MASK;

        return ($low + (($mid << 16) & self::MASK)) & self::MASK;
    }
}
