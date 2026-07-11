<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\InvalidTokenException;
use App\Support\Auth\SupabaseTokenVerifier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Weryfikuje nagłówek `Authorization: Bearer <token>` (token GoTrue Supabase).
 * Po sukcesie wstawia SupabaseUser do atrybutów requestu (`supabase_user`)
 * i ustawia user-resolver. Każdy błąd → 401.
 *
 * Zasada: to jedyne zaufane źródło tożsamości. Kontrolery NIGDY nie ufają
 * user_id/gold/level z body — czytają stan z bazy.
 */
final class VerifySupabaseJwt
{
    public function __construct(private readonly SupabaseTokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $this->unauthorized('Brak nagłówka Authorization: Bearer <token>.');
        }

        try {
            $user = $this->verifier->verify($matches[1]);
        } catch (InvalidTokenException) {
            // Świadomie generyczny komunikat — nie zdradzamy, dlaczego token padł.
            return $this->unauthorized('Nieprawidłowy lub wygasły token.');
        }

        $request->attributes->set('supabase_user', $user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
