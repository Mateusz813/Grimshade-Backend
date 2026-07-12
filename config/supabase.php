<?php

declare(strict_types=1);

return [
    'url' => env('SUPABASE_URL', ''),

    'jwt' => [
        'driver' => env('SUPABASE_JWT_DRIVER', 'hmac'),

        'secret' => env('SUPABASE_JWT_SECRET', ''),

        'jwks_url' => env(
            'SUPABASE_JWKS_URL',
            env('SUPABASE_URL', '') !== ''
                ? rtrim((string) env('SUPABASE_URL'), '/').'/auth/v1/.well-known/jwks.json'
                : ''
        ),

        'issuer' => env(
            'SUPABASE_JWT_ISS',
            env('SUPABASE_URL', '') !== ''
                ? rtrim((string) env('SUPABASE_URL'), '/').'/auth/v1'
                : ''
        ),
        'audience' => env('SUPABASE_JWT_AUD', 'authenticated'),

        'leeway' => (int) env('SUPABASE_JWT_LEEWAY', 30),
    ],

    'state_commit_strict' => (bool) env('SUPABASE_STATE_COMMIT_STRICT', false),

    'event_validation_strict' => (bool) env('SUPABASE_EVENT_VALIDATION_STRICT', false),
];
