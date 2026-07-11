<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MarketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Market (aukcje gracz→gracz) — prefix `api/v1` (dodaje bootstrap).
|--------------------------------------------------------------------------
| Autorytet: serwer liczy cały przepływ gold/escrow/transfer — klient wysyła
| tylko intencje. NAJWAŻNIEJSZE: kupno zamyka duping (lock listing FOR UPDATE
| + dekrement/usunięcie + transfer + notyfikacja — w JEDNEJ transakcji).
*/

Route::middleware('supabase.auth')->group(function (): void {
    // Przeglądanie ofert (odczyt globalny, bez per-postać).
    Route::get('/market/listings', [MarketController::class, 'index']);

    // Per-postać (własność weryfikowana przez owns.character).
    Route::middleware('owns.character')->group(function (): void {
        // Moje aukcje.
        Route::get('/characters/{character}/market/mine', [MarketController::class, 'mine']);

        // Wystaw aukcję (escrow: item schodzi z bloba ATOMOWO z insertem).
        Route::post('/characters/{character}/market/listings', [MarketController::class, 'store']);

        // Kup (SERWER liczy gold + transfer, anty-dupe lock FOR UPDATE).
        // {listing} czytamy przez $request->route('listing') — Laravel gubi
        // wiązanie przy 2 parametrach trasy.
        Route::post('/characters/{character}/market/listings/{listing}/buy', [MarketController::class, 'buy']);

        // Wycofaj aukcję (zwrot escrow do sprzedawcy).
        Route::delete('/characters/{character}/market/listings/{listing}', [MarketController::class, 'destroy']);

        // Edytuj WŁASNĄ ofertę (price/quantity). {listing} przez route('listing').
        Route::put('/characters/{character}/market/listings/{listing}', [MarketController::class, 'update']);

        // Notyfikacje sprzedaży (nieodczytane) + oznaczenie jako odczytane.
        Route::get('/characters/{character}/market/notifications', [MarketController::class, 'notifications']);
        Route::post('/characters/{character}/market/notifications/{id}/dismiss', [MarketController::class, 'dismissNotification']);
    });
});
