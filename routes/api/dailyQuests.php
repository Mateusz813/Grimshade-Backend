<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DailyQuestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dzienne questy (dailyQuests) — trasy per-postać
|--------------------------------------------------------------------------
| Blob-slice: state.dailyQuests (lastRefreshDate, activeQuests, todayQuestDefs)
| + licznik rankingowy characters.quests_daily_done. Brak dedykowanych tabel.
|
|  refresh: gdy lastRefreshDate != dziś → nowy zestaw questów dnia
|    (deterministyczny per dzień + poziom, DailyQuestSystem). Klucz dnia z body
|    'date' albo z now() serwera. Naturalna idempotencja (ten sam dzień → no-op).
|  claim: serwer PRZELICZA nagrodę (gold→blob, xp→postać, elixir→consumable) za
|    UKOŃCZONY quest i bumpuje quests_daily_done. Idempotencja: flaga `claimed`
|    (drugi claim → 422, brak podwójnej nagrody).
|
| Grupowanie jak w routes/api.php — prefix api/v1 dokłada bootstrap.
*/
Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/daily-quests/refresh', [DailyQuestController::class, 'refresh']);
        Route::post('/characters/{character}/daily-quests/{questId}/claim', [DailyQuestController::class, 'claim']);
    });
});
