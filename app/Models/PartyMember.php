<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PartyMember extends Model
{
    use HasUuids;

    protected $table = 'party_members';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'party_id', 'character_id', 'character_name',
        'character_class', 'character_level', 'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'character_level' => 'integer',
            'joined_at' => 'datetime',
        ];
    }
}
