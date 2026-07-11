<?php

use App\Http\Middleware\EnsureOwnsCharacter;
use App\Http\Middleware\VerifySupabaseJwt;
use App\Services\InsufficientFundsException;
use App\Services\StateValidationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Auto-ładowanie modułowych plików tras (routes/api/*.php) — każda
            // domena endpointów ma własny plik (zero kolizji przy równoległej pracy).
            // Wewnątrz: `Route::middleware('supabase.auth')->group(...)` jak w api.php.
            foreach (glob(base_path('routes/api/*.php')) ?: [] as $file) {
                Route::middleware('api')->prefix('api/v1')->group($file);
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'supabase.auth' => VerifySupabaseJwt::class,
            'owns.character' => EnsureOwnsCharacter::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Odmowa gry (brak środków itp.) = 422, nie 500.
        $exceptions->render(function (InsufficientFundsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        // Odrzucenie zapisu stanu (tryb STRICT anty-cheatu) = 422.
        $exceptions->render(function (StateValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
