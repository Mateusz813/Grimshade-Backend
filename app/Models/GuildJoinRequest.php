<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GuildJoinRequest extends Model
{
    use HasUuids;

    protected $table = 'guild_join_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

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
