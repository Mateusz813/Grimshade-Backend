<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    use HasUuids;

    protected $table = 'parties';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'leader_id', 'name', 'description', 'password',
        'max_members', 'is_public', 'min_join_level',
    ];

    protected function casts(): array
    {
        return [
            'max_members' => 'integer',
            'is_public' => 'boolean',
            'min_join_level' => 'integer',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(PartyMember::class, 'party_id');
    }
}
