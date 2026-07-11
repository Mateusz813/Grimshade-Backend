<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tygodniowy wkład postaci w zabicie bossa (`guild_boss_contributions`). Jeden
 * wiersz per (guild_id, character_id, week_start); total_damage kumuluje wszystkie
 * obrażenia w tygodniu i steruje mnożnikiem nagrody (contributionMultiplier).
 * Kształt: guildApi.ts (IGuildBossContributionRow).
 *
 * @property string $id
 * @property string $guild_id
 * @property string $character_id
 * @property string $week_start
 * @property int $total_damage
 * @property bool $rewards_claimed
 */
class GuildBossContribution extends Model
{
    use HasUuids;

    protected $table = 'guild_boss_contributions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tylko updated_at

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
