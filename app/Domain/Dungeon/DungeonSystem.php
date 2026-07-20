<?php

declare(strict_types=1);

namespace App\Domain\Dungeon;

use App\Domain\Combat\CombatMath;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Support\Rng\RngInterface;
use DateTimeImmutable;

final class DungeonSystem
{
    public const DUNGEON_RARITY_ORDER = ['common', 'rare', 'epic', 'legendary', 'mythic', 'heroic'];

    private const RARITY_WEIGHTS = [50, 25, 15, 7, 2.5, 0.5];

    public const DUNGEON_MONSTER_TYPE_MULTIPLIERS = [
        'Normal' => ['hp' => 1.0, 'atk' => 1.0, 'def' => 1.0],
        'Strong' => ['hp' => 1.5, 'atk' => 1.3, 'def' => 1.2],
        'Epic' => ['hp' => 2.0, 'atk' => 1.5, 'def' => 1.3],
        'Legendary' => ['hp' => 3.0, 'atk' => 1.8, 'def' => 1.5],
        'Boss' => ['hp' => 5.0, 'atk' => 2.5, 'def' => 2.0],
    ];

    private const TYPE_ORDER = ['Normal', 'Strong', 'Epic', 'Legendary', 'Boss'];

    public static function getDungeonMinLevel(array $dungeon): int
    {
        return (int) ($dungeon['minLevel'] ?? $dungeon['level']);
    }

    public static function getDungeonWaves(array $dungeon): int
    {
        return (int) ($dungeon['waves'] ?? max(3, min(10, (int) floor($dungeon['level'] / 15) + 3)));
    }

    public static function getDungeonCooldown(array $dungeon): int
    {
        $dailyAttempts = $dungeon['dailyAttempts'] ?? 0;

        return (int) ($dungeon['cooldown'] ?? ($dailyAttempts ? (int) floor(86400 / $dailyAttempts) : 17280));
    }

    public static function getDungeonRewardGold(array $dungeon): array
    {
        if (isset($dungeon['rewardGold'])) {
            return [(int) $dungeon['rewardGold'][0], (int) $dungeon['rewardGold'][1]];
        }

        return [(int) $dungeon['level'] * 10, (int) $dungeon['level'] * 25];
    }

    public static function getDungeonRewardXp(array $dungeon): int
    {
        return (int) ($dungeon['rewardXp'] ?? $dungeon['level'] * 50);
    }

    public static function canEnterDungeon(
        array $dungeon,
        int $characterLevel,
        ?string $lastCompletedAt,
        int $nowMs,
    ): bool {
        if ($characterLevel < self::getDungeonMinLevel($dungeon)) {
            return false;
        }
        if ($lastCompletedAt === null) {
            return true;
        }
        $elapsed = $nowMs - self::isoToMs($lastCompletedAt);

        return $elapsed >= self::getDungeonCooldown($dungeon) * 1000;
    }

    public static function getDungeonRemainingMs(array $dungeon, ?string $lastCompletedAt, int $nowMs): int
    {
        if ($lastCompletedAt === null) {
            return 0;
        }
        $elapsed = $nowMs - self::isoToMs($lastCompletedAt);

        return (int) max(0, self::getDungeonCooldown($dungeon) * 1000 - $elapsed);
    }

    public static function formatCooldown(int $ms): string
    {
        $totalSec = (int) ceil($ms / 1000);
        if ($totalSec < 60) {
            return "{$totalSec}s";
        }
        if ($totalSec < 3600) {
            return (int) floor($totalSec / 60).'m '.($totalSec % 60).'s';
        }

        return (int) floor($totalSec / 3600).'h '.(int) floor(($totalSec % 3600) / 60).'m';
    }

    public static function getFinalWaveMonsterType(int $dungeonLevel): string
    {
        if ($dungeonLevel <= 8) {
            return 'Epic';
        }
        if ($dungeonLevel <= 18) {
            return 'Legendary';
        }

        return 'Boss';
    }

    public static function getMidWaveMonsterType(int $dungeonLevel, int $wave, int $totalWaves): string
    {
        if ($dungeonLevel < 20) {
            return 'Normal';
        }
        if ($wave === $totalWaves - 2 && $totalWaves >= 4) {
            return 'Legendary';
        }
        if ($wave > 0 && $wave % 2 === 0) {
            return 'Strong';
        }

        return 'Normal';
    }

