<?php

declare(strict_types=1);

use App\Http\Controllers\Api\TransformController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Domena: transform (autorytatywna progresja transformacji)
|--------------------------------------------------------------------------
| Prefix `api/v1` dokłada bootstrap (glob routes/api/*.php). Auth: supabase.auth.
| Per-postać: owns.character (własność weryfikowana z tokenu, nie z body).
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        // Walka z transform-bossem (staty scaleMonsterStats + TRANSFORM_BOSS_MULTIPLIER).
        // {transformId} czytany przez $request->route('transformId') (2 parametry trasy).
        Route::post('/characters/{character}/transform/{transformId}/resolve', [TransformController::class, 'resolve']);

        // Odbiór nagród: dopisz completedTransforms + trwałe bonusy (natural idempotency).
        Route::post('/characters/{character}/transform/claim', [TransformController::class, 'claim']);
    });
});
