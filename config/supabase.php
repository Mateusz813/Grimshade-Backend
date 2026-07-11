<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Supabase project URL
    |--------------------------------------------------------------------------
    | Np. https://xxxx.supabase.co — używane do budowy expected `iss`
    | (${SUPABASE_URL}/auth/v1) oraz przyszłego JWKS endpointu.
    */
    'url' => env('SUPABASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | JWT weryfikacja (GoTrue)
    |--------------------------------------------------------------------------
    | driver:
    |   'hmac' — dzisiejsze legacy tokeny Supabase (HS256), podpisane sekretem
    |            projektu (Dashboard → Settings → API → JWT Secret).
    |   'jwks' — ścieżka migracji do asymetrycznych kluczy (RS256/ES256),
    |            weryfikacja publicznym kluczem z .well-known/jwks.json.
    |
    | Weryfikator jest za interfejsem SupabaseTokenVerifier, więc przełączenie
    | to zmiana configu, nie przepisywanie kodu.
    */
    'jwt' => [
        'driver' => env('SUPABASE_JWT_DRIVER', 'hmac'),

        // HS256 shared secret (tryb 'hmac').
        'secret' => env('SUPABASE_JWT_SECRET', ''),

        // Endpoint JWKS (tryb 'jwks'); domyślnie z SUPABASE_URL.
        'jwks_url' => env(
            'SUPABASE_JWKS_URL',
            env('SUPABASE_URL', '') !== ''
                ? rtrim((string) env('SUPABASE_URL'), '/').'/auth/v1/.well-known/jwks.json'
                : ''
        ),

        // Oczekiwane claims. iss domyślnie ${SUPABASE_URL}/auth/v1.
        'issuer' => env(
            'SUPABASE_JWT_ISS',
            env('SUPABASE_URL', '') !== ''
                ? rtrim((string) env('SUPABASE_URL'), '/').'/auth/v1'
                : ''
        ),
        'audience' => env('SUPABASE_JWT_AUD', 'authenticated'),

        // Tolerancja zegara (sekundy) przy walidacji exp/nbf/iat.
        'leeway' => (int) env('SUPABASE_JWT_LEEWAY', 30),
    ],
];
