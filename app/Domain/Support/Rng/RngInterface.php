<?php

declare(strict_types=1);

namespace App\Domain\Support\Rng;

interface RngInterface
{
    public function nextFloat(): float;

    public function nextInt(int $min, int $max): int;

    public function shuffle(array $items): array;
}
