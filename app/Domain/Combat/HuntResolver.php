<?php

declare(strict_types=1);

namespace App\Domain\Combat;

use App\Domain\Loot\LootSystem;
use App\Domain\Progression\LevelSystem;
use App\Domain\Support\Rng\RngInterface;

/**
 * Autorytatywne rozstrzygnięcie pojedynczej walki solo-hunt. Czyste (bierze
 * staty postaci + potwora + RngInterface), więc testowalne deterministycznie
 * (seeded Mulberry32Rng) i wolne od Eloquent.
 *
 * Sedno anty-cheatu: WYNIK i NAGRODY liczy serwer z danych postaci + treści +
 * serwerowego RNG. Klient nie przysyła XP/gold/lvl — nic z body nie jest tu ufane.
 *
 * ⚠️ To UPROSZCZONY model walki (pierwsza wersja autorytatywna): tura gracz→potwór,
 * mitygacja obrażeń = max(1, dmg - obrona). Pełny, parytetowy port `combatEngine.ts`
 * (animacje/skille/party) przyjdzie później jako rozbudowa tego resolvera.
 */
final class HuntResolver
{
    private const MAX_ROUNDS = 500;

    public function __construct(private readonly RngInterface $rng) {}

    /**
     * @param  array{attack:int|float, defense:int|float, hp:int|float, max_hp:int|float, crit_chance:float, crit_damage:float, level:int, xp:int}  $character
     * @param  array{level:int, hp:int|float, attack:int|float, defense:int|float, xp:int|float, gold:array{0:int|float,1:int|float}, attack_min?:int|float, attack_max?:int|float}  $monster
     * @return array<string, mixed>
     */
    public function resolve(array $character, array $monster): array
    {
        // 1) Wariant rzadkości potwora (serwerowy roll).
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

        // 2) Symulacja.
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
                'isBlocked' => false,
                'isDodged' => false,
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

        // 3) Nagrody — serwerowe.
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
