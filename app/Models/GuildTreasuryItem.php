<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Przedmiot w skarbcu gildii (`guild_treasury_items`). Kształt: guildApi.ts
 * (IGuildTreasuryItemRow). Autorytatywny escrow: item schodzi z bloba game_saves
 * (bag) i ląduje TU; przy wypłacie wraca do bag odbiorcy — wszystko w transakcji.
 *
 * @property string $id
 * @property string $guild_id
 * @property string $item_data
 * @property string $deposited_by
 */
class GuildTreasuryItem extends Model
{
    use HasUuids;

    protected $table = 'guild_treasury_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tylko deposited_at

    protected $fillable = [
        'guild_id', 'item_data', 'deposited_by', 'deposited_by_name', 'deposited_at',
    ];

    protected function casts(): array
    {
        return [
            'deposited_at' => 'datetime',
        ];
    }
}
