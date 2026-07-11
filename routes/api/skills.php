<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SkillController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Skills (per-postać) — ulepszanie active skilli + trening offline
|--------------------------------------------------------------------------
| Tożsamość z tokenu (supabase.auth), własność postaci z owns.character.
| Koszt spell-chest/gold i XP treningu liczy SERWER (App\Domain\Skills\SkillSystem).
| Prefix `api/v1` dokłada bootstrap — NIE ustawiamy go tutaj.
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        // Trening offline — start wybiera skill, collect przelicza XP z czasu serwera.
        Route::post('/characters/{character}/skills/train/start', [SkillController::class, 'trainStart']);
        Route::post('/characters/{character}/skills/train/collect', [SkillController::class, 'trainCollect']);

        // Ulepszenie active skilla (koszt: spell-chesty + gold; roll sukcesu serwerowy).
        Route::post('/characters/{character}/skills/{skillId}/upgrade', [SkillController::class, 'upgrade']);

        // Przypisanie/wyczyszczenie slotu active-skilla (0-3).
        Route::post('/characters/{character}/skills/slot', [SkillController::class, 'slot']);

        // Odblokowanie active skilla (koszt: 1 spell-chest unlockLevel + gold).
        Route::post('/characters/{character}/skills/{skillId}/unlock', [SkillController::class, 'unlock']);
    });
});
