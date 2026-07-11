<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Wpis w globalnym feedzie śmierci (character_deaths). Kształt: deathsApi.ts.
 * character_name/class/level bierze SERWER z postaci (nie z body) — anty-fałsz.
 */
class Death extends Model
{
    use HasUuids;

    protected $table = 'character_deaths';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tabela ma tylko died_at

    protected $fillable = [
        'character_id', 'character_name', 'character_class', 'character_level',
        'source', 'source_name', 'source_level', 'result', 'died_at',
    ];

    protected function casts(): array
    {
        return [
            'character_level' => 'integer',
            'source_level' => 'integer',
            'died_at' => 'datetime',
        ];
    }
}
