<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Content\ContentRepository;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Shop\ShopCatalog;
use App\Domain\Support\Rng\RngInterface;
use App\Http\Controllers\Controller;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ShopController extends Controller
{
    public function catalog(ContentRepository $content): JsonResponse
    {
        return response()->json($content->get('shop'));
    }

    public function buyElixir(Request $request, CharacterStateService $state, ContentRepository $content): JsonResponse
    {
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

    public function buyItem(
        Request $request,
        CharacterStateService $state,
        ContentRepository $content,
        RngInterface $rng,
    ): JsonResponse {
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemId' => ['required', 'string', 'max:128'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "shop.buyItem.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

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
