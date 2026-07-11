<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Dzienna próba na bossa gildii (`guild_boss_attempts`). Jeden wiersz per
 * (guild_id, character_id, attempt_date); damage_dealt kumuluje obrażenia z
 * danego dnia. Kształt: guildApi.ts (IGuildBossAttemptRow).
 *
 * @property string $id
 * @property string $guild_id
 * @property string $character_id
 * @property string $attempt_date
 * @property int $damage_dealt
 */
class GuildBossAttempt extends Model
{
    use HasUuids;

    protected $table = 'guild_boss_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tylko created_at

    protected $fillable = [
        'guild_id', 'character_id', 'character_name', 'attempt_date',
        'damage_dealt', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'damage_dealt' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
