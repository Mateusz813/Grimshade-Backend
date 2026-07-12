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


Route::get('/content/version', [ContentController::class, 'version']);

Route::middleware('supabase.auth')->group(function (): void {
    Route::get('/characters', [CharacterController::class, 'index']);

    Route::get('/shop/catalog', [ShopController::class, 'catalog']);
    Route::get('/deaths', [DeathController::class, 'index']);

    Route::middleware('owns.character')->group(function (): void {
        Route::get('/characters/{character}/state', [CharacterStateController::class, 'show']);
        Route::put('/characters/{character}/state', [CharacterStateController::class, 'commit']);
        Route::put('/characters/{character}/prefs', [CharacterStateController::class, 'updatePrefs']);

        Route::post('/characters/{character}/combat/resolve', [CombatController::class, 'resolve']);
        Route::post('/characters/{character}/items/sell', [ItemController::class, 'sell']);
        Route::post('/characters/{character}/items/upgrade', [ItemController::class, 'upgrade']);
        Route::post('/characters/{character}/shop/buy-elixir', [ShopController::class, 'buyElixir']);

        Route::post('/characters/{character}/inventory/equip', [InventoryController::class, 'equip']);
        Route::post('/characters/{character}/inventory/unequip', [InventoryController::class, 'unequip']);
        Route::post('/characters/{character}/inventory/deposit', [InventoryController::class, 'moveToDeposit']);
        Route::post('/characters/{character}/inventory/withdraw', [InventoryController::class, 'moveToBag']);

        Route::post('/characters/{character}/deaths', [DeathController::class, 'store']);

        Route::post('/characters/{character}/tasks/{taskId}/claim', [ProgressionController::class, 'claimTask']);
    });
});
