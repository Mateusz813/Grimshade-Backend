<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ShopController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shop (sklep) — moduł tras domeny sklepu
|--------------------------------------------------------------------------
| Ładowane przez bootstrap (routes/api/*.php) z prefiksem `api/v1`. Bez
| ustawiania prefixu tutaj. Autoryzacja: `supabase.auth`; per-postać
| dokłada `owns.character` (własność {character}).
|
| buy-item: kupno itemowego (NIE-eliksirowego) towaru — bronie/offhandy/
| pancerz/akcesoria. Katalog GENEROWANY per klasa+poziom; SERWER odtwarza go
| (ShopCatalog), liczy cenę + level-gate i generuje item (ItemGenerator).
| Katalog czytany (GET /shop/catalog) oraz buy-elixir żyją w routes/api.php.
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/shop/buy-item', [ShopController::class, 'buyItem']);
    });
});
