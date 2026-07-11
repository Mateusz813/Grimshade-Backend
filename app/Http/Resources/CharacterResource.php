<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kształt odpowiedzi = ICharacter z frontu (src/types/character.ts).
 * snake_case, wszystkie kolumny rankingowe — żeby store'y/widoki frontu
 * nie musiały się zmieniać po repoincie.
 *
 * @mixin Character
 */
final class CharacterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'class' => $this->class,
            'level' => $this->level,
            'xp' => $this->xp,
            'hp' => $this->hp,
            'max_hp' => $this->max_hp,
            'mp' => $this->mp,
            'max_mp' => $this->max_mp,
            'attack' => $this->attack,
            'defense' => $this->defense,
            'attack_speed' => $this->attack_speed,
            'crit_chance' => $this->crit_chance,
            'crit_damage' => $this->crit_damage,
            'magic_level' => $this->magic_level,
            'hp_regen' => $this->hp_regen,
            'mp_regen' => $this->mp_regen,
            'gold' => $this->gold,
            'stat_points' => $this->stat_points,
            'highest_level' => $this->highest_level,
            'equipment' => $this->equipment ?? [],
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),

            // Liczniki rankingowe
            'arena_kills' => $this->arena_kills,
            'arena_deaths' => $this->arena_deaths,
            'arena_league' => $this->arena_league,
            'arena_league_points' => $this->arena_league_points,
            'mastery_points' => $this->mastery_points,
            'quests_oneshot_done' => $this->quests_oneshot_done,
            'quests_daily_done' => $this->quests_daily_done,
            'market_items_sold' => $this->market_items_sold,
            'market_items_bought' => $this->market_items_bought,
            'item_upgrades_done' => $this->item_upgrades_done,
            'skill_upgrades_done' => $this->skill_upgrades_done,
            'best_dps5_solo' => $this->best_dps5_solo,
            'best_dps5_party' => $this->best_dps5_party,
            'market_gold_earned' => $this->market_gold_earned,
            'market_gold_spent' => $this->market_gold_spent,
            'best_dps5_party_composition' => $this->best_dps5_party_composition,
        ];
    }
}