    public static function getWaveMonsterType(int $wave, int $totalWaves, int $dungeonLevel): string
    {
        $isBossWave = $wave === $totalWaves - 1;
        if ($isBossWave) {
            return self::getFinalWaveMonsterType($dungeonLevel);
        }

        return self::getMidWaveMonsterType($dungeonLevel, $wave, $totalWaves);
    }

    public static function getWaveMonsterCount(int $dungeonLevel, int $wave, int $totalWaves): int
    {
        $isBossWave = $wave === $totalWaves - 1;
        if ($isBossWave) {
            return $dungeonLevel >= 30 ? 4 : 3;
        }

        $waveProgress = $wave / max(1, $totalWaves - 1);
        $count = 1 + (int) floor($waveProgress * 2);
        if ($dungeonLevel >= 30 && $wave > 0) {
            $count += 1;
        }

        return (int) max(1, min(4, $count));
    }

    public static function getWaveComposition(int $dungeonLevel, int $wave, int $totalWaves): array
    {
        $lead = self::getWaveMonsterType($wave, $totalWaves, $dungeonLevel);
        $count = self::getWaveMonsterCount($dungeonLevel, $wave, $totalWaves);
        if ($count <= 1) {
            return [$lead];
        }

        $out = [$lead];

        if ($dungeonLevel >= 800) {
            while (count($out) < $count) {
                $out[] = $lead;
            }

            return $out;
        }

        if ($dungeonLevel <= 14) {
            $current = $lead;
            while (count($out) < $count) {
                $current = self::stepDownType($current);
                $out[] = $current;
            }

            return $out;
        }

        $filler = self::stepDownType($lead);
        while (count($out) < $count) {
            $out[] = $filler;
        }

        return $out;
    }

    public static function pickWaveMonster(array $dungeon, array $allMonsters, int $wave, int $totalWaves): array
    {
        $isBossWave = $wave === $totalWaves - 1;

        if ($isBossWave && ! empty($dungeon['bossMonster'])) {
            foreach ($allMonsters as $m) {
                if ($m['id'] === $dungeon['bossMonster']) {
                    return $m;
                }
            }
        }
        if (! $isBossWave && ! empty($dungeon['monsters'])) {
            $monsters = $dungeon['monsters'];
            $monsterId = $monsters[$wave % count($monsters)];
            foreach ($allMonsters as $m) {
                if ($m['id'] === $monsterId) {
                    return $m;
                }
            }
        }

        $dungeonLevel = self::getDungeonMinLevel($dungeon);
        $sorted = self::sortByLevelDistance($allMonsters, $dungeonLevel);
        $offset = $isBossWave ? min(2, count($sorted) - 1) : 0;

        return $sorted[$offset] ?? $allMonsters[0];
    }

    public static function pickWaveMonsters(array $dungeon, array $allMonsters, int $wave, int $totalWaves): array
    {
        $dLvl = self::getDungeonMinLevel($dungeon);
        $count = self::getWaveMonsterCount($dLvl, $wave, $totalWaves);
        $lead = self::pickWaveMonster($dungeon, $allMonsters, $wave, $totalWaves);
        if ($count <= 1) {
            return [$lead];
        }

        $pool = array_values(array_filter($allMonsters, static fn (array $m): bool => $m['id'] !== $lead['id']));
        $pool = self::sortByLevelDistance($pool, $dLvl);

        $result = [$lead];
        for ($i = 1; $i < $count; $i++) {
            $result[] = $pool[($i - 1) % max(1, count($pool))] ?? $lead;
        }

        return $result;
    }

    public static function scaleDungeonMonster(array $monster, int $wave, int $totalWaves, ?int $dungeonLevel = null): array
    {
        $dLvl = $dungeonLevel ?? (int) $monster['level'];
        $waveProgress = $wave / max(1, $totalWaves - 1);

        if ($dLvl <= 8) {
            $hpScale = 0.8 + $waveProgress * 0.2;
            $atkScale = 0.7 + $waveProgress * 0.2;
            $defScale = 0.7 + $waveProgress * 0.2;
        } elseif ($dLvl <= 18) {
            $hpScale = 1.0 + $waveProgress * 0.2;
            $atkScale = 0.9 + $waveProgress * 0.2;
            $defScale = 0.9 + $waveProgress * 0.2;
        } else {
            $levelBonus = min(1.0, ($dLvl - 20) / 200);
            $baseScale = 1.2 + $levelBonus * 0.5;
            $hpScale = $baseScale + $waveProgress * (0.3 + $levelBonus * 0.5);
            $atkScale = (1.1 + $levelBonus * 0.4) + $waveProgress * (0.3 + $levelBonus * 0.4);
            $defScale = $baseScale + $waveProgress * (0.2 + $levelBonus * 0.3);
        }

        $monsterType = self::getWaveMonsterType($wave, $totalWaves, $dLvl);
        $typeMult = self::DUNGEON_MONSTER_TYPE_MULTIPLIERS[$monsterType];
        $hpScale *= $typeMult['hp'];
        $atkScale *= $typeMult['atk'];
        $defScale *= $typeMult['def'];

        return array_merge($monster, [
            'hp' => (int) max(1, floor($monster['hp'] * $hpScale)),
            'attack' => (int) max(1, floor($monster['attack'] * $atkScale)),
            'defense' => (int) max(0, floor($monster['defense'] * $defScale)),
        ]);
    }

