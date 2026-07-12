<?php

declare(strict_types=1);

namespace App\Domain\Party;

use App\Domain\Support\Rng\RngInterface;

final class PartySystem
{
    public const MAX_PARTY_SIZE = 4;

    public const CLASS_PARTY_BUFFS = [
        'Cleric' => ['id' => 'cleric_heal', 'name' => 'Holy Light', 'sourceClass' => 'Cleric', 'effect' => 'heal', 'value' => 0.15, 'duration' => 3],
        'Bard' => ['id' => 'bard_atk', 'name' => 'Inspiring Melody', 'sourceClass' => 'Bard', 'effect' => 'atk_boost', 'value' => 0.10, 'duration' => 5],
        'Knight' => ['id' => 'knight_def', 'name' => 'Battle Cry', 'sourceClass' => 'Knight', 'effect' => 'def_boost', 'value' => 0.10, 'duration' => 5],
    ];

    public const AGGRO_CLASS_WEIGHTS = [
        'Knight' => 80,
        'Rogue' => 60,
        'Archer' => 50,
        'Necromancer' => 40,
        'Mage' => 30,
        'Cleric' => 20,
        'Bard' => 20,
    ];

    private const BOT_NAMES = [
        'Knight' => 'Bot Pancerny',
        'Cleric' => 'Bot Lecznik',
        'Archer' => 'Bot Łucznik',
        'Mage' => 'Bot Mag',
    ];

    private static function clampSize(int $partySize): int
    {
        return (int) max(1, min($partySize, self::MAX_PARTY_SIZE));
    }

    public static function calculateDropMultiplier(int $partySize): float
    {
        $size = self::clampSize($partySize);

        return 1 + ($size - 1) * 0.005;
    }

    public static function calculateXpMultiplier(int $partySize): float
    {
        $size = self::clampSize($partySize);

        return 1 + ($size - 1) * 0.065;
    }

    public static function calculateDifficultyMultiplier(int $partySize): float
    {
        $size = self::clampSize($partySize);

        return 1 + ($size - 1) * 0.2;
    }

    public static function canJoinParty(int $currentSize): bool
    {
        return $currentSize < self::MAX_PARTY_SIZE;
    }

    public static function isFull(array $members): bool
    {
        return count($members) >= self::MAX_PARTY_SIZE;
    }

    public static function getHumanCount(array $members): int
    {
        return count(array_filter($members, static fn (array $m): bool => empty($m['isBot'])));
    }

    public static function getBotCount(array $members): int
    {
        return count(array_filter($members, static fn (array $m): bool => ! empty($m['isBot'])));
    }

    public static function shouldSuggestBot(array $members): bool
    {
        return self::getHumanCount($members) < 2;
    }

    public static function createBotHelper(array $members): array
    {
        $classes = array_map(static fn (array $m): string => $m['class'], $members);

        $botClass = 'Knight';
        if (! in_array('Cleric', $classes, true)) {
            $botClass = 'Cleric';
        } elseif (! in_array('Knight', $classes, true)) {
            $botClass = 'Knight';
        } elseif (! in_array('Mage', $classes, true)) {
            $botClass = 'Mage';
        } else {
            $botClass = 'Archer';
        }

        $avgLevel = count($members) > 0
            ? (int) floor(array_sum(array_map(static fn (array $m) => $m['level'], $members)) / count($members))
            : 10;
        $hp = (int) max(100, $avgLevel * 20);

        return [
            'name' => self::BOT_NAMES[$botClass] ?? 'Bot '.$botClass,
            'class' => $botClass,
            'level' => $avgLevel,
            'hp' => $hp,
            'maxHp' => $hp,
            'isBot' => true,
            'isOnline' => true,
        ];
    }

    public static function getXpShare(int $totalXp, int $partySize): int
    {
        return (int) floor($totalXp / max(1, $partySize));
    }

    public static function getGoldShare(int $totalGold, int $partySize): int
    {
        return (int) floor($totalGold / max(1, $partySize));
    }

