<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CharacterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Postaci (CRUD) — moduł tras domeny postaci
|--------------------------------------------------------------------------
| Ładowane przez bootstrap (routes/api/*.php) z prefiksem `api/v1`. Bez
| ustawiania prefixu tutaj.
|
| store: tylko `supabase.auth` — tożsamość z tokenu (sub); przy store postaci
| jeszcze NIE ma, więc owns.character nie ma czego pilnować. Serwer DERYWUJE
| staty startowe z katalogu klas i SEEDUJE startowy blob game_saves.
| (GET /characters — index — żyje już w routes/api.php.)
|
| destroy: `supabase.auth` + `owns.character` — kasowanie tylko WŁASNEJ postaci
| (własność udowodniona → brak hasła w body). Sprząta roster/market/game_saves
| + wiersz characters w jednej transakcji.
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::post('/characters', [CharacterController::class, 'store']);

    Route::middleware('owns.character')->group(function (): void {
        Route::delete('/characters/{character}', [CharacterController::class, 'destroy']);
    });
});
