<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Character\EffectiveStats;
use App\Domain\Content\ContentRepository;
use App\Domain\Support\Rng\RngInterface;
use App\Domain\Support\Rng\SecureRng;
use App\Support\Auth\HmacSupabaseTokenVerifier;
use App\Support\Auth\JwksSupabaseTokenVerifier;
use App\Support\Auth\SupabaseTokenVerifier;
use App\Support\Auth\SystemClock;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ContentRepository::class,
            fn (): ContentRepository => new ContentRepository(resource_path('game-content')),
        );

        $this->app->bind(RngInterface::class, SecureRng::class);

        $this->app->singleton(
            EffectiveStats::class,
            fn ($app): EffectiveStats => EffectiveStats::fromContent($app->make(ContentRepository::class)),
        );

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
                'jwks' => new JwksSupabaseTokenVerifier(
                    jwksUrl: (string) $cfg['jwks_url'],
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

    public function boot(): void
    {
        JsonResource::withoutWrapping();
    }
}
