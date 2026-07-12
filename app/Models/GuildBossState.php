<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GuildBossState extends Model
{
    use HasUuids;

    protected $table = 'guild_boss_state';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'guild_id', 'week_start', 'boss_tier', 'boss_max_hp', 'boss_current_hp',
        'boss_killed', 'current_attacker_id', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'boss_tier' => 'integer',
            'boss_max_hp' => 'integer',
            'boss_current_hp' => 'integer',
            'boss_killed' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
