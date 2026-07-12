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

final class MarketController extends Controller
{
    private const KINDS = ['item', 'potion', 'elixir', 'stone', 'arena_points', 'spell_chest'];

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

    public function mine(Request $request): JsonResponse
    {
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

    public function store(Request $request, CharacterStateService $state): JsonResponse
    {
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

        $qty = $data['kind'] === 'item' ? 1 : (int) $data['quantity'];
        if (! MarketMath::isValidQuantity($qty)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nieprawidłowa ilość.');
        }

        $payload = DB::transaction(function () use ($state, $character, $data, $price, $qty): array {
            $save = $state->lockedFor($character);

            if ($data['kind'] === 'item') {
                $item = $state->findBagItem($save, $data['itemUuid']);
                if ($item === null) {
                    abort(Response::HTTP_NOT_FOUND, 'Item nie istnieje w torbie.');
                }
                $state->removeBagItem($save, $data['itemUuid']);

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

    public function buy(Request $request, CharacterStateService $state): JsonResponse
    {
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

            $buyer = Character::query()->lockForUpdate()->findOrFail($character->id);
            $buyerSave = $state->lockedFor($buyer);
            $state->spendGold($buyerSave, $total);
            $this->creditBuyer($state, $buyerSave, $listing, $qty);

            $remaining = (int) $listing->quantity - $qty;
            if ($remaining > 0) {
                $listing->quantity = $remaining;
                $listing->save();
            } else {
                $listing->delete();
            }

            $buyer->market_items_bought = (int) $buyer->market_items_bought + $qty;
            $buyer->market_gold_spent = (int) $buyer->market_gold_spent + $total;
            $buyer->save();
            $state->persist($buyerSave);

            $seller = Character::query()->lockForUpdate()->find($listing->seller_id);
            if ($seller !== null) {
                $sellerSave = $state->lockedFor($seller);
                $state->addGold($sellerSave, $net);
                $state->persist($sellerSave);

                $seller->market_items_sold = (int) $seller->market_items_sold + $qty;
                $seller->market_gold_earned = (int) $seller->market_gold_earned + $net;
                $seller->save();
            }

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

    public function update(Request $request): JsonResponse
    {
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

    public function notifications(Request $request): JsonResponse
    {
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

    public function dismissNotification(Request $request): JsonResponse
    {
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

    public function destroy(Request $request, CharacterStateService $state): JsonResponse
    {
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
