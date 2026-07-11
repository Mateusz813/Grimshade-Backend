<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Członek party (`party_members`). Kształt: src/api/v1/partyApi.ts (IPartyMemberRow).
 *
 * Snapshot tożsamości postaci (name/class/level) kopiowany z wiersza `characters`
 * w momencie dołączenia — front renderuje roster bez dociągania każdej postaci.
 * UNIQUE(character_id) wymusza: postać w co najwyżej jednym party.
 *
 * @property string $id
 * @property string $party_id
 * @property string $character_id
 * @property string $character_name
 * @property string $character_class
 * @property int $character_level
 * @property string|null $role
 */
class PartyMember extends Model
{
    use HasUuids;

    protected $table = 'party_members';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tabela ma tylko joined_at (ustawiamy jawnie)

    protected $fillable = [
        'party_id', 'character_id', 'character_name',
        'character_class', 'character_level', 'role', 'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'character_level' => 'integer',
            'joined_at' => 'datetime',
        ];
    }
}
