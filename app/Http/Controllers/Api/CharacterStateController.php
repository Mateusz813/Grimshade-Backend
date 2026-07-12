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

final class CharacterStateController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $character = $request->attributes->get('character');

        $save = GameSave::query()->where('character_id', $character->id)->first();

        return response()->json([
            'character' => (new CharacterResource($character))->resolve(),
            'state' => $save?->state ?? ['_ownerCharacterId' => $character->id],
            'updated_at' => optional($save?->updated_at)->toIso8601String(),
        ]);
    }

    public function commit(Request $request, CharacterStateService $state, EffectiveStats $effective): JsonResponse
    {
        $character = $request->attributes->get('character');

        $validated = $request->validate([
            'requestId' => ['required', 'string'],
            'state' => ['required', 'array'],
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

    public function updatePrefs(Request $request, CharacterStateService $state): JsonResponse
    {
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
