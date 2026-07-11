<?php

declare(strict_types=1);

use App\Http\Controllers\Api\QuestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Quests (domena: quests) — prefix `api/v1` dokłada bootstrap.
|--------------------------------------------------------------------------
| Odbiór nagród za ukończony quest. Serwer waliduje ukończenie (wszystkie
| goals progress >= count) z blobu i PRZELICZA nagrody z żywej treści
| (quests.json rewards[]). Idempotencja naturalna: quest znika z
| activeQuests → trafia do completedQuestIds (drugi claim → 404).
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post(
            '/characters/{character}/quests/{questId}/claim',
            [QuestController::class, 'claim'],
        );
    });
});
