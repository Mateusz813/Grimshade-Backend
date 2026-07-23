<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AttributeController;
use Illuminate\Support\Facades\Route;

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/attributes/allocate', [AttributeController::class, 'allocate']);
    });
});