    public static function scaleDungeonMonsterAsType(
        array $monster,
        int $wave,
        int $totalWaves,
        int $dungeonLevel,
        string $asType,
    ): array {
        $leadScaled = self::scaleDungeonMonster($monster, $wave, $totalWaves, $dungeonLevel);
        $leadType = self::getWaveMonsterType($wave, $totalWaves, $dungeonLevel);
        if ($asType === $leadType) {
            return $leadScaled;
        }

        $leadMult = self::DUNGEON_MONSTER_TYPE_MULTIPLIERS[$leadType];
        $newMult = self::DUNGEON_MONSTER_TYPE_MULTIPLIERS[$asType];

        return array_merge($leadScaled, [
            'hp' => (int) max(1, floor($leadScaled['hp'] * ($newMult['hp'] / $leadMult['hp']))),
            'attack' => (int) max(1, floor($leadScaled['attack'] * ($newMult['atk'] / $leadMult['atk']))),
            'defense' => (int) max(0, floor($leadScaled['defense'] * ($newMult['def'] / $leadMult['def']))),
        ]);
    }

    public static function resolveWave(
        int $playerHp,
        int $playerAtk,
        int $playerDef,
        int $playerLevel,
        int $monsterHp,
        int $monsterAtk,
        ?int $monsterDef = null,
        ?int $monsterLevel = null,
    ): array {
        $pHp = $playerHp;
        $mHp = $monsterHp;
        $pDmg = CombatMath::mitigateDamage($playerAtk, $monsterDef ?? 0, $playerLevel, true);
        $mDmg = CombatMath::mitigateDamage($monsterAtk, $playerDef, $monsterLevel ?? 1);

        for ($i = 0; $i < 10000; $i++) {
            $mHp -= $pDmg;
            if ($mHp <= 0) {
                break;
            }
            $pHp -= $mDmg;
            if ($pHp <= 0) {
                break;
            }
        }

        return ['playerHpLeft' => (int) max(0, $pHp), 'won' => $pHp > 0];
    }

    public static function rollDungeonRarity(RngInterface $rng, string $maxRarity): string
    {
        $maxIdx = array_search($maxRarity, self::DUNGEON_RARITY_ORDER, true);
        $maxIdx = $maxIdx === false ? -1 : $maxIdx;
        $weights = array_slice(self::RARITY_WEIGHTS, 0, $maxIdx + 1);
        $total = array_sum($weights);
        $rand = $rng->nextFloat() * $total;
        for ($i = 0; $i < count($weights); $i++) {
            $rand -= $weights[$i];
            if ($rand <= 0) {
                return self::DUNGEON_RARITY_ORDER[$i];
            }
        }

        return self::DUNGEON_RARITY_ORDER[$maxIdx];
    }

    public static function rollDungeonGold(RngInterface $rng, array $range): int
    {
        return $range[0] + (int) floor($rng->nextFloat() * ($range[1] - $range[0] + 1));
    }

    public static function rollDungeonItemDrop(
        RngInterface $rng,
        ItemGenerator $items,
        array $dungeon,
        bool $isBossWave,
    ): ?array {
        $dropChance = $isBossWave ? 0.7 : 0.15;
        if ($rng->nextFloat() > $dropChance) {
            return null;
        }

        $rarity = self::rollDungeonRarity($rng, $dungeon['maxRarity'] ?? 'common');
        $dungeonLevel = self::getDungeonMinLevel($dungeon);

        $item = $items->generateRandomItem($dungeonLevel, $rarity);
        if ($item === null) {
            return null;
        }

        return [
            'itemId' => $item['itemId'],
            'rarity' => $item['rarity'],
            'bonuses' => $item['bonuses'],
            'itemLevel' => $dungeonLevel,
        ];
    }

