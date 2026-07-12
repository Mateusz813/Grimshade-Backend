<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ChatController;
use Illuminate\Support\Facades\Route;


Route::middleware('supabase.auth')->group(function (): void {
    Route::get('/chat/messages', [ChatController::class, 'index']);

    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/chat/messages', [ChatController::class, 'store']);

        Route::post('/characters/{character}/chat/system-event', [ChatController::class, 'systemEvent']);
    });
});
