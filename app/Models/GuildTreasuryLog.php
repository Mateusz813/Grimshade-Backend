<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Log operacji skarbca gildii (`guild_treasury_logs`). Kształt: guildApi.ts
 * (IGuildTreasuryLogRow). Wpis deposit/withdraw z nazwą i snapshotem itemu.
 *
 * @property string $id
 * @property string $guild_id
 * @property string $action
 * @property string $character_id
 */
class GuildTreasuryLog extends Model
{
    use HasUuids;

    protected $table = 'guild_treasury_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tylko created_at

    protected $fillable = [
        'guild_id', 'action', 'character_id', 'character_name',
        'item_name', 'item_data', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
