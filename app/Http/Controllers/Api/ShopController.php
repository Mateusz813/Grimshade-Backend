<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Content\ContentRepository;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Shop\ShopCatalog;
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
 * Sklep (potiony/eliksiry). Katalog + ceny = resources/game-content/shop.json
 * (generowany z shopStore.ts frontu — jedno źródło prawdy). Serwer waliduje
 * minLevel i cenę; klient podaje tylko id + ilość. Semantyka 1:1 z Shop.tsx:
 * spendGold(price*qty) + addConsumable(id, qty).
 */
final class ShopController extends Controller
{
    /** GET /shop/catalog — katalog dla frontu (opcjonalny odczyt). */
    public function catalog(ContentRepository $content): JsonResponse
    {
        return response()->json($content->get('shop'));
    }

    public function buyElixir(Request $request, CharacterStateService $state, ContentRepository $content): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemId' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "shop.buy.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $entry = collect($content->get('shop')['elixirs'])->firstWhere('id', $data['itemId']);
        if ($entry === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego towaru.');
        }
        if ((int) $character->level < (int) $entry['minLevel']) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Wymagany poziom {$entry['minLevel']}.");
        }

        $total = (int) $entry['price'] * (int) $data['quantity'];

        $payload = DB::transaction(function () use ($state, $character, $data, $total): array {
            $save = $state->lockedFor($character);
            $state->spendGold($save, $total);
            $state->addConsumable($save, $data['itemId'], (int) $data['quantity']);
            $state->persist($save);

            return [
                'itemId' => $data['itemId'],
                'quantity' => (int) $data['quantity'],
                'totalPrice' => $total,
                'gold' => $state->gold($save),
                'consumables' => $save->state['inventory']['consumables'] ?? [],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * POST /shop/buy-item — kupno itemowego (NIE-eliksirowego) towaru sklepu:
     * bronie / offhandy / pancerz / akcesoria. Katalog jest GENEROWANY per
     * klasa+poziom (shopStore.ts generateShopItems), więc serwer odtwarza go
     * przez ShopCatalog żeby autorytatywnie ustalić cenę + parametry generacji.
     * Item, który gracz dostaje, tworzy ItemGenerator (serwerowy RNG) — dokładnie
     * jak `buyShopItem` na froncie. Cena/level-gate liczy SERWER; z body czyta
     * WYŁĄCZNIE itemId + requestId.
     *
     * Semantyka 1:1 z frontem (Shop.tsx handleBuyItem → shopStore.buyShopItem):
     *  - level gate: `character.level >= item.level` (id koduje poziom; sklep
     *    generuje itemy na poziomie min(charLevel, 100), więc minLevel == level itemu),
     *  - spendGold(price) → potem generacja itemu → addBagItem.
     */
    public function buyItem(
        Request $request,
        CharacterStateService $state,
        ContentRepository $content,
        RngInterface $rng,
    ): JsonResponse {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemId' => ['required', 'string', 'max:128'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "shop.buyItem.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        // Odtwórz katalog na poziomie zakodowanym w itemId (rarity + level z prawej),
        // dla klasy postaci. Membership = jednocześnie walidacja klasy i formatu id.
        $parsedLevel = $this->parseItemLevel($data['itemId']);
        if ($parsedLevel === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego towaru.');
        }

        $catalog = (new ShopCatalog($content->get('itemTemplates')))
            ->generate((string) $character->class, $parsedLevel);
        $entry = $catalog[$data['itemId']] ?? null;
        if ($entry === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego towaru.');
        }

        // Level gate: sklep pokazuje itemy na poziomie gracza (cap 100), więc
        // minLevel itemu == jego poziom. Za niski poziom → 422.
        if ((int) $character->level < (int) $entry['level']) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Wymagany poziom {$entry['level']}.");
        }

        $totalPrice = (int) $entry['price'];

        $payload = DB::transaction(function () use ($state, $character, $data, $entry, $totalPrice, $content, $rng): array {
            $save = $state->lockedFor($character);
            $state->spendGold($save, $totalPrice);

            $item = $this->generateItem(new ItemGenerator($content->get('itemTemplates'), $rng), $entry);
            if ($item === null) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nie udało się wygenerować itemu.');
            }
            $state->addBagItem($save, $item);
            $state->persist($save);

            return [
                'itemId' => $data['itemId'],
                'item' => $item,
                'totalPrice' => $totalPrice,
                'gold' => $state->gold($save),
                'inventory' => $save->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * Wyciąga poziom itemu z `shop_..._{level}_{rarity}` (parsuje od prawej:
     * ostatni token = rarity, przedostatni = level). Zwraca null gdy format zły.
     */
    private function parseItemLevel(string $itemId): ?int
    {
        $parts = explode('_', $itemId);
        if (count($parts) < 4) {
            return null;
        }
        $levelPart = $parts[count($parts) - 2];
        if (! ctype_digit($levelPart)) {
            return null;
        }

        return (int) $levelPart;
    }

    /**
     * Generuje item dla wpisu katalogu przez ItemGenerator — mapowanie
     * templateType → metoda 1:1 z buyShopItem (shopStore.ts).
     *
     * @param  array{templateType: string, level: int, rarity: string, type?: string, slot?: string, armorPrefix?: string}  $entry
     * @return array<string, mixed>|null
     */
    private function generateItem(ItemGenerator $generator, array $entry): ?array
    {
        return match ($entry['templateType']) {
            'weapon' => $generator->generateWeapon($entry['type'], $entry['level'], $entry['rarity']),
            'offhand' => $generator->generateOffhand($entry['type'], $entry['level'], $entry['rarity']),
            'armor' => $generator->generateArmor($entry['armorPrefix'] ?? '', $entry['slot'], $entry['level'], $entry['rarity']),
            'accessory' => $generator->generateAccessory($entry['type'], $entry['level'], $entry['rarity']),
            default => null,
        };
    }
}
