<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArenaController;
use Illuminate\Support\Facades\Route;


Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/arena/match', [ArenaController::class, 'match']);

        Route::get('/characters/{character}/arena/shop', [ArenaController::class, 'shop']);
        Route::post('/characters/{character}/arena/shop/buy', [ArenaController::class, 'buy']);

        Route::get('/characters/{character}/arena/season', [ArenaController::class, 'season']);
        Route::post('/characters/{character}/arena/season/claim', [ArenaController::class, 'claimSeason']);
    });
});
