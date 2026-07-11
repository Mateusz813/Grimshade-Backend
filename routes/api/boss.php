<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BossController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Domena: boss (autorytatywne rozstrzygnięcie walki z bossem)
|--------------------------------------------------------------------------
| Prefix `api/v1` dokłada bootstrap (glob routes/api/*.php). Auth: supabase.auth.
| Per-postać: owns.character (własność weryfikowana z tokenu, nie z body).
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        // Symulacja + serwerowe nagrody (gold→blob, xp→postać, loot→blob).
        // {bossId} czytany przez $request->route('bossId') (2 parametry trasy).
        Route::post('/characters/{character}/boss/{bossId}/resolve', [BossController::class, 'resolve']);
    });
});
