<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\GameSave;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dokleja `updated_at` (wersję stanu) do KAŻDEJ mutującej odpowiedzi endpointu per-postać.
 *
 * Klient utrzymuje `server_version` z odpowiedzi serwera; endpoint, który mutuje stan,
 * ale nie zwraca wersji, zostawia klienta z nieaktualną bazą → następny commit bloba
 * dostaje zbędny 409. Ten middleware gwarantuje echo wersji systemowo, zamiast liczyć
 * na to, że każdy kontroler pamięta o polu.
 */
final class AppendStateVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (strtoupper($request->getMethod()) === 'GET') {
            return $response;
        }

        if (! $response instanceof JsonResponse || $response->getStatusCode() >= 300) {
            return $response;
        }

        $character = $request->attributes->get('character') ?? $request->route('character');
        $characterId = is_object($character) ? ($character->id ?? null) : $character;
        if ($characterId === null) {
            return $response;
        }

        $data = $response->getData(true);
        if (! is_array($data) || array_key_exists('updated_at', $data)) {
            return $response;
        }

        $save = GameSave::query()->where('character_id', $characterId)->first();
        if ($save === null) {
            return $response;
        }

        $data['updated_at'] = optional($save->updated_at)->toIso8601String();
        $response->setData($data);

        return $response;
    }
}
