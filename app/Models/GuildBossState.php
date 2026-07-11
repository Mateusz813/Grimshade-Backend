<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tygodniowy stan bossa gildii (`guild_boss_state`). Jeden wiersz per
 * (guild_id, week_start). Kształt: guildApi.ts (IGuildBossStateRow).
 *
 * Autorytatywne: HP/tier/killed liczy SERWER (GuildSystem::getGuildBossMaxHp +
 * computeGuildBossDamage). Klient nigdy nie ustawia HP.
 *
 * @property string $id
 * @property string $guild_id
 * @property string $week_start
 * @property int $boss_tier
 * @property int $boss_max_hp
 * @property int $boss_current_hp
 * @property bool $boss_killed
 */
class GuildBossState extends Model
{
    use HasUuids;

    protected $table = 'guild_boss_state';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // created_at/updated_at ustawiamy jawnie

    protected $fillable = [
        'guild_id', 'week_start', 'boss_tier', 'boss_max_hp', 'boss_current_hp',
        'boss_killed', 'current_attacker_id', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'boss_tier' => 'integer',
            'boss_max_hp' => 'integer',
            'boss_current_hp' => 'integer',
            'boss_killed' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
