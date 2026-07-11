<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RaidController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Domena: raid (autorytatywne rozstrzygnięcie rajdu — mini-resolver fal)
|--------------------------------------------------------------------------
| Prefix `api/v1` dokłada bootstrap (glob routes/api/*.php). Auth: supabase.auth.
| Per-postać: owns.character (własność weryfikowana z tokenu, nie z body).
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        // Symulacja fal bossów + serwerowe nagrody (gold→blob, xp→postać,
        // loot/kamienie/potiony→blob, slice raid→blob). Dzienny limit prób.
        // {raidId} czytany przez $request->route('raidId') (2 parametry trasy).
        Route::post('/characters/{character}/raid/{raidId}/resolve', [RaidController::class, 'resolve']);
    });
});
