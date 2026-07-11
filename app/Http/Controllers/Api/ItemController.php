<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Content\ContentRepository;
use App\Domain\Items\ItemEconomy;
use App\Domain\Items\StoneSystem;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Support\Rng\RngInterface;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intent-endpointy itemów. Ceny/koszty/wynik liczy SERWER (ItemEconomy +
 * serwerowy RNG). Klient podaje tylko uuid itemu + requestId.
 *
 * Semantyka 1:1 z frontem (Inventory.tsx):
 *  - sell: gold = getSellPrice (baza + zwrot golda z ulepszeń) ORAZ zwrot
 *    kamieni z getEnhancementRefund; item znika z bag.
 *  - upgrade: koszt (gold+kamienie) schodzi ZAWSZE; sukces = roll < successRate;
 *    sukces → upgradeLevel+1 + licznik item_upgrades_done++.
 */
final class ItemController extends Controller
{
    public function sell(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemUuid' => ['required', 'string', 'max:128'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "items.sell.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data): array {
            $save = $state->lockedFor($character);
            $item = $state->findBagItem($save, $data['itemUuid']);
            if ($item === null) {
                abort(Response::HTTP_NOT_FOUND, 'Item nie istnieje w torbie.');
            }

            // Generated itemy nie mają wpisu bazowego (basePrice) → ścieżka rarity+level.
            $price = ItemEconomy::getSellPrice($item, null);
            $refund = ItemEconomy::getEnhancementRefund($item['upgradeLevel'] ?? 0, $item['rarity']);

            $state->removeBagItem($save, $data['itemUuid']);
            $state->addGold($save, $price);
            if ($refund['stones'] > 0 && $refund['stoneType'] !== '') {
                $state->addStones($save, $refund['stoneType'], $refund['stones']);
            }
            $state->persist($save);

            return [
                'goldGained' => $price,
                'stonesRefunded' => $refund['stones'],
                'stoneType' => $refund['stoneType'],
                'gold' => $state->gold($save),
                'inventory' => $save->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    public function upgrade(Request $request, CharacterStateService $state, RngInterface $rng): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemUuid' => ['required', 'string', 'max:128'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "items.upgrade.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data, $rng): array {
            $save = $state->lockedFor($character);
            $item = $state->findBagItem($save, $data['itemUuid']);
            if ($item === null) {
                abort(Response::HTTP_NOT_FOUND, 'Item nie istnieje w torbie.');
            }

            $currentLevel = (int) ($item['upgradeLevel'] ?? 0);
            $cost = ItemEconomy::getEnhancementCost($currentLevel + 1, $item['rarity']);

            // Jak na froncie: koszty schodzą ZAWSZE (sukces czy porażka).
            $state->spendGold($save, (int) $cost['gold']);
            $state->useStones($save, $cost['stoneType'], (int) $cost['stones']);

            $success = $rng->nextFloat() * 100 < $cost['successRate'];
            if ($success) {
                $item['upgradeLevel'] = $currentLevel + 1;
                $state->updateBagItem($save, $data['itemUuid'], $item);

                // Licznik rankingowy — jak front bumpStat('item_upgrades_done').
                $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
                $fresh->item_upgrades_done = (int) $fresh->item_upgrades_done + 1;
                $fresh->save();
            }
            $state->persist($save);

            return [
                'success' => $success,
                'item' => $item,
                'cost' => $cost,
                'gold' => $state->gold($save),
                'stones' => $save->state['inventory']['stones'] ?? [],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * Rozłóż item na czynniki. Parytet: Inventory.tsx handleDisassemble —
     * item ZAWSZE znika z torby; roll < 0.20 daje dokładnie 1 kamień typu
     * getRequiredStoneType(rarity), w przeciwnym razie brak kamienia.
     */
    public function disassemble(Request $request, CharacterStateService $state, RngInterface $rng): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemUuid' => ['required', 'string', 'max:128'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "items.disassemble.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data, $rng): array {
            $save = $state->lockedFor($character);
            $item = $state->findBagItem($save, $data['itemUuid']);
            if ($item === null) {
                abort(Response::HTTP_NOT_FOUND, 'Item nie istnieje w torbie.');
            }

            $stoneType = ItemEconomy::getRequiredStoneType($item['rarity']);
            $gotStone = $rng->nextFloat() < 0.20;

            // Item konsumowany ZAWSZE (sukces i porażka rolla) — jak front.
            $state->removeBagItem($save, $data['itemUuid']);
            if ($gotStone) {
                $state->addStones($save, $stoneType, 1);
            }
            $state->persist($save);

            return [
                'success' => true,
                'stoneGained' => $gotStone,
                'stoneType' => $stoneType,
                'inventory' => $save->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * Masowy rozkład. Parytet: inventoryStore.ts disassembleMultiple — per item
     * roll >= 0.20 pomija (brak kamienia), inaczej +1 kamień STONE_FOR_RARITY.
     * Wszystkie wskazane itemy konsumowane; kamienie agregowane po typie.
     * Kolejność rolli = kolejność w torbie (jak bag.filter w TS).
     */
    public function disassembleMass(Request $request, CharacterStateService $state, RngInterface $rng): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemUuids' => ['required', 'array'],
            'itemUuids.*' => ['required', 'string', 'max:128'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "items.disassembleMass.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data, $rng): array {
            $save = $state->lockedFor($character);
            $uuidSet = array_flip($data['itemUuids']);

            $stonesGained = [];
            $toRemove = [];
            foreach (($save->state['inventory']['bag'] ?? []) as $item) {
                if (! isset($uuidSet[$item['uuid'] ?? null])) {
                    continue;
                }
                $toRemove[] = $item['uuid'];
                if ($rng->nextFloat() >= 0.20) {
                    continue; // brak kamienia
                }
                $stoneType = ItemEconomy::getRequiredStoneType($item['rarity']);
                $stonesGained[$stoneType] = ($stonesGained[$stoneType] ?? 0) + 1;
            }

            foreach ($toRemove as $uuid) {
                $state->removeBagItem($save, $uuid);
            }
            foreach ($stonesGained as $stoneType => $count) {
                $state->addStones($save, $stoneType, $count);
            }
            $state->persist($save);

            return [
                'stonesGained' => (object) $stonesGained,
                'disassembled' => count($toRemove),
                'inventory' => $save->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * Przelosowanie bonusów itemu. Parytet: Inventory.tsx (REROLL_STONE_COST=2)
     * + itemGenerator.ts rerollItemBonuses. Koszt = 2 kamienie STONE_FOR_RARITY.
     * Warunki: rarity !== common, RARITY_BONUS_SLOTS[rarity] > 0, posiadane
     * kamienie >= 2 (inaczej 422). Zachowuje bazowe staty slotu, resztę
     * losuje od nowa.
     */
    public function reroll(Request $request, CharacterStateService $state, ContentRepository $content, RngInterface $rng): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemUuid' => ['required', 'string', 'max:128'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "items.reroll.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data, $content, $rng): array {
            $save = $state->lockedFor($character);
            $item = $state->findBagItem($save, $data['itemUuid']);
            if ($item === null) {
                abort(Response::HTTP_NOT_FOUND, 'Item nie istnieje w torbie.');
            }

            $rarity = (string) $item['rarity'];
            $rerollCost = 2;
            $stoneType = ItemEconomy::getRequiredStoneType($rarity);
            $owned = (int) ($save->state['inventory']['stones'][$stoneType] ?? 0);

            if ($rarity === 'common'
                || (ItemEconomy::RARITY_BONUS_SLOTS[$rarity] ?? 0) <= 0
                || $owned < $rerollCost) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Item nie kwalifikuje się do rerollu lub brak kamieni.');
            }

            $generator = new ItemGenerator($content->get('itemTemplates'), $rng);
            $info = $generator->getItemDisplayInfo($item['itemId']);
            $slot = $info['slot'] ?? null;
            if ($slot === null) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nie można ustalić slotu itemu.');
            }

            $state->useStones($save, $stoneType, $rerollCost);
            $item['bonuses'] = $generator->rerollItemBonuses($item, $slot);
            $state->updateBagItem($save, $data['itemUuid'], $item);
            $state->persist($save);

            return [
                'item' => $item,
                'stonesUsed' => $rerollCost,
                'stoneType' => $stoneType,
                'inventory' => $save->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * Konwersja kamieni: 100 niższych + 1000 golda → 1 wyższy tier. Parytet:
     * itemSystem.ts STONE_CONVERSION_CHAIN + inventoryStore.ts convertStones.
     */
    public function convertStones(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'stoneType' => ['required', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "items.convertStones.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character, $data): array {
            $save = $state->lockedFor($character);

            $stoneType = $data['stoneType'];
            $higher = StoneSystem::higherTier($stoneType);
            if ($higher === null) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Ten kamień nie ma wyższego tieru.');
            }

            $owned = (int) ($save->state['inventory']['stones'][$stoneType] ?? 0);
            if ($owned < StoneSystem::STONE_CONVERSION_COST) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za mało kamieni do konwersji.');
            }
            if ($state->gold($save) < StoneSystem::STONE_CONVERSION_GOLD) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za mało golda do konwersji.');
            }

            $state->spendGold($save, StoneSystem::STONE_CONVERSION_GOLD);
            $state->useStones($save, $stoneType, StoneSystem::STONE_CONVERSION_COST);
            $state->addStones($save, $higher, 1);
            $state->persist($save);

            return [
                'stoneType' => $stoneType,
                'higherStoneType' => $higher,
                'gold' => $state->gold($save),
                'inventory' => $save->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }
}
