<?php

declare(strict_types=1);

use App\Http\Controllers\Api\TransformController;
use Illuminate\Support\Facades\Route;


Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/transform/{transformId}/resolve', [TransformController::class, 'resolve']);

        Route::post('/characters/{character}/transform/claim', [TransformController::class, 'claim']);
    });
});
