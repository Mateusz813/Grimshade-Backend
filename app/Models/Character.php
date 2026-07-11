<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CharacterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Postać gracza. Kanoniczny kształt: src/types/character.ts (ICharacter).
 *
 * UWAGA: klucz to UUID (string), nie auto-increment. Autorytatywne zapisy
 * (gold/level/loot) NIGDY nie przychodzą z body — liczy je serwer i zapisuje
 * przez dedykowane serwisy. Ten model to na razie odczyt + tworzenie postaci.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $class
 */
class Character extends Model
{
    /** @use HasFactory<CharacterFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'characters';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Kolumny masowo przypisywalne przy tworzeniu postaci. Statystyki bazowe
     * ustawia serwer z katalogu klas (nie ufamy wartościom z body poza name/class).
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'name', 'class',
        'level', 'xp', 'hp', 'max_hp', 'mp', 'max_mp', 'attack', 'defense',
        'attack_speed', 'crit_chance', 'crit_damage', 'magic_level',
        'hp_regen', 'mp_regen', 'gold', 'stat_points', 'highest_level', 'equipment',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'xp' => 'integer',
            'hp' => 'integer',
            'max_hp' => 'integer',
            'mp' => 'integer',
            'max_mp' => 'integer',
            'attack' => 'integer',
            'defense' => 'integer',
            'attack_speed' => 'float',
            'crit_chance' => 'float',
            'crit_damage' => 'float',
            'magic_level' => 'integer',
            'hp_regen' => 'float',
            'mp_regen' => 'float',
            'gold' => 'integer',
            'stat_points' => 'integer',
            'highest_level' => 'integer',
            'equipment' => 'array',
            'arena_kills' => 'integer',
            'arena_deaths' => 'integer',
            'arena_league_points' => 'integer',
            'mastery_points' => 'integer',
            'quests_oneshot_done' => 'integer',
            'quests_daily_done' => 'integer',
            'market_items_sold' => 'integer',
            'market_items_bought' => 'integer',
            'item_upgrades_done' => 'integer',
            'skill_upgrades_done' => 'integer',
            'best_dps5_solo' => 'integer',
            'best_dps5_party' => 'integer',
            'market_gold_earned' => 'integer',
            'market_gold_spent' => 'integer',
        ];
    }
}
