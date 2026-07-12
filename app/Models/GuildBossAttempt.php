<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GuildBossAttempt extends Model
{
    use HasUuids;

    protected $table = 'guild_boss_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

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
