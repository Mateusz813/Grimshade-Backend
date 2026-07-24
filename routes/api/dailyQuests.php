<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DailyQuestController;
use Illuminate\Support\Facades\Route;

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/daily-quests/refresh', [DailyQuestController::class, 'refresh']);
        Route::post('/characters/{character}/daily-quests/claim-all', [DailyQuestController::class, 'claimAll']);
        Route::post('/characters/{character}/daily-quests/{questId}/claim', [DailyQuestController::class, 'claim']);
    });
});
