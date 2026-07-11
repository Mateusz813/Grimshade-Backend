<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Content\ContentRepository;
use App\Domain\Support\Rng\RngInterface;
use App\Domain\Support\Rng\SecureRng;
use App\Support\Auth\HmacSupabaseTokenVerifier;
use App\Support\Auth\SupabaseTokenVerifier;
use App\Support\Auth\SystemClock;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Treść gry (monsters/items/skills/...) — jedno źródło prawdy balansu.
        $this->app->singleton(
            ContentRepository::class,
            fn (): ContentRepository => new ContentRepository(resource_path('game-content')),
        );

        // Domyślne (produkcyjne) RNG — nieprzewidywalne dla klienta. Kod domenowy,
        // który potrzebuje determinizmu (golden-vectory), tworzy Mulberry32Rng jawnie.
        $this->app->bind(RngInterface::class, SecureRng::class);

        // Weryfikator JWT Supabase — wybierany po SUPABASE_JWT_DRIVER.
        // 'jwks' (RS256/ES256) dojdzie później jako osobna implementacja.
        $this->app->singleton(SupabaseTokenVerifier::class, function ($app): SupabaseTokenVerifier {
            $cfg = $app['config']->get('supabase.jwt');

            return match ($cfg['driver']) {
                'hmac' => new HmacSupabaseTokenVerifier(
                    secret: (string) $cfg['secret'],
                    issuer: (string) $cfg['issuer'],
                    audience: (string) $cfg['audience'],
                    leewaySeconds: (int) $cfg['leeway'],
                    clock: new SystemClock,
                ),
                default => throw new InvalidArgumentException(
                    "Nieobsługiwany SUPABASE_JWT_DRIVER: {$cfg['driver']}",
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Front oczekuje surowej tablicy (jak PostgREST), bez opakowania `data`.
        JsonResource::withoutWrapping();
    }
}
