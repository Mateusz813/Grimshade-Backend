<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\CharacterStateController;
use App\Http\Controllers\Api\CombatController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DeathController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ProgressionController;
use App\Http\Controllers\Api\ShopController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (prefix `api/v1`)
|--------------------------------------------------------------------------
| Wszystkie endpointy danych gry. Autoryzacja: `supabase.auth` (JWT GoTrue).
| Per-postać endpointy dokładają `owns.character` (własność postaci).
*/

// Publiczne (bez auth) — front sprawdza zgodność treści przed loginem.
Route::get('/content/version', [ContentController::class, 'version']);

Route::middleware('supabase.auth')->group(function (): void {
    Route::get('/characters', [CharacterController::class, 'index']);

    // Katalog sklepu + globalny feed śmierci (odczyt, bez per-postać).
    Route::get('/shop/catalog', [ShopController::class, 'catalog']);
    Route::get('/deaths', [DeathController::class, 'index']);

    // Endpointy per-postać (własność weryfikowana przez owns.character).
    Route::middleware('owns.character')->group(function (): void {
        // Stan gry (hydracja frontu) + preferencje klienta.
        Route::get('/characters/{character}/state', [CharacterStateController::class, 'show']);
        Route::put('/characters/{character}/prefs', [CharacterStateController::class, 'updatePrefs']);

        // Autorytatywne intencje — serwer liczy wynik, klient aplikuje odpowiedź.
        Route::post('/characters/{character}/combat/resolve', [CombatController::class, 'resolve']);
        Route::post('/characters/{character}/items/sell', [ItemController::class, 'sell']);
        Route::post('/characters/{character}/items/upgrade', [ItemController::class, 'upgrade']);
        Route::post('/characters/{character}/shop/buy-elixir', [ShopController::class, 'buyElixir']);

        // Inwentarz: equip/unequip + skrytka.
        Route::post('/characters/{character}/inventory/equip', [InventoryController::class, 'equip']);
        Route::post('/characters/{character}/inventory/unequip', [InventoryController::class, 'unequip']);
        Route::post('/characters/{character}/inventory/deposit', [InventoryController::class, 'moveToDeposit']);
        Route::post('/characters/{character}/inventory/withdraw', [InventoryController::class, 'moveToBag']);

        // Log śmierci (tożsamość postaci z serwera).
        Route::post('/characters/{character}/deaths', [DeathController::class, 'store']);

        // Odbiór nagród za progresję (serwer przelicza z żywej treści).
        Route::post('/characters/{character}/tasks/{taskId}/claim', [ProgressionController::class, 'claimTask']);
    });
});
