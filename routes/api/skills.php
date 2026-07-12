<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SkillController;
use Illuminate\Support\Facades\Route;


Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/skills/train/start', [SkillController::class, 'trainStart']);
        Route::post('/characters/{character}/skills/train/collect', [SkillController::class, 'trainCollect']);

        Route::post('/characters/{character}/skills/{skillId}/upgrade', [SkillController::class, 'upgrade']);

        Route::post('/characters/{character}/skills/slot', [SkillController::class, 'slot']);

        Route::post('/characters/{character}/skills/{skillId}/unlock', [SkillController::class, 'unlock']);
    });
});
