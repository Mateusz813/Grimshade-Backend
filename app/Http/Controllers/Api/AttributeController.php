<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Character\AttributeSystem;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AttributeController extends Controller
{
    /**
     * Przydziela punkty atrybutów PO STRONIE SERWERA, w transakcji, per pojedyncza akcja.
     *
     * Wcześniej alokacja żyła wyłącznie w blobie klienta i docierała na serwer dopiero
     * pełnym commitem stanu — kto rozdał punkty i zamknął apkę, tracił je. Ten endpoint
     * jest autorytatywny: budżet liczy z kolumny `characters.stat_points`, cap DEF
     * egzekwuje z `AttributeSystem`, a klient dostaje z powrotem gotowy stan.
     */
    public function allocate(Request $request, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:200'],
            'stat' => ['required', 'string', Rule::in(['attack', 'hp', 'defense'])],
            'points' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $cacheKey = "attributes.allocate.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($character, $state, $data): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = is_array($save->state) ? $save->state : [];
            $allocation = is_array($blob['attributes'] ?? null) ? $blob['attributes'] : [];

            $budget = max(0, (int) $fresh->stat_points);
            $requested = min((int) $data['points'], $budget);

            $key = match ($data['stat']) {
                'attack' => 'attackPoints',
                'hp' => 'hpPoints',
                'defense' => 'defensePoints',
            };

            $current = max(0, (int) ($allocation[$key] ?? 0));
            $applied = $requested;

            if ($data['stat'] === 'defense') {
                $cap = AttributeSystem::getMaxDefensePoints((string) $fresh->class);
                $applied = min($applied, max(0, $cap - $current));
            }

            if ($applied > 0) {
                $allocation[$key] = $current + $applied;
                $allocation['migrationVersion'] = max(1, (int) ($allocation['migrationVersion'] ?? 0));
                $allocation['_entryOwner'] = (string) $fresh->id;
                $blob['attributes'] = $allocation;

                $fresh->stat_points = $budget - $applied;

                $save->state = $blob;
                $state->persist($save);
                $fresh->save();
            }

            return [
                'applied' => $applied,
                'attributes' => $blob['attributes'] ?? $allocation,
                'character' => (new CharacterResource($fresh))->resolve(),
                'state' => is_array($save->state) ? $save->state : $blob,
                'updated_at' => optional($save->updated_at)->toIso8601String(),
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }
}