    public static function getPartySummary(array $members): array
    {
        $size = count($members);
        $avgLevel = $size > 0
            ? (int) floor(array_sum(array_map(static fn (array $m) => $m['level'], $members)) / $size)
            : 0;

        return [
            'totalMembers' => $size,
            'humanMembers' => self::getHumanCount($members),
            'botMembers' => self::getBotCount($members),
            'avgLevel' => $avgLevel,
            'dropMultiplier' => self::calculateDropMultiplier($size),
            'xpMultiplier' => self::calculateXpMultiplier($size),
            'difficultyMultiplier' => self::calculateDifficultyMultiplier($size),
        ];
    }

    public static function calculateHelpDamage(int|float $finishedMemberAttack, int|float $remainingMonsterHp): int
    {
        return (int) floor($finishedMemberAttack * 0.5);
    }

    public static function getPartyBuffs(array $memberClasses): array
    {
        $buffs = [];
        foreach ($memberClasses as $cls) {
            if (isset(self::CLASS_PARTY_BUFFS[$cls])) {
                $buffs[] = self::CLASS_PARTY_BUFFS[$cls];
            }
        }

        return $buffs;
    }

    public static function applyPartyBuffs(int|float $baseAttack, int|float $baseDefense, int|float $maxHp, array $buffs): array
    {
        $attack = $baseAttack;
        $defense = $baseDefense;
        $healPerRound = 0;

        foreach ($buffs as $buff) {
            switch ($buff['effect']) {
                case 'atk_boost':
                    $attack = (int) floor($attack * (1 + $buff['value']));
                    break;
                case 'def_boost':
                    $defense = (int) floor($defense * (1 + $buff['value']));
                    break;
                case 'heal':
                    $healPerRound = (int) floor($maxHp * $buff['value']);
                    break;
            }
        }

        return ['attack' => $attack, 'defense' => $defense, 'healPerRound' => $healPerRound];
    }

    public static function hasOptimalComposition(array $memberClasses): bool
    {
        return count(array_unique($memberClasses)) >= 3;
    }

    public static function getCompositionBonus(array $memberClasses): float
    {
        $unique = count(array_unique($memberClasses));
        if ($unique >= 4) {
            return 1.20;
        }
        if ($unique >= 3) {
            return 1.10;
        }

        return 1.0;
    }

    public static function getPartyGateLevel(int $myLevel, ?array $members): int
    {
        if ($members === null || count($members) === 0) {
            return $myLevel;
        }

        $humans = array_filter($members, static fn (array $m): bool => empty($m['isBot']));
        if (count($humans) === 0) {
            return $myLevel;
        }

        $lowest = INF;
        foreach ($humans as $m) {
            if ($m['level'] < $lowest) {
                $lowest = $m['level'];
            }
        }
        if (! is_finite($lowest)) {
            return $myLevel;
        }

        return (int) min($myLevel, $lowest);
    }

    public static function getPartyMaxUnlockedMonsterLevel(
        int $myMaxUnlockedLevel,
        ?array $members,
        array $presenceByMember,
        string $myCharacterId,
    ): int {
        $cap = $myMaxUnlockedLevel;
        if ($members === null || count($members) === 0) {
            return $cap;
        }

        foreach ($members as $m) {
            if (! empty($m['isBot'])) {
                continue;
            }
            if ($m['id'] === $myCharacterId) {
                continue;
            }
            $snap = $presenceByMember[$m['id']] ?? null;
            if ($snap === null || ! array_key_exists('maxUnlockedMonsterLevel', $snap)) {
                continue;
            }
            if ($snap['maxUnlockedMonsterLevel'] < $cap) {
                $cap = $snap['maxUnlockedMonsterLevel'];
            }
        }

        return $cap;
    }

    public static function getAggroWeight(string $cls): int
    {
        return self::AGGRO_CLASS_WEIGHTS[$cls] ?? 30;
    }

    public static function pickWeightedAggroTarget(RngInterface $rng, array $targets): ?string
    {
        if (count($targets) === 0) {
            return null;
        }

        $weights = array_map(static fn (array $t): int => self::getAggroWeight($t['class']), $targets);
        $total = array_sum($weights);
        if ($total <= 0) {
            return $targets[0]['id'] ?? null;
        }

        $roll = $rng->nextFloat() * $total;
        $count = count($targets);
        for ($i = 0; $i < $count; $i++) {
            $roll -= $weights[$i];
            if ($roll <= 0) {
                return $targets[$i]['id'];
            }
        }

        return $targets[$count - 1]['id'];
    }
}
