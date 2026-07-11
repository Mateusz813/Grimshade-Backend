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

    /*
    |--------------------------------------------------------------------------
    | Autorytatywny commit stanu (PUT /characters/{id}/state)
    |--------------------------------------------------------------------------
    | Klient liczy walkę własnym silnikiem i pushuje pełny blob stanu; serwer
    | jest autorytatywnym PERSISTEREM. Walidacja/anty-cheat:
    |   false (DOMYŚLNIE, SOFT) — sanityzuj + waliduj, ale ZAWSZE zapisz;
    |          naruszenia tylko logowane (Log::warning). Priorytet: nie
    |          odrzucać legalnego end-game gearu właściciela (mythic/heroic,
    |          setki mln golda).
    |   true  (STRICT) — naruszenie niezmienników => HTTP 422, brak zapisu.
    |          Włączać dopiero, gdy bounds są dopracowane (późniejszy toggle).
    */
    'state_commit_strict' => (bool) env('SUPABASE_STATE_COMMIT_STRICT', false),

    /*
    |--------------------------------------------------------------------------
    | Walidacja zdarzeń combatu (PUT /characters/{id}/state z polem `event`)
    |--------------------------------------------------------------------------
    | Gdy commit niesie semantyczny `event`, serwer DIFFUJE poprzedni blob vs
    | przysłany (EventValidation) i egzekwuje reguły przejścia:
    |   HARD (ZAWSZE 422, niezależnie od tego flagu): duplikat uuid itemu
    |        (rdzeń dupe/dupingu) oraz inventory.gold nie-skończone/ujemne.
    |   SOFT (nowe itemy, dzienne limity, spójność śmierci, skok poziomu):
    |     false (DOMYŚLNIE) — tylko Log::warning, zapis mimo to (nie odrzucamy
    |            legalnego end-game save'u właściciela, dopóki bounds są niepewne).
    |     true  (STRICT) — naruszenia SOFT też => HTTP 422, brak zapisu.
    |            HARD są egzekwowane ZAWSZE, niezależnie od tej wartości.
    */
    'event_validation_strict' => (bool) env('SUPABASE_EVENT_VALIDATION_STRICT', false),
];
