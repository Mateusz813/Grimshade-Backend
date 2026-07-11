<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ItemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Items — moduł tras domeny itemów (rozkład / reroll / konwersja kamieni)
|--------------------------------------------------------------------------
| Ładowane przez bootstrap (routes/api/*.php) z prefiksem `api/v1`. Bez
| ustawiania prefixu tutaj. Autoryzacja: `supabase.auth`; per-postać dokłada
| `owns.character`.
|
| SERWER liczy każdy koszt/RNG (ItemEconomy + StoneSystem + serwerowy RNG).
| Klient podaje tylko uuid(-y) itemu / typ kamienia + requestId (idempotencja).
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/items/disassemble', [ItemController::class, 'disassemble']);
        Route::post('/characters/{character}/items/disassemble-mass', [ItemController::class, 'disassembleMass']);
        Route::post('/characters/{character}/items/reroll', [ItemController::class, 'reroll']);
        Route::post('/characters/{character}/stones/convert', [ItemController::class, 'convertStones']);
    });
});
