<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OfflineHuntController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Offline Hunt (settle) — trasy per-postać
|--------------------------------------------------------------------------
| Ładowane przez bootstrap (routes/api/*.php) z prefiksem `api/v1`. Bez
| ustawiania prefixu tutaj. Autoryzacja: `supabase.auth`; per-postać dokłada
| `owns.character` (własność {character}).
|
| Blob-slice: state.offlineHunt = { isActive, startedAt, targetMonster,
| trainedSkillId } + state.mastery (poziom mastery potwora skaluje tempo/XP/gold).
| Znacznik offline: game_saves.offline_entered_at.
|
|  settle: SERWER liczy kills/XP/gold za czas offline (now - startedAt, cap 12h),
|    grant autorytatywny (XP → postać, gold → blob), po czym zatrzymuje polowanie
|    i czyści znacznik offline (anty-duplikacja). Idempotencja: Cache po requestId
|    (replay tego samego id → ta sama odpowiedź) + naturalna (marker znika, więc
|    kolejny settle innym id → nic do rozliczenia).
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/offline-hunt/settle', [OfflineHuntController::class, 'settle']);
    });
});
