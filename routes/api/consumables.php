<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ConsumableController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Consumables — alchemia, wypijanie eliksirów, reset statystyk
|--------------------------------------------------------------------------
| Ładowane przez bootstrap (routes/api/*.php) z prefiksem `api/v1`. Bez
| ustawiania prefixu tutaj. Autoryzacja: `supabase.auth`; per-postać dokłada
| `owns.character`.
|
| SERWER liczy receptury/koszty/wynik — nic z body nie jest ufane poza id
| składnika i requestId (idempotencja).
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/potions/convert', [ConsumableController::class, 'convert']);
        Route::post('/characters/{character}/consumables/use', [ConsumableController::class, 'use']);
        Route::post('/characters/{character}/character/stat-reset', [ConsumableController::class, 'statReset']);
    });
});
