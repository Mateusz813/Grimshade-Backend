<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Character;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CharacterFactory extends Factory
{
    protected $model = Character::class;

    public function definition(): array
    {
        return [
            'user_id' => (string) Str::uuid(),
            'name' => $this->faker->unique()->firstName(),
            'class' => $this->faker->randomElement(
                ['Knight', 'Mage', 'Cleric', 'Archer', 'Rogue', 'Necromancer', 'Bard'],
            ),
            'level' => 1,
            'xp' => 0,
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 50,
            'max_mp' => 50,
            'attack' => 10,
            'defense' => 5,
            'attack_speed' => 1.0,
            'crit_chance' => 0.05,
            'crit_damage' => 1.5,
            'magic_level' => 1,
            'hp_regen' => 1.0,
            'mp_regen' => 1.0,
            'gold' => 0,
            'stat_points' => 0,
            'highest_level' => 1,
            'equipment' => [],
        ];
    }

    public function forUser(string $userId): static
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }
}
