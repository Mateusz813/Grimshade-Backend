<?php

declare(strict_types=1);

namespace App\Support\Auth;

final readonly class SupabaseUser
{
    public function __construct(
        public string $id,
        public ?string $email,
        public string $role,
        public array $claims = [],
    ) {}
}
