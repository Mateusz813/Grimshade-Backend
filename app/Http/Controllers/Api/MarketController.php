<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Market\MarketMath;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\GameSave;
use App\Models\MarketListing;
use App\Models\MarketSaleNotification;
use App\Services\CharacterStateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aukcje gracz→gracz. SERWER liczy cały przepływ ekonomii (gold/escrow/transfer)
 * — klient wysyła tylko intencje. Prawdziwy gold = blob game_saves inventory.gold
 * (przez CharacterStateService), nigdy characters.gold ani kwoty z body.
 *
 * NAJWAŻNIEJSZE (zamyka duping): kupno bierze lock FOR UPDATE na wierszu aukcji,
 * sprawdza stock/not-self/gold, dekrementuje-lub-usuwa, transferuje item, kredytuje
 * sprzedawcę (netto po 5% podatku) + notyfikacja — wszystko w JEDNEJ transakcji.
 *
 * Semantyka 1:1 z frontem (marketApi.ts / Market.tsx / buy_market_listing RPC):
 *  - escrow przy wystawieniu: item schodzi z bloba ATOMOWO z insertem aukcji,
 *  - buyer płaci price×qty (brutto), seller dostaje price×qty − tax (netto),
 *  - liczniki rankingowe: buyer market_items_bought/market_gold_spent, seller
 *    market_items_sold/market_gold_earned.
 */
final class MarketController extends Controller
{
    /** @var list<string> */
    private const KINDS = ['item', 'potion', 'elixir', 'stone', 'arena_points', 'spell_chest'];

    // ---- Przeglądanie -------------------------------------------------------

    /** GET /market/listings — aktywne aukcje z opcjonalnymi filtrami (DB-level). */
    public function index(Request $request): JsonResponse
    {
        $query = MarketListing::query()->where('quantity', '>', 0);

        if (($kind = $request->query('kind')) !== null && in_array($kind, self::KINDS, true)) {
            $query->where('kind', $kind);
        }
        if (($rarity = $request->query('rarity')) !== null && $rarity !== 'all') {
            $query->where('rarity', $rarity);
        }
        if (($slot = $request->query('slot')) !== null && $slot !== '') {
            $query->where('slot', $slot);
        }
        if (($min = $request->query('minLevel')) !== null && is_numeric($min)) {
            $query->where('item_level', '>=', (int) $min);
        }
        if (($max = $request->query('maxLevel')) !== null && is_numeric($max)) {
            $query->where('item_level', '<=', (int) $max);
        }
        if (($q = $request->query('q')) !== null && trim((string) $q) !== '') {
            $query->where('item_name', 'like', '%'.trim((string) $q).'%');
        }

        $this->applySort($query, (string) $request->query('sort', 'newest'));

        $limit = min(200, max(1, (int) $request->query('limit', '100')));

        return response()->json(
            $query->limit($limit)->get()->map(fn (MarketListing $l): array => $this->snapshot($l))->all()
        );
    }

    /** GET /characters/{character}/market/mine — aukcje danej postaci. */
    public function mine(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        return response()->json(
            MarketListing::query()
                ->where('seller_id', $character->id)
                ->orderByDesc('listed_at')
                ->get()
                ->map(fn (MarketListing $l): array => $this->snapshot($l))
                ->all()
        );
    }

    // ---- Wystawienie (escrow) ----------------------------------------------

