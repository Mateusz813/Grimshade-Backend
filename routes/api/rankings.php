<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RankingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rankings (DPS) — moduł tras rankingowych
|--------------------------------------------------------------------------
| Ładowane przez bootstrap (routes/api/*.php) z prefiksem `api/v1`. Bez
| ustawiania prefixu tutaj. Autoryzacja: `supabase.auth`; per-postać dokłada
| `owns.character` (własność {character}).
|
| DPS high-water mark: SERWER zaciska maksa na wierszu characters (best_dps5_*).
| Klient podaje zmierzony dps + inParty; nic z body nie steruje kolumną poza
| tym, że wyższy wynik nadpisuje aktualny. Zastępuje kliencki zapis z Trenera.
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/dps-record', [RankingController::class, 'dpsRecord']);
    });
});
