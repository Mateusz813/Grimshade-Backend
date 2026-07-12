<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Character;
use App\Support\Auth\SupabaseUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOwnsCharacter
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('supabase_user');
        if (! $user instanceof SupabaseUser) {
            return response()->json(['message' => 'Brak uwierzytelnienia.'], Response::HTTP_UNAUTHORIZED);
        }

        $routeCharacter = $request->route('character');
        $character = $routeCharacter instanceof Character
            ? $routeCharacter
            : Character::query()->find($routeCharacter);

        if ($character === null) {
            return response()->json(['message' => 'Postać nie istnieje.'], Response::HTTP_NOT_FOUND);
        }

        if ($character->user_id !== $user->id) {
            return response()->json(['message' => 'Brak dostępu do tej postaci.'], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('character', $character);

        return $next($request);
    }
}
