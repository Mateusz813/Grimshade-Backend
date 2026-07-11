<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DungeonController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Domena: dungeon (autorytatywne rozstrzygnięcie fal lochu)
|--------------------------------------------------------------------------
| Prefix `api/v1` dokłada bootstrap (glob routes/api/*.php). Auth: supabase.auth.
| Per-postać: owns.character (własność weryfikowana z tokenu, nie z body).
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        // Symulacja fal (DungeonSystem::resolveDungeon) + serwerowe nagrody
        // (gold→blob, xp→postać, loot→blob) + dzienny limit + min-level.
        // {dungeonId} czytany przez $request->route('dungeonId') (2 parametry trasy).
        Route::post('/characters/{character}/dungeon/{dungeonId}/resolve', [DungeonController::class, 'resolve']);
    });
});
