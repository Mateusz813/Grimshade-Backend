<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameSave extends Model
{
    protected $table = 'game_saves';

    public $timestamps = false;

    protected $fillable = ['user_id', 'character_id', 'state', 'updated_at'];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'updated_at' => 'datetime',
        ];
    }
}
