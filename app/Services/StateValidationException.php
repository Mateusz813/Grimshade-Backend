<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Autorytatywny zapis stanu odrzucony przez walidację (tryb STRICT anty-cheatu).
 * Kontroler/handler mapuje na HTTP 422 — odmowa przyjęcia niespójnego bloba.
 *
 * DOMYŚLNIE nieużywana: commit działa w trybie SOFT (loguje naruszenia, zapisuje
 * mimo to), żeby NIE odrzucać legalnego end-game gearu właściciela. Rzucana tylko
 * gdy config('supabase.state_commit_strict') === true.
 */
final class StateValidationException extends RuntimeException {}
