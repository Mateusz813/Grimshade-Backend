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
    | jest autorytatywnym PERSISTEREM.
    |
    | UWAGA: niezależnie od tego flagu, na KAŻDYM commicie (z polem `event` czy
    | bez) działa ALWAYS-RUN bramka HARD (CharacterStateService::guardInvariants),
    | która ZAWSZE => HTTP 422 + rollback: duplikat uuid itemu (rdzeń dupe/clone),
    | skok poziomu wzwyż > 50 w jednym commicie, oraz absurdalne sufity absolutne
    | (gold > 1e12, consumables/stones > 100k za stack, arenaPoints > 1e9,
    | skillLevels > 500). HARD ignoruje wszystkie flagi — to celowe.
    |
    | Ten flag gatuje WYŁĄCZNIE walidację SOFT (niepewne bounds — bounds statów
    | itemów, XP↔poziom, delta golda, magnituda bazowych statów vs recompute):
    |   false (DOMYŚLNIE, SOFT) — sanityzuj + waliduj, ale ZAWSZE zapisz;
    |          naruszenia SOFT tylko logowane (Log::warning). Priorytet: nie
    |          odrzucać legalnego end-game gearu właściciela (mythic/heroic,
    |          setki mln golda).
    |   true  (STRICT) — naruszenie SOFT też => HTTP 422, brak zapisu. Włączać
    |          dopiero, gdy bounds są dopracowane (późniejszy toggle).
    */
    'state_commit_strict' => (bool) env('SUPABASE_STATE_COMMIT_STRICT', false),

    /*
    |--------------------------------------------------------------------------
    | Walidacja zdarzeń combatu (PUT /characters/{id}/state z polem `event`)
    |--------------------------------------------------------------------------
    | Gdy commit niesie semantyczny `event`, serwer DODATKOWO DIFFUJE poprzedni
    | blob vs przysłany (EventValidation) dla naruszeń WYMAGAJĄCYCH kontekstu
    | zdarzenia. Wszystkie są SOFT (dzienne limity prób, spójność śmierci, spadek
    | poziomu bez śmierci):
    |     false (DOMYŚLNIE) — tylko Log::warning, zapis mimo to (nie odrzucamy
    |            legalnego end-game save'u właściciela, dopóki bounds są niepewne).
    |     true  (STRICT) — naruszenia event-SOFT też => HTTP 422, brak zapisu.
    |
    | UWAGA: twarde niezmienniki (dupe uuid, skok poziomu, absurdalne sufity) NIE
    | żyją już tutaj — egzekwuje je ALWAYS-RUN guardInvariants na KAŻDYM commicie
    | (patrz `state_commit_strict` wyżej), z eventem czy bez. Ten flag NIE ma na
    | nie wpływu.
    */
    'event_validation_strict' => (bool) env('SUPABASE_EVENT_VALIDATION_STRICT', false),
];
