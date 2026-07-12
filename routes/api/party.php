<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PartyController;
use Illuminate\Support\Facades\Route;


Route::middleware('supabase.auth')->group(function (): void {
    Route::get('/parties', [PartyController::class, 'index']);

    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/parties', [PartyController::class, 'store']);

        Route::get('/characters/{character}/parties/active', [PartyController::class, 'active']);

        Route::get('/characters/{character}/parties/{party}', [PartyController::class, 'show']);

        Route::put('/characters/{character}/parties/{party}', [PartyController::class, 'update']);

        Route::post('/characters/{character}/parties/{party}/join', [PartyController::class, 'join']);

        Route::post('/characters/{character}/parties/{party}/leave', [PartyController::class, 'leave']);

        Route::post('/characters/{character}/parties/{party}/handover', [PartyController::class, 'handover']);

        Route::post('/characters/{character}/parties/{party}/kick', [PartyController::class, 'kick']);
    });
});
