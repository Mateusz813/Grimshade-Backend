<?php

declare(strict_types=1);

namespace App\Support\Auth;

interface SupabaseTokenVerifier
{
    public function verify(string $jwt): SupabaseUser;
}
