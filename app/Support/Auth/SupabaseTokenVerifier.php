<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Weryfikacja tokenów Supabase GoTrue.
 *
 * Implementacje: HmacSupabaseTokenVerifier (HS256, dzisiejsze legacy tokeny)
 * oraz — w przyszłości — JwksSupabaseTokenVerifier (RS256/ES256). Middleware
 * zależy tylko od tego interfejsu, więc migracja to zmiana bindingu w configu.
 */
interface SupabaseTokenVerifier
{
    /**
     * Zwraca zweryfikowanego użytkownika lub rzuca InvalidTokenException.
     *
     * @throws InvalidTokenException gdy token jest nieważny (podpis/exp/iss/aud/struktura)
     */
    public function verify(string $jwt): SupabaseUser;
}
