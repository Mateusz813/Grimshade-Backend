<?php

use App\Http\Middleware\AppendStateVersion;
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
        $middleware->api(append: [AppendStateVersion::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (InsufficientFundsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (StateValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
