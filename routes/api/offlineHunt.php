<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OfflineHuntController;
use Illuminate\Support\Facades\Route;


Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/offline-hunt/settle', [OfflineHuntController::class, 'settle']);
    });
});