    public static function resolveDungeon(
        array $dungeon,
        array $character,
        array $allMonsters,
        RngInterface $rng,
        ItemGenerator $items,
    ): array {
        $totalWaves = self::getDungeonWaves($dungeon);
        $playerHp = (int) $character['max_hp'];
        $totalXp = self::getDungeonRewardXp($dungeon);
        $waveResults = [];
        $itemsOut = [];

        for ($w = 0; $w < $totalWaves; $w++) {
            $isBossWave = $w === $totalWaves - 1;
            $raw = self::pickWaveMonster($dungeon, $allMonsters, $w, $totalWaves);
            $monster = self::scaleDungeonMonster($raw, $w, $totalWaves, self::getDungeonMinLevel($dungeon));

            $res = self::resolveWave(
                $playerHp,
                (int) $character['attack'], (int) $character['defense'], (int) $character['level'],
                (int) $monster['hp'], (int) $monster['attack'], (int) $monster['defense'], (int) $monster['level'],
            );

            $playerHp = $res['playerHpLeft'];
            $totalXp += (int) floor($raw['xp'] * (1 + $w * 0.05));

            $drop = self::rollDungeonItemDrop($rng, $items, $dungeon, $isBossWave);
            if ($drop !== null) {
                $itemsOut[] = $drop;
            }

            $waveResults[] = [
                'wave' => $w,
                'monsterName' => $raw['name_pl'],
                'monsterSprite' => $raw['sprite'],
                'isBossWave' => $isBossWave,
                'playerHpAfter' => $playerHp,
                'won' => $res['won'],
            ];

            if (! $res['won']) {
                return [
                    'waveResults' => $waveResults,
                    'result' => [
                        'success' => false,
                        'wavesCleared' => $w,
                        'playerHpLeft' => 0,
                        'gold' => 0,
                        'xp' => 0,
                        'items' => [],
                    ],
                ];
            }
        }

        $gold = self::rollDungeonGold($rng, self::getDungeonRewardGold($dungeon));

        return [
            'waveResults' => $waveResults,
            'result' => [
                'success' => true,
                'wavesCleared' => $totalWaves,
                'playerHpLeft' => $playerHp,
                'gold' => $gold,
                'xp' => $totalXp,
                'items' => $itemsOut,
            ],
        ];
    }

    public static function estimateDungeonRewards(array $dungeon, array $allMonsters, array $monstersRawData): array
    {
        $multiplier = 4;
        $totalWaves = self::getDungeonWaves($dungeon);
        $totalXpEst = 0;
        $totalGoldMin = 0;
        $totalGoldMax = 0;

        for ($w = 0; $w < $totalWaves; $w++) {
            $slots = self::pickWaveMonsters($dungeon, $allMonsters, $w, $totalWaves);
            foreach ($slots as $monster) {
                $totalXpEst += $monster['xp'];
                $rawGold = null;
                foreach ($monstersRawData as $m) {
                    if ($m['id'] === $monster['id']) {
                        $rawGold = $m['gold'];
                        break;
                    }
                }
                if ($rawGold !== null) {
                    $totalGoldMin += $rawGold[0];
                    $totalGoldMax += $rawGold[1];
                }
            }
        }

        $lvl = $dungeon['level'] ?? 1;
        $xpBonus = $lvl * $lvl;
        $goldBonus = $lvl * 1000;

        return [
            'goldMin' => (int) ($totalGoldMin * $multiplier + $goldBonus),
            'goldMax' => (int) ($totalGoldMax * $multiplier + $goldBonus),
            'xp' => (int) ($totalXpEst * $multiplier + $xpBonus),
        ];
    }

    private static function stepDownType(string $type): string
    {
        $idx = array_search($type, self::TYPE_ORDER, true);
        if ($idx === false || $idx <= 0) {
            return 'Normal';
        }

        return self::TYPE_ORDER[$idx - 1];
    }

    private static function sortByLevelDistance(array $monsters, int $dungeonLevel): array
    {
        $sorted = array_values($monsters);
        usort(
            $sorted,
            static fn (array $a, array $b): int => abs($a['level'] - $dungeonLevel) <=> abs($b['level'] - $dungeonLevel),
        );

        return $sorted;
    }

    private static function isoToMs(string $iso): int
    {
        return (int) (new DateTimeImmutable($iso))->format('Uv');
    }
}
