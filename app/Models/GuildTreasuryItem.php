<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GuildTreasuryItem extends Model
{
    use HasUuids;

    protected $table = 'guild_treasury_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

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
