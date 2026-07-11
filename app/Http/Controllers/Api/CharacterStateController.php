<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Models\GameSave;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stan gry postaci (blob game_saves) — odczyt do hydracji frontu + zapis
 * WYŁĄCZNIE preferencji klienta (settings). Wszystkie inne wycinki bloba
 * mutują tylko intent-endpointy (sell/upgrade/buy/combat...).
 */
final class CharacterStateController extends Controller
{
    /** GET /characters/{character}/state — kształt = blob (front: applyBlobToStores). */
    public function show(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $save = GameSave::query()->where('character_id', $character->id)->first();

        return response()->json([
            'character' => (new CharacterResource($character))->resolve(),
            'state' => $save?->state ?? ['_ownerCharacterId' => $character->id],
            'updated_at' => optional($save?->updated_at)->toIso8601String(),
        ]);
    }

    /** PUT /characters/{character}/prefs — nadpisuje TYLKO wycinek settings. */
    public function updatePrefs(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        DB::transaction(function () use ($state, $character, $validated): void {
            $save = $state->lockedFor($character);
            $state->writeClientPrefs($save, $validated['settings']);
            $state->persist($save);
        });

        return response()->json(['ok' => true]);
    }
}
