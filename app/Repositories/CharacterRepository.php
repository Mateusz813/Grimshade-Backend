<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Character;
use Illuminate\Database\Eloquent\Collection;

final class CharacterRepository
{
    public function forUser(string $userId): Collection
    {
        return Character::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get();
    }
}
