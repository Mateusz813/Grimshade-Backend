<?php

declare(strict_types=1);

namespace App\Support\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Minimalny zegar PSR-20 (UTC) dla walidacji czasu w lcobucci/jwt.
 *
 * W testach można podmienić na zegar zamrożony, żeby deterministycznie
 * testować tokeny wygasłe / jeszcze-nieważne (patrz testy weryfikatora).
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
