<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Character\EffectiveStats;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Models\GameSave;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    /**
     * PUT /characters/{character}/state — autorytatywny commit PEŁNEGO stanu.
     *
     * Klient liczy walkę własnym silnikiem i pushuje cały blob (`_characterStats`
     * + `inventory` z prawdziwym goldem + skills/quests/...). Serwer jest jedynym
     * pisarzem: sanityzuje (gold >=0, pola numeryczne), waliduje niezmienniki
     * (SOFT — loguje i zapisuje mimo to; STRICT — 422 przez config), zapisuje blob
     * + kolumny characters, i zwraca ten sam kształt co GET /state (hydracja frontu).
     *
     * Idempotencja po requestId (Cache 1h) — replay zwraca cache.
     */
    public function commit(Request $request, CharacterStateService $state, EffectiveStats $effective): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $validated = $request->validate([
            'requestId' => ['required', 'string'],
            'state' => ['required', 'array'],
            // Opcjonalny semantyczny opis zdarzenia (walka klienta). Wszystkie pola
            // opcjonalne; obecność `event` włącza bramkę EventValidation (diff prev↔next).
            'event' => ['sometimes', 'array'],
            'event.type' => ['sometimes', 'nullable', 'string', Rule::in([
                'dungeon', 'boss', 'raid', 'transform', 'hunt', 'offline-hunt', 'arena',
            ])],
            'event.sourceId' => ['sometimes', 'nullable', 'string', 'max:128'],
            'event.outcome' => ['sometimes', 'nullable', 'string', Rule::in(['won', 'lost', 'fled', 'settled'])],
            'event.died' => ['sometimes', 'boolean'],
            'event.protectionConsumed' => ['sometimes', 'nullable', 'string', Rule::in([
                'death_protection', 'amulet_of_loss',
            ])],
            'event.wavesCompleted' => ['sometimes', 'nullable', 'integer'],
        ]);

        $requestId = (string) $validated['requestId'];
        $cacheKey = "state.commit.{$character->id}.{$requestId}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $strict = (bool) config('supabase.state_commit_strict', false);
        $eventStrict = (bool) config('supabase.event_validation_strict', false);
        $event = isset($validated['event']) && is_array($validated['event']) ? $validated['event'] : null;

        $payload = DB::transaction(function () use ($character, $state, $effective, $strict, $eventStrict, $event, $validated): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $sanitized = $state->commit($fresh, $save, $validated['state'], $effective, $strict, $event, $eventStrict);

            return [
                'character' => (new CharacterResource($fresh))->resolve(),
                'state' => $sanitized,
                'updated_at' => optional($save->updated_at)->toIso8601String(),
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
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
