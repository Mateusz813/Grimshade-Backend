<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArenaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Arena (PvP) — moduł tras domeny areny
|--------------------------------------------------------------------------
| Ładowane przez bootstrap (routes/api/*.php) z prefiksem `api/v1`. Bez
| ustawiania prefixu tutaj. Autoryzacja: `supabase.auth`; per-postać
| dokłada `owns.character` (własność NAPASTNIKA — {character}).
|
| Mecz areny: SERWER symuluje walkę i liczy wynik (attackerWon) — nic z body
| nie jest ufane. Aktualizuje OBIE postaci (napastnik + obrońca) atomowo.
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/arena/match', [ArenaController::class, 'match']);

        // Sklep areny (AP) — katalog + zakup. Ceny/gating/typ broni liczy serwer.
        Route::get('/characters/{character}/arena/shop', [ArenaController::class, 'shop']);
        Route::post('/characters/{character}/arena/shop/buy', [ArenaController::class, 'buy']);

        // Sezon areny — podgląd + odbiór nagród (awans/spadek, reset wycinka bloba).
        Route::get('/characters/{character}/arena/season', [ArenaController::class, 'season']);
        Route::post('/characters/{character}/arena/season/claim', [ArenaController::class, 'claimSeason']);
    });
});
