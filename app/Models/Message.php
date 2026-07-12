<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasUuids;

    protected $table = 'messages';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'channel', 'character_name', 'character_class',
        'character_level', 'content', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'character_level' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
