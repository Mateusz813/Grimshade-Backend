<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Party co-op (`parties`). Kształt: src/api/v1/partyApi.ts (IPartyRow / IRawPartyRow).
 *
 * Wiersz jest autorytatywny: leader_id, pojemność, hasło (plain-text) i gate poziomu
 * trzyma serwer. Klient NIGDY nie mutuje tej tabeli bezpośrednio — tylko przez
 * intencje PartyController. `password` NIE wychodzi w odpowiedzi (snapshot zwraca
 * jedynie `has_password`).
 *
 * @property string $id
 * @property string $leader_id
 * @property string $name
 * @property string|null $description
 * @property string|null $password
 * @property int $max_members
 * @property bool $is_public
 * @property int $min_join_level
 */
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

    /** @return HasMany<PartyMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(PartyMember::class, 'party_id');
    }
}
