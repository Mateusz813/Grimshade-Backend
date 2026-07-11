<?php

declare(strict_types=1);

namespace App\Support\Auth;

use RuntimeException;

/**
 * Rzucany, gdy token jest nieobecny, źle sformułowany, ma zły podpis,
 * wygasł lub nie spełnia oczekiwanych claims (iss/aud).
 *
 * VerifySupabaseJwt tłumaczy go na odpowiedź HTTP 401.
 */
final class InvalidTokenException extends RuntimeException {}
