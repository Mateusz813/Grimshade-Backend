<?php

declare(strict_types=1);

namespace App\Domain\Support\Rng;

final class Mulberry32Rng implements RngInterface
{
    private const MASK = 0xFFFFFFFF;

    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & self::MASK;
    }

    public function nextUint32(): int
    {
        $this->state = ($this->state + 0x6D2B79F5) & self::MASK;
        $a = $this->state;

        $t = $this->imul($a ^ ($a >> 15), 1 | $a);

        $t = (($t + $this->imul($t ^ ($t >> 7), 61 | $t)) & self::MASK) ^ $t;

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
