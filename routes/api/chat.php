<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Domena: chat (miasto / system / party / guild / PM)
|--------------------------------------------------------------------------
| Prefix `api/v1` dokłada bootstrap (glob routes/api/*.php). Auth: supabase.auth.
| Wysyłka jest per-postać (owns.character) — tożsamość nadawcy serwer bierze z
| postaci, nie z body. Feed to odczyt globalny per kanał.
*/

Route::middleware('supabase.auth')->group(function (): void {
    // Feed kanału (odczyt globalny) — ?channel=&limit.
    Route::get('/chat/messages', [ChatController::class, 'index']);

    // Per-postać (własność weryfikowana przez owns.character).
    Route::middleware('owns.character')->group(function (): void {
        // Wyślij wiadomość (rate-limit + limit długości, tożsamość z serwera).
        Route::post('/characters/{character}/chat/messages', [ChatController::class, 'store']);

        // Broadcast zdarzenia systemowego (upgrade/skillUpgrade → kanał `system`).
        Route::post('/characters/{character}/chat/system-event', [ChatController::class, 'systemEvent']);
    });
});
