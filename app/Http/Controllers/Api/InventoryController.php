<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zarządzanie inwentarzem: equip/unequip + skrytka (deposit). Wszystko mutuje
 * blob game_saves przez CharacterStateService (serwer = właściciel).
 *
 * Walidacja v1: slot musi być prawidłowy + itemLevel <= poziom postaci.
 * TODO: pełny canEquip (zgodność klasy/broni) — po porcie itemSystem.canEquip.
 */
final class InventoryController extends Controller
{
    /** @var list<string> */
    private const SLOTS = [
        'helmet', 'armor', 'pants', 'gloves', 'shoulders', 'boots',
        'mainHand', 'offHand', 'ring1', 'ring2', 'earrings', 'necklace',
    ];

    public function equip(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemUuid' => ['required', 'string', 'max:128'],
            'slot' => ['required', 'string', 'in:'.implode(',', self::SLOTS)],
        ]);

        $inventory = DB::transaction(function () use ($state, $character, $data): array {
            $save = $state->lockedFor($character);
            $item = $state->findBagItem($save, $data['itemUuid']);
            if ($item === null) {
                abort(Response::HTTP_NOT_FOUND, 'Item nie istnieje w torbie.');
            }
            if ((int) ($item['itemLevel'] ?? 1) > (int) $character->level) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za niski poziom na ten przedmiot.');
            }

            $state->equipFromBag($save, $data['itemUuid'], $data['slot']);
            $state->persist($save);

            return $save->state['inventory'];
        });

        return response()->json(['inventory' => $inventory]);
    }

    public function unequip(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'slot' => ['required', 'string', 'in:'.implode(',', self::SLOTS)],
        ]);

        $inventory = DB::transaction(function () use ($state, $character, $data): array {
            $save = $state->lockedFor($character);
            $state->unequipToBag($save, $data['slot']);
            $state->persist($save);

            return $save->state['inventory'];
        });

        return response()->json(['inventory' => $inventory]);
    }

    public function moveToDeposit(Request $request, CharacterStateService $state): JsonResponse
    {
        return $this->move($request, $state, deposit: true);
    }

    public function moveToBag(Request $request, CharacterStateService $state): JsonResponse
    {
        return $this->move($request, $state, deposit: false);
    }

    private function move(Request $request, CharacterStateService $state, bool $deposit): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate(['itemUuid' => ['required', 'string', 'max:128']]);

        $inventory = DB::transaction(function () use ($state, $character, $data, $deposit): array {
            $save = $state->lockedFor($character);
            $deposit
                ? $state->moveBagToDeposit($save, $data['itemUuid'])
                : $state->moveDepositToBag($save, $data['itemUuid']);
            $state->persist($save);

            return $save->state['inventory'];
        });

        return response()->json(['inventory' => $inventory]);
    }
}
