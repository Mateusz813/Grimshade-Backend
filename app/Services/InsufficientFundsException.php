<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Gracz nie ma wystarczających środków (gold/kamienie/consumables) na akcję.
 * Kontrolery mapują na HTTP 422 — to normalna odmowa gry, nie błąd serwera.
 */
final class InsufficientFundsException extends RuntimeException {}
