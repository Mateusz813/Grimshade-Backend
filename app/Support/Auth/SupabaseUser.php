<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Zweryfikowana tożsamość z tokenu Supabase GoTrue.
 *
 * `id` = claim `sub` (Supabase user_id). To JEDYNE zaufane źródło tożsamości —
 * nigdy nie ufamy user_id przekazanemu w body requestu.
 */
final readonly class SupabaseUser
{
    /**
     * @param  array<string, mixed>  $claims  surowe claims tokenu (do debugowania/audytu)
     */
    public function __construct(
        public string $id,
        public ?string $email,
        public string $role,
        public array $claims = [],
    ) {}
}
