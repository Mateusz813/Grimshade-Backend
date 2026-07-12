<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GuildMember extends Model
{
    use HasUuids;

    protected $table = 'guild_members';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'guild_id', 'character_id', 'character_name', 'character_class',
        'character_level', 'character_transform_tier', 'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'character_level' => 'integer',
            'character_transform_tier' => 'integer',
            'joined_at' => 'datetime',
        ];
    }
}
