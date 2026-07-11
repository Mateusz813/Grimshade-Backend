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

/**
 * Intent-endpointy consumables. SERWER liczy receptury/koszty/wynik (PotionSystem
 * + StatReset). Klient podaje tylko id + requestId — nic z body nie jest ufane.
 *
 * Semantyka 1:1 z frontem (Inventory.tsx):
 *  - convert: alchemia (POTION_CONVERSIONS) — FREE, useConsumable(input) +
 *    addConsumable(output); gating po poziomie i posiadanym stacku.
 *  - use: wypicie 1 sztuki (applyElixirDose) — AUTORYTATYWNE jest wyłącznie
 *    zdjęcie 1 ze stacku; buffy/heale to efemeryczny stan klienta (NIE serwer).
 *  - stat-reset: handleStatReset — reset statystyk postaci do bazy klasy +
 *    przeliczenie puli stat_points z highest_level; konsumuje eliksir stat_reset.
 */
final class ConsumableController extends Controller
{
    /** Id eliksiru resetu statystyk (ELIXIRS w shopStore.ts). */
    private const STAT_RESET_ELIXIR_ID = 'stat_reset';

    /**
     * POST /characters/{character}/potions/convert
     * body {inputId, batches, outputId?, requestId}
     */
    public function convert(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'inputId' => ['required', 'string', 'max:64'],
            'batches' => ['required', 'integer', 'min:1'],
            // Rozstrzyga wieloznaczność inputId (np. hp_potion_lg → great LUB mega).
            // Front zawsze przekazuje outputId (handlePotionConvert); opcjonalny.
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

    /**
     * POST /characters/{character}/consumables/use
     * body {consumableId, requestId}
     *
     * AUTORYTATYWNE jest tylko zdjęcie 1 ze stacku. Buffy/heale są efemeryczne
     * po stronie klienta (z założenia NIE serwerowe) — timerów nie utrwalamy.
     */
    public function use(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'consumableId' => ['required', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        // Reset statystyk ma dedykowany endpoint (front woła statReset) — tu 422.
        if ($data['consumableId'] === self::STAT_RESET_ELIXIR_ID) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Uzyj /character/stat-reset dla resetu statystyk.');
        }

        $cacheKey = "consumables.use.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data): array {
            $save = $state->lockedFor($character);

            // Rzuca InsufficientFundsException (→ 422), gdy stack < 1.
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

    /**
     * POST /characters/{character}/character/stat-reset
     * body {consumableId?, requestId}
     */
    public function statReset(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
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

            // Postać jest osobną tabelą — lock oddzielnie i mutuj po wyliczeniu.
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

            // Eliksir stat_reset (jeśli podany) schodzi ze stacku — jak front.
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
