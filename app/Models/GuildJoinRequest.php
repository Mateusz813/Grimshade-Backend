<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Prośba o dołączenie do gildii (`guild_join_requests`). Kształt: guildApi.ts
 * (IGuildJoinRequestRow). Po zaakceptowaniu przez lidera znika (wraz z każdą
 * inną prośbą tej postaci — patrz purgeRequestsForCharacter).
 *
 * @property string $id
 * @property string $guild_id
 * @property string $character_id
 */
class GuildJoinRequest extends Model
{
    use HasUuids;

    protected $table = 'guild_join_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tylko requested_at

    protected $fillable = [
        'guild_id', 'character_id', 'character_name', 'character_class',
        'character_level', 'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'character_level' => 'integer',
            'requested_at' => 'datetime',
        ];
    }
}
