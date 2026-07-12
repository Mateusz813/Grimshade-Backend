<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ConsumableController;
use Illuminate\Support\Facades\Route;


Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/potions/convert', [ConsumableController::class, 'convert']);
        Route::post('/characters/{character}/consumables/use', [ConsumableController::class, 'use']);
        Route::post('/characters/{character}/character/stat-reset', [ConsumableController::class, 'statReset']);
    });
});
