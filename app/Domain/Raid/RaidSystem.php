<?php

declare(strict_types=1);

namespace App\Domain\Raid;

use App\Domain\Support\Rng\RngInterface;

final class RaidSystem
{
    public const RAID_REWARD_MULTIPLIER = 12;

    public const SPELL_CHEST_CHANCE_PER_LEVEL = 0.0025;

    private const BOSS_HP = 8.0;

    private const BOSS_ATK = 5.0;

    private const BOSS_DEF = 2.0;

    private const BOSS_XP = 10.0;

    private const BOSS_GOLD = 15.0;

    public const ITEM_RARITY_CHANCES = [
        ['heroic', 0.005],
        ['mythic', 0.05],
        ['legendary', 0.10],
        ['epic', 0.20],
        ['rare', 0.50],
        ['common', 0.145],
    ];

    public const STONE_DROPS = [
        ['heroic', 0.01, 'heroic_stone'],
        ['mythic', 0.15, 'mythic_stone'],
        ['legendary', 0.25, 'legendary_stone'],
        ['epic', 0.40, 'epic_stone'],
        ['rare', 0.10, 'rare_stone'],
        ['common', 0.09, 'common_stone'],
    ];

    public const COMPLETION_ROLL = [
        ['heroic', 0.015],
        ['mythic', 0.08],
        ['legendary', 0.15],
        ['epic', 0.25],
        ['rare', 0.40],
        ['common', 0.105],
    ];

    public const SPELL_CHEST_LEVELS = [5, 10, 20, 30, 40, 50, 60, 70, 80, 100, 150, 300, 600, 800, 1000];

    private array $dungeons;

    private array $monsters;

    public function __construct(array $dungeons, array $monsters)
    {
        $this->dungeons = array_values($dungeons);
        $this->monsters = array_values($monsters);
    }

    public static function getRaidWaveCount(int $raidLevel): int
    {
        if ($raidLevel <= 10) {
            return 1;
        }
        if ($raidLevel <= 50) {
            return 2;
        }
        if ($raidLevel <= 200) {
            return 3;
        }
        if ($raidLevel <= 500) {
            return 4;
        }

        return 5;
    }

    public function getAllRaids(): array
    {
        $raids = [];
        foreach ($this->dungeons as $d) {
            $level = (int) $d['level'];
            $raids[] = [
                'id' => 'raid_'.self::stripDungeonPrefix((string) $d['id']),
                'name_pl' => $d['name_pl'],
                'level' => $level,
                'waves' => self::getRaidWaveCount($level),
                'dailyAttempts' => 5,
                'sourceDungeonId' => $d['id'],
            ];
        }

        return $raids;
    }

    public function getRaidById(string $id): ?array
    {
        foreach ($this->getAllRaids() as $raid) {
            if ($raid['id'] === $id) {
                return $raid;
            }
        }

        return null;
    }

    public function estimateRaidRewards(array $raid): array
    {
        $level = (int) $raid['level'];
        $base = $this->pickBaseRaidMonster($level);
        $totalBosses = (int) $raid['waves'] * 4;
        $factor = $totalBosses * self::RAID_REWARD_MULTIPLIER;
        $xpPerKill = (int) floor($base['xp'] * self::BOSS_XP);
        $goldMinPerKill = (int) floor($base['gold'][0] * self::BOSS_GOLD);
        $goldMaxPerKill = (int) floor($base['gold'][1] * self::BOSS_GOLD);
        $xpBonus = self::levelXpBonus($level);
        $goldBonus = self::levelGoldBonus($level);

        return [
            'goldMin' => $goldMinPerKill * $factor + $goldBonus,
            'goldMax' => $goldMaxPerKill * $factor + $goldBonus,
            'xp' => $xpPerKill * $factor + $xpBonus,
        ];
    }

    public function generateWaveBosses(array $raid, int $waveIdx): array
    {
        $level = (int) $raid['level'];
        $base = $this->pickBaseRaidMonster($level);
        $levelGap = max(1, $level - (int) $base['level']);
        $mult = (1 + $levelGap * 0.05) * (1 + $waveIdx * 0.15);

        $bosses = [];
        for ($slotIdx = 0; $slotIdx < 4; $slotIdx++) {
            $hp = (int) floor($base['hp'] * self::BOSS_HP * $mult);
            $bosses[] = [
                'baseId' => $base['id'],
                'level' => (int) $base['level'],
                'name' => $base['name_pl'].' #'.($slotIdx + 1),
                'sprite' => $base['sprite'],
                'maxHp' => $hp,
                'currentHp' => $hp,
                'attack' => (int) floor($base['attack'] * self::BOSS_ATK * $mult),
                'defense' => (int) floor($base['defense'] * self::BOSS_DEF * $mult),
                'isDead' => false,
                'waveIdx' => $waveIdx,
                'slotIdx' => $slotIdx,
            ];
        }

        return $bosses;
    }

