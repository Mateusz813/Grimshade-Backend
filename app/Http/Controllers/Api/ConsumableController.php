<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Character\StatReset;
use App\Domain\Items\PotionSystem;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ConsumableController extends Controller
{
    private const STAT_RESET_ELIXIR_ID = 'stat_reset';

    public function convert(Request $request, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'inputId' => ['required', 'string', 'max:64'],
            'batches' => ['required', 'integer', 'min:1'],
            'outputId' => ['sometimes', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "consumables.convert.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data): array {
            $save = $state->lockedFor($character);

            $conv = null;
            foreach (PotionSystem::potionConversions() as $candidate) {
                if ($candidate['inputId'] !== $data['inputId']) {
                    continue;
                }
                if (isset($data['outputId']) && $candidate['outputId'] !== $data['outputId']) {
                    continue;
                }
                $conv = $candidate;
                break;
            }
            if ($conv === null) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Brak takiej konwersji.');
            }

            $owned = (int) ($save->state['inventory']['consumables'][$conv['inputId']] ?? 0);
            $avail = PotionSystem::checkConversionAvailability($conv, $owned, (int) $character->level);

            if ($avail['levelLocked']) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Wymagany poziom {$avail['requiredLevel']}.");
            }
            if ($data['batches'] > $avail['maxBatches']) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za mało składników na konwersję.');
            }

            $consumed = $conv['inputCount'] * $data['batches'];
            $produced = $data['batches'];

            $state->useConsumable($save, $conv['inputId'], $consumed);
            $state->addConsumable($save, $conv['outputId'], $produced);
            $state->persist($save);

            return [
                'inputId' => $conv['inputId'],
                'outputId' => $conv['outputId'],
                'produced' => $produced,
                'consumed' => $consumed,
                'consumables' => $save->state['inventory']['consumables'] ?? [],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    public function use(Request $request, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'consumableId' => ['required', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        if ($data['consumableId'] === self::STAT_RESET_ELIXIR_ID) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Uzyj /character/stat-reset dla resetu statystyk.');
        }

        $cacheKey = "consumables.use.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data): array {
            $save = $state->lockedFor($character);

            $state->useConsumable($save, $data['consumableId'], 1);
            $state->persist($save);

            return [
                'consumableId' => $data['consumableId'],
                'consumables' => $save->state['inventory']['consumables'] ?? [],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    public function statReset(Request $request, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'consumableId' => ['sometimes', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "consumables.statReset.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data): array {
            $save = $state->lockedFor($character);

            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);

            $reset = StatReset::compute(
                (string) $fresh->class,
                (int) $fresh->hp,
                (int) $fresh->mp,
                (int) $fresh->highest_level,
            );
            if ($reset === null) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nieznana klasa postaci.');
            }

            if (isset($data['consumableId'])) {
                $state->useConsumable($save, $data['consumableId'], 1);
                $state->persist($save);
            }

            $fresh->attack = $reset['attack'];
            $fresh->defense = $reset['defense'];
            $fresh->max_hp = $reset['max_hp'];
            $fresh->max_mp = $reset['max_mp'];
            $fresh->hp = $reset['hp'];
            $fresh->mp = $reset['mp'];
            $fresh->stat_points = $reset['stat_points'];
            $fresh->save();

            return [
                'character' => [
                    'attack' => $fresh->attack,
                    'defense' => $fresh->defense,
                    'max_hp' => $fresh->max_hp,
                    'max_mp' => $fresh->max_mp,
                    'hp' => $fresh->hp,
                    'mp' => $fresh->mp,
                    'stat_points' => $fresh->stat_points,
                ],
                'consumables' => $save->state['inventory']['consumables'] ?? [],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }
}
