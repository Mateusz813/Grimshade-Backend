<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Character;
use Illuminate\Database\Eloquent\Collection;

/**
 * Dostęp do danych postaci. Warstwa serwisowa/kontrolery zależą od repo,
 * nie od Eloquent bezpośrednio — łatwiej testować i podmieniać.
 */
final class CharacterRepository
{
    /**
     * Wszystkie postaci danego usera, w kolejności utworzenia (jak front:
     * `order=created_at.asc`).
     *
     * @return Collection<int, Character>
     */
    public function forUser(string $userId): Collection
    {
        return Character::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get();
    }
}