    public function computeMemberRewards(array $raid, int $bossesDefeated): array
    {
        $level = (int) $raid['level'];
        $base = $this->pickBaseRaidMonster($level);
        $xpPerKill = (int) floor($base['xp'] * self::BOSS_XP);
        $goldMidPerKill = (int) floor((($base['gold'][0] + $base['gold'][1]) / 2) * self::BOSS_GOLD);
        $totalSlots = max(1, (int) $raid['waves'] * 4);
        $cleared = $bossesDefeated >= $totalSlots;
        $xpBonus = $cleared ? self::levelXpBonus($level) : 0;
        $goldBonus = $cleared ? self::levelGoldBonus($level) : 0;

        return [
            'xp' => $xpPerKill * $bossesDefeated * self::RAID_REWARD_MULTIPLIER + $xpBonus,
            'gold' => $goldMidPerKill * $bossesDefeated * self::RAID_REWARD_MULTIPLIER + $goldBonus,
        ];
    }

    public static function selectItemRarity(float $roll): ?string
    {
        $cum = 0.0;
        foreach (self::ITEM_RARITY_CHANCES as [$rarity, $chance]) {
            $cum += $chance;
            if ($roll < $cum) {
                return $rarity;
            }
        }

        return null;
    }

    public static function selectStoneDrop(float $roll): ?array
    {
        $cum = 0.0;
        foreach (self::STONE_DROPS as [$rarity, $chance, $id]) {
            $cum += $chance;
            if ($roll < $cum) {
                return ['rarity' => $rarity, 'id' => $id];
            }
        }

        return null;
    }

    public static function selectCompletionRarity(float $roll): string
    {
        $cum = 0.0;
        foreach (self::COMPLETION_ROLL as [$rarity, $chance]) {
            $cum += $chance;
            if ($roll < $cum) {
                return $rarity;
            }
        }

        return 'common';
    }

    public function rollMemberDrops(RngInterface $rng, array $raid, int $bossesDefeated): array
    {
        $raidLevel = (int) $raid['level'];
        $eligibleChests = array_values(array_filter(
            self::SPELL_CHEST_LEVELS,
            static fn (int $lvl): bool => $lvl <= $raidLevel,
        ));

        $lines = [];
        for ($i = 0; $i < $bossesDefeated; $i++) {
            $itemRarity = self::selectItemRarity($rng->nextFloat());
            if ($itemRarity !== null) {
                $lines[] = ['kind' => 'item', 'rarity' => $itemRarity];
            }

            foreach ($eligibleChests as $chestLvl) {
                if ($rng->nextFloat() < self::SPELL_CHEST_CHANCE_PER_LEVEL) {
                    $lines[] = ['kind' => 'spell_chest', 'amount' => $chestLvl];
                }
            }

            $stone = self::selectStoneDrop($rng->nextFloat());
            if ($stone !== null) {
                $lines[] = ['kind' => 'upgrade_stone', 'rarity' => $stone['rarity'], 'itemId' => $stone['id']];
            }
        }

        $completion = self::selectCompletionRarity($rng->nextFloat());
        $lines[] = ['kind' => 'item', 'rarity' => $completion, 'isBonus' => true];

        return $lines;
    }

    private static function levelXpBonus(int $raidLevel): int
    {
        return $raidLevel * $raidLevel;
    }

    private static function levelGoldBonus(int $raidLevel): int
    {
        return $raidLevel * 1_000;
    }

    private function pickBaseRaidMonster(int $raidLevel): array
    {
        $best = null;
        foreach ($this->monsters as $m) {
            if ((int) $m['level'] <= $raidLevel && ($best === null || (int) $m['level'] > (int) $best['level'])) {
                $best = $m;
            }
        }

        return $best ?? $this->monsters[0];
    }

    private static function stripDungeonPrefix(string $id): string
    {
        return preg_replace('/dungeon_/', '', $id, 1) ?? $id;
    }
}
