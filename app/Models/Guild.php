<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Guild extends Model
{
    use HasUuids;

    protected $table = 'guilds';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name', 'tag', 'logo', 'color', 'leader_id',
        'level', 'xp', 'boss_tier', 'member_cap', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'xp' => 'integer',
            'boss_tier' => 'integer',
            'member_cap' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