    /**
     * POST /characters/{character}/market/listings — wystawia aukcję.
     * Escrow: dobro schodzi z bloba game_saves ATOMOWO z insertem (item po uuid
     * z bag; stacki z consumables/stones/arenaPoints). Idempotencja po requestId.
     */
    public function store(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', self::KINDS)],
            'price' => ['required', 'integer'],
            'quantity' => ['required', 'integer'],
            'requestId' => ['required', 'string', 'max:64'],
            'itemUuid' => ['required_if:kind,item', 'string', 'max:128'],
            'itemId' => ['required_unless:kind,item', 'string', 'max:128'],
            'itemName' => ['nullable', 'string', 'max:190'],
            'rarity' => ['nullable', 'string', 'max:32'],
            'slot' => ['nullable', 'string', 'max:32'],
            'itemLevel' => ['nullable', 'integer'],
        ]);

        $cacheKey = "market.store.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey), Response::HTTP_CREATED);
        }

        $price = (int) $data['price'];
        if (! MarketMath::isValidPrice($price)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nieprawidłowa cena.');
        }

        // Item = zawsze 1 sztuka; stacki = ilość z body.
        $qty = $data['kind'] === 'item' ? 1 : (int) $data['quantity'];
        if (! MarketMath::isValidQuantity($qty)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nieprawidłowa ilość.');
        }

        $payload = DB::transaction(function () use ($state, $character, $data, $price, $qty): array {
            $save = $state->lockedFor($character);

            if ($data['kind'] === 'item') {
                // Anty-dupe: removeBagItem rzuca (→404), jeśli itemu nie ma.
                $item = $state->findBagItem($save, $data['itemUuid']);
                if ($item === null) {
                    abort(Response::HTTP_NOT_FOUND, 'Item nie istnieje w torbie.');
                }
                $state->removeBagItem($save, $data['itemUuid']);

                // Snapshot z REALNEGO itemu (nie z body) — pola ekonomiczne/tożsamość.
                $listing = MarketListing::create([
                    'seller_id' => $character->id,
                    'seller_name' => $character->name,
                    'kind' => 'item',
                    'item_id' => (string) ($item['itemId'] ?? ''),
                    'item_name' => (string) ($item['name'] ?? $data['itemName'] ?? ($item['itemId'] ?? '')),
                    'item_level' => (int) ($item['itemLevel'] ?? 1),
                    'rarity' => (string) ($item['rarity'] ?? 'common'),
                    'slot' => (string) ($item['slot'] ?? $data['slot'] ?? ''),
                    'price' => $price,
                    'quantity' => 1,
                    'quantity_initial' => 1,
                    'bonuses' => $item['bonuses'] ?? [],
                    'upgrade_level' => (int) ($item['upgradeLevel'] ?? 0),
                    'listed_at' => now(),
                ]);
            } else {
                // Stacki: escrow schodzi z odpowiedniego stora (rzuca 422 gdy za mało).
                $this->escrowStack($state, $save, $data['kind'], $data['itemId'], $qty);

                $listing = MarketListing::create([
                    'seller_id' => $character->id,
                    'seller_name' => $character->name,
                    'kind' => $data['kind'],
                    'item_id' => (string) $data['itemId'],
                    'item_name' => (string) ($data['itemName'] ?? $data['itemId']),
                    'item_level' => (int) ($data['itemLevel'] ?? 1),
                    'rarity' => (string) ($data['rarity'] ?? 'common'),
                    'slot' => '',
                    'price' => $price,
                    'quantity' => $qty,
                    'quantity_initial' => $qty,
                    'bonuses' => [],
                    'upgrade_level' => 0,
                    'listed_at' => now(),
                ]);
            }

            $state->persist($save);

            return [
                'listing' => $this->snapshot($listing),
                'gold' => $state->gold($save),
                'inventory' => $save->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload, Response::HTTP_CREATED);
    }

    // ---- Kupno (autorytatywne, anty-dupe) ----------------------------------

    /**
     * POST /characters/{character}/market/listings/{listing}/buy.
     * SERWER: lock listing FOR UPDATE → stock/not-self/gold → dekrement/usunięcie
     * → buyer.spendGold + item → seller gold(netto)+notyfikacja+liczniki. Wszystko
     * w JEDNEJ transakcji. Idempotencja po requestId. NIC client-side.
     */
    public function buy(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $listingId = (string) $request->route('listing');

        $data = $request->validate([
            'quantity' => ['nullable', 'integer'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "market.buy.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $qty = (int) ($data['quantity'] ?? 1);
        if (! MarketMath::isValidQuantity($qty)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nieprawidłowa ilość.');
        }

        $payload = DB::transaction(function () use ($state, $character, $listingId, $qty): array {
            // Serializuje równoległych kupujących na tym samym wierszu (anty-dupe).
            $listing = MarketListing::query()->lockForUpdate()->find($listingId);
            if ($listing === null) {
                abort(Response::HTTP_NOT_FOUND, 'Oferta już nie istnieje.');
            }
            if ($listing->seller_id === $character->id) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nie możesz kupić własnej oferty.');
            }
            if ((int) $listing->quantity < $qty) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Oferta nie ma tylu sztuk.');
            }

            $total = (int) $listing->price * $qty;
            $tax = MarketMath::calculateMarketTax($total);
            $net = $total - $tax;

            // Buyer: prawdziwy gold z bloba; spendGold rzuca 422 gdy za mało.
            $buyer = Character::query()->lockForUpdate()->findOrFail($character->id);
            $buyerSave = $state->lockedFor($buyer);
            $state->spendGold($buyerSave, $total);
            $this->creditBuyer($state, $buyerSave, $listing, $qty);

            // Dekrement/usunięcie aukcji.
            $remaining = (int) $listing->quantity - $qty;
            if ($remaining > 0) {
                $listing->quantity = $remaining;
                $listing->save();
            } else {
                $listing->delete();
            }

            // Liczniki buyera + persist bloba PO wszystkich mutacjach serwisu.
            $buyer->market_items_bought = (int) $buyer->market_items_bought + $qty;
            $buyer->market_gold_spent = (int) $buyer->market_gold_spent + $total;
            $buyer->save();
            $state->persist($buyerSave);

            // Seller: gold netto do JEGO bloba + liczniki (jeśli postać istnieje).
            $seller = Character::query()->lockForUpdate()->find($listing->seller_id);
            if ($seller !== null) {
                $sellerSave = $state->lockedFor($seller);
                $state->addGold($sellerSave, $net);
                $state->persist($sellerSave);

                $seller->market_items_sold = (int) $seller->market_items_sold + $qty;
                $seller->market_gold_earned = (int) $seller->market_gold_earned + $net;
                $seller->save();
            }

            // Notyfikacja sprzedawcy — gold_received = NETTO (faktyczny przychód).
            MarketSaleNotification::create([
                'seller_id' => $listing->seller_id,
                'item_id' => $listing->item_id,
                'item_name' => $listing->item_name,
                'rarity' => $listing->rarity,
                'quantity_sold' => $qty,
                'gold_received' => $net,
                'sold_at' => now(),
                'seen' => false,
            ]);

            return [
                'ok' => true,
                'listing' => $this->snapshot($listing),
                'quantityPurchased' => $qty,
                'remainingQty' => max(0, $remaining),
                'totalPaid' => $total,
                'tax' => $tax,
                'sellerNet' => $net,
                'gold' => $state->gold($buyerSave),
                'inventory' => $buyerSave->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    // ---- Edycja własnej oferty ----------------------------------------------

    /**
     * PUT /characters/{character}/market/listings/{listing} — edycja price/quantity
     * WŁASNEJ oferty (parity: marketApi.updateListing). Tylko sprzedawca (403/404),
     * walidacja ceny/ilości SERWER-side (422). Aktualizuje wiersz market_listings.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $listingId = (string) $request->route('listing');

        $data = $request->validate([
            'price' => ['sometimes', 'integer'],
            'quantity' => ['sometimes', 'integer'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "market.update.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        // Walidacja SERWER-side — nigdy nie ufamy liczbom z body.
        if (array_key_exists('price', $data) && ! MarketMath::isValidPrice((int) $data['price'])) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nieprawidłowa cena.');
        }
        if (array_key_exists('quantity', $data) && ! MarketMath::isValidQuantity((int) $data['quantity'])) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nieprawidłowa ilość.');
        }

        $payload = DB::transaction(function () use ($character, $listingId, $data): array {
            $listing = MarketListing::query()->lockForUpdate()->find($listingId);
            if ($listing === null) {
                abort(Response::HTTP_NOT_FOUND, 'Oferta już nie istnieje.');
            }
            if ($listing->seller_id !== $character->id) {
                abort(Response::HTTP_FORBIDDEN, 'To nie jest twoja oferta.');
            }

            if (array_key_exists('price', $data)) {
                $listing->price = (int) $data['price'];
            }
            if (array_key_exists('quantity', $data)) {
                $listing->quantity = (int) $data['quantity'];
            }
            $listing->save();

            return ['listing' => $this->snapshot($listing)];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    // ---- Notyfikacje sprzedaży ----------------------------------------------

    /**
     * GET /characters/{character}/market/notifications — nieodczytane notyfikacje
     * sprzedaży tej postaci (parity: marketApi.getSaleNotifications — seen=false,
     * najnowsze pierwsze).
     */
    public function notifications(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        return response()->json([
            'notifications' => MarketSaleNotification::query()
                ->where('seller_id', $character->id)
                ->where('seen', false)
                ->orderByDesc('sold_at')
                ->get()
                ->map(fn (MarketSaleNotification $n): array => $this->saleSnapshot($n))
                ->all(),
        ]);
    }

    /**
     * POST /characters/{character}/market/notifications/{id}/dismiss — oznacza
     * notyfikację jako odczytaną (parity: marketApi.dismissSaleNotification →
     * seen=true). Tylko właściciel (403/404).
     */
    public function dismissNotification(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $notificationId = (string) $request->route('id');

        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "market.dismiss.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($character, $notificationId): array {
            $note = MarketSaleNotification::query()->lockForUpdate()->find($notificationId);
            if ($note === null) {
                abort(Response::HTTP_NOT_FOUND, 'Notyfikacja nie istnieje.');
            }
            if ($note->seller_id !== $character->id) {
                abort(Response::HTTP_FORBIDDEN, 'To nie jest twoja notyfikacja.');
            }

            $note->seen = true;
            $note->save();

            return ['dismissed' => $note->id];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    // ---- Wycofanie (zwrot escrow) ------------------------------------------

    /**
     * DELETE /characters/{character}/market/listings/{listing} — wycofuje aukcję
     * i zwraca escrow (POZOSTAŁĄ ilość) do sprzedawcy. Idempotencja naturalna:
     * po usunięciu drugi call → 404.
     */
    public function destroy(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $listingId = (string) $request->route('listing');

        $payload = DB::transaction(function () use ($state, $character, $listingId): array {
            $listing = MarketListing::query()->lockForUpdate()->find($listingId);
            if ($listing === null) {
                abort(Response::HTTP_NOT_FOUND, 'Oferta już nie istnieje.');
            }
            if ($listing->seller_id !== $character->id) {
                abort(Response::HTTP_FORBIDDEN, 'To nie jest twoja oferta.');
            }

            $save = $state->lockedFor($character);
            $this->creditBuyer($state, $save, $listing, (int) $listing->quantity);
            $state->persist($save);

            $listing->delete();

            return [
                'ok' => true,
                'returnedQty' => (int) $listing->quantity,
                'inventory' => $save->state['inventory'],
            ];
        });

        return response()->json($payload);
    }

    // ---- Helpery ------------------------------------------------------------

    /** Escrow stacka z odpowiedniego stora (rzuca InsufficientFundsException → 422). */
    private function escrowStack(CharacterStateService $state, GameSave $save, string $kind, string $itemId, int $qty): void
    {
        switch ($kind) {
            case 'potion':
            case 'elixir':
            case 'spell_chest':
                $state->useConsumable($save, $itemId, $qty);
                break;
            case 'stone':
                $state->useStones($save, $itemId, $qty);
                break;
            case 'arena_points':
                $have = (int) ($save->state['inventory']['arenaPoints'] ?? 0);
                if ($have < $qty) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za mało punktów areny.');
                }
                $state->addArenaPoints($save, -$qty);
                break;
        }
    }

    /**
     * Dopisuje dobro (item lub stack) do bloba odbiorcy — używane przez kupno
     * (transfer do kupującego) ORAZ wycofanie (zwrot do sprzedawcy).
     */
    private function creditBuyer(CharacterStateService $state, GameSave $save, MarketListing $listing, int $qty): void
    {
        switch ($listing->kind) {
            case 'item':
                $state->addBagItem($save, [
                    'uuid' => (string) Str::uuid(),
                    'itemId' => $listing->item_id,
                    'name' => $listing->item_name,
                    'rarity' => $listing->rarity,
                    'slot' => $listing->slot,
                    'bonuses' => $listing->bonuses ?? [],
                    'itemLevel' => (int) $listing->item_level,
                    'upgradeLevel' => (int) $listing->upgrade_level,
                ]);
                break;
            case 'stone':
                $state->addStones($save, $listing->item_id, $qty);
                break;
            case 'arena_points':
                $state->addArenaPoints($save, $qty);
                break;
            case 'potion':
            case 'elixir':
            case 'spell_chest':
            default:
                $state->addConsumable($save, $listing->item_id, $qty);
                break;
        }
    }

    /** Sortowanie ofert (odpowiednik marketSystem.sortListings — tu na DB). */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'level_asc' => $query->orderBy('item_level'),
            'level_desc' => $query->orderByDesc('item_level'),
            default => $query->orderByDesc('listed_at'),
        };
    }

    /**
     * Kształt odpowiedzi 1:1 z IMarketListing (mapDbToListing na froncie).
     *
     * @return array<string, mixed>
     */
    private function snapshot(MarketListing $l): array
    {
        return [
            'id' => $l->id,
            'sellerId' => $l->seller_id,
            'sellerName' => $l->seller_name,
            'kind' => $l->kind,
            'itemId' => $l->item_id,
            'itemName' => $l->item_name,
            'itemLevel' => (int) $l->item_level,
            'rarity' => $l->rarity,
            'slot' => $l->slot,
            'price' => (int) $l->price,
            'quantity' => (int) $l->quantity,
            'quantityInitial' => (int) $l->quantity_initial,
            'bonuses' => $l->bonuses ?? [],
            'upgradeLevel' => (int) $l->upgrade_level,
            'listedAt' => optional($l->listed_at)->toIso8601String(),
        ];
    }

    /**
     * Kształt odpowiedzi 1:1 z IMarketSaleNotification (mapDbToSale na froncie).
     *
     * @return array<string, mixed>
     */
    private function saleSnapshot(MarketSaleNotification $n): array
    {
        return [
            'id' => $n->id,
            'sellerId' => $n->seller_id,
            'itemId' => $n->item_id,
            'itemName' => $n->item_name,
            'rarity' => $n->rarity,
            'quantitySold' => (int) $n->quantity_sold,
            'goldReceived' => (int) $n->gold_received,
            'soldAt' => optional($n->sold_at)->toIso8601String(),
            'seen' => (bool) $n->seen,
        ];
    }
}
