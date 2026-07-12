<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GuildBossContribution extends Model
{
    use HasUuids;

    protected $table = 'guild_boss_contributions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'guild_id', 'character_id', 'week_start', 'total_damage',
        'rewards_claimed', 'rewards_json', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'total_damage' => 'integer',
            'rewards_claimed' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}
