<?php

declare(strict_types=1);

namespace App\Domain\Combat;

use App\Domain\Loot\LootSystem;
use App\Domain\Progression\LevelSystem;
use App\Domain\Support\Rng\RngInterface;

final class HuntResolver
{
    private const MAX_ROUNDS = 500;

    public function __construct(private readonly RngInterface $rng) {}

    public function resolve(array $character, array $monster): array
    {
        $rarity = LootSystem::rollMonsterRarity($this->rng, false, null);
        $stats = CombatMath::applyMonsterRarity([
            'hp' => $monster['hp'],
            'attack' => $monster['attack'],
            'attack_min' => $monster['attack_min'] ?? null,
            'attack_max' => $monster['attack_max'] ?? null,
            'defense' => $monster['defense'],
            'xp' => $monster['xp'],
            'gold' => $monster['gold'],
        ], $rarity);

        $monsterHp = $stats['hp'];
        $playerHp = $character['hp'] > 0 ? (int) $character['hp'] : (int) $character['max_hp'];
        $range = CombatMath::getMonsterAttackRange($stats);
        $rounds = 0;
        $won = false;

        while ($rounds < self::MAX_ROUNDS) {
            $rounds++;

            $isCrit = $this->rng->nextFloat() < min($character['crit_chance'], 0.5);
            $hit = CombatMath::calculateDamage([
                'baseAtk' => $character['attack'],
                'weaponAtk' => 0,
                'skillBonus' => 0,
                'classModifier' => 1,
                'enemyDefense' => $stats['defense'],
                'isCrit' => $isCrit,
                'critDmg' => $character['crit_damage'],
            ]);
            $monsterHp -= $hit['finalDamage'];
            if ($monsterHp <= 0) {
                $won = true;
                break;
            }

            $monsterRoll = $range['min'] + (int) floor($this->rng->nextFloat() * ($range['max'] - $range['min'] + 1));
            $playerHp -= (int) max(1, $monsterRoll - $character['defense']);
            if ($playerHp <= 0) {
                $playerHp = 0;
                break;
            }
        }

        if (! $won) {
            return [
                'won' => false,
                'rounds' => $rounds,
                'monsterRarity' => $rarity,
                'playerHp' => max(0, $playerHp),
            ];
        }

        $gold = LootSystem::calculateGoldDrop($this->rng, [$stats['goldMin'], $stats['goldMax']], 1);
        $xp = (int) $stats['xp'];
        $level = LevelSystem::processXpGain((int) $character['level'], (int) $character['xp'], $xp);

        return [
            'won' => true,
            'rounds' => $rounds,
            'monsterRarity' => $rarity,
            'xpGained' => $xp,
            'goldGained' => $gold,
            'newLevel' => $level['newLevel'],
            'remainingXp' => $level['remainingXp'],
            'levelsGained' => $level['levelsGained'],
            'statPointsGained' => $level['statPointsGained'],
            'playerHp' => max(0, $playerHp),
        ];
    }
}
