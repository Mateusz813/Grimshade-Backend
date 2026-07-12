<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MarketController;
use Illuminate\Support\Facades\Route;


Route::middleware('supabase.auth')->group(function (): void {
    Route::get('/market/listings', [MarketController::class, 'index']);

    Route::middleware('owns.character')->group(function (): void {
        Route::get('/characters/{character}/market/mine', [MarketController::class, 'mine']);

        Route::post('/characters/{character}/market/listings', [MarketController::class, 'store']);

        Route::post('/characters/{character}/market/listings/{listing}/buy', [MarketController::class, 'buy']);

        Route::delete('/characters/{character}/market/listings/{listing}', [MarketController::class, 'destroy']);

        Route::put('/characters/{character}/market/listings/{listing}', [MarketController::class, 'update']);

        Route::get('/characters/{character}/market/notifications', [MarketController::class, 'notifications']);
        Route::post('/characters/{character}/market/notifications/{id}/dismiss', [MarketController::class, 'dismissNotification']);
    });
});
