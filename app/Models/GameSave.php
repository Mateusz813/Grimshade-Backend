<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Blob zapisu gry (`game_saves.state`, JSONB, 1 wiersz per postać).
 *
 * Tu żyje PRAWDZIWY stan ekonomii: `state.inventory.gold` (nie characters.gold!),
 * bag/deposit/equipment (itemy), consumables, stones, arenaPoints — plus wycinki
 * skills/quests/tasks/mastery/transforms/settings itd. (patrz characterScope.ts
 * STORE_ENTRIES na froncie).
 *
 * Docelowo (Faza 4 pełna) wycinki autorytatywne przechodzą do znormalizowanych
 * tabel; do tego czasu BACKEND jest właścicielem bloba: klient wysyła intencje,
 * serwer waliduje i mutuje blob (CharacterStateService). Po lockdownie klient
 * traci prawo zapisu do tej tabeli.
 */
class GameSave extends Model
{
    protected $table = 'game_saves';

    public $timestamps = false; // updated_at ustawiamy jawnie (front porównuje po nim)

    protected $fillable = ['user_id', 'character_id', 'state', 'updated_at'];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'updated_at' => 'datetime',
        ];
    }
}
