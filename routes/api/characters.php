<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CharacterController;
use Illuminate\Support\Facades\Route;


Route::middleware('supabase.auth')->group(function (): void {
    Route::post('/characters', [CharacterController::class, 'store']);

    Route::middleware('owns.character')->group(function (): void {
        Route::delete('/characters/{character}', [CharacterController::class, 'destroy']);
    });
});
