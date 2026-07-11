<?php

declare(strict_types=1);

/**
 * CORS dla publicznego cutoveru: front (Vercel, inny origin) woła backend przez
 * przeglądarkę, więc backend MUSI wysłać nagłówki CORS, inaczej przeglądarka
 * zablokuje żądania.
 *
 * Uwierzytelnianie idzie przez `Authorization: Bearer <JWT>` (nie cookies), więc
 * `supports_credentials = false`, a origin można zawęzić do domeny Vercela.
 *
 * PROD: ustaw CORS_ALLOWED_ORIGINS w .env na dokładne originy frontu, np.
 *   CORS_ALLOWED_ORIGINS=https://grimshade.vercel.app,https://grimshade.pl
 * Pusty = '*' (wygodne lokalnie; na produkcji ZAWĘŹ do swoich domen).
 */
$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

$patterns = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS_PATTERNS', '')),
)));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Brak jawnych originów => '*' (dev). Na produkcji ustaw CORS_ALLOWED_ORIGINS.
    'allowed_origins' => $origins !== [] ? $origins : ['*'],

    // Np. '#^https://.*\.vercel\.app$#' dla preview-deployów Vercela.
    'allowed_origins_patterns' => $patterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    // Bearer JWT, nie cookies — brak credentials (i nie można łączyć z origin '*').
    'supports_credentials' => false,
];
