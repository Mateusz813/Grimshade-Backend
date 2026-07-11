<?php

declare(strict_types=1);

namespace App\Domain\Arena;

/**
 * Port czystego podzbioru src/systems/arenaSystem.ts: ligi, nagrody za mecz,
 * awans/spadek na koniec sezonu, kubełki nagród. (generateBotsForArena — RNG,
 * rankCompetitors/sezonowe daty — odłożone.)
 */
final class ArenaMath
{
    /** @var list<string> */
    public const ARENA_LEAGUES = [
        'bronze', 'silver', 'gold', 'platinum', 'emerald', 'diamond', 'master', 'grand_master', 'legend',
    ];

    /** @var array<string, array{promotedTop:?int, relegatedBottom:?int}> */
    public const LEAGUE_BOUNDARIES = [
        'bronze' => ['promotedTop' => 40, 'relegatedBottom' => null],
        'silver' => ['promotedTop' => 35, 'relegatedBottom' => 20],
        'gold' => ['promotedTop' => 33, 'relegatedBottom' => 30],
        'platinum' => ['promotedTop' => 20, 'relegatedBottom' => 40],
        'emerald' => ['promotedTop' => 17, 'relegatedBottom' => 45],
        'diamond' => ['promotedTop' => 15, 'relegatedBottom' => 50],
        'master' => ['promotedTop' => 10, 'relegatedBottom' => 60],
        'grand_master' => ['promotedTop' => 5, 'relegatedBottom' => 70],
        'legend' => ['promotedTop' => null, 'relegatedBottom' => null],
    ];

    /** @var list<array<string, mixed>> */
    private const REWARD_BUCKETS = [
        ['positionLabel' => '1', 'range' => [1, 1], 'arenaPoints' => 1000, 'gold' => 100000, 'mythicStones' => 10, 'legendaryStones' => 20, 'epicStones' => 30, 'rareStones' => 40, 'commonStones' => 50, 'pctHpPotion' => 100, 'pctMpPotion' => 100],
        ['positionLabel' => '2', 'range' => [2, 2], 'arenaPoints' => 800, 'gold' => 80000, 'mythicStones' => 8, 'legendaryStones' => 15, 'epicStones' => 20, 'rareStones' => 30, 'commonStones' => 40, 'pctHpPotion' => 50, 'pctMpPotion' => 50],
        ['positionLabel' => '3', 'range' => [3, 3], 'arenaPoints' => 500, 'gold' => 50000, 'mythicStones' => 5, 'legendaryStones' => 10, 'epicStones' => 15, 'rareStones' => 20, 'commonStones' => 30, 'pctHpPotion' => 25, 'pctMpPotion' => 25],
        ['positionLabel' => '4-5', 'range' => [4, 5], 'arenaPoints' => 300, 'gold' => 30000, 'mythicStones' => 1, 'legendaryStones' => 5, 'epicStones' => 10, 'rareStones' => 15, 'commonStones' => 20, 'pctHpPotion' => 0, 'pctMpPotion' => 0],
        ['positionLabel' => '6-10', 'range' => [6, 10], 'arenaPoints' => 200, 'gold' => 20000, 'mythicStones' => 0, 'legendaryStones' => 0, 'epicStones' => 10, 'rareStones' => 15, 'commonStones' => 20, 'pctHpPotion' => 0, 'pctMpPotion' => 0],
        ['positionLabel' => '11-50', 'range' => [11, 50], 'arenaPoints' => 100, 'gold' => 10000, 'mythicStones' => 0, 'legendaryStones' => 0, 'epicStones' => 0, 'rareStones' => 10, 'commonStones' => 15, 'pctHpPotion' => 0, 'pctMpPotion' => 0],
        ['positionLabel' => '51-100', 'range' => [51, 100], 'arenaPoints' => 50, 'gold' => 5000, 'mythicStones' => 0, 'legendaryStones' => 0, 'epicStones' => 0, 'rareStones' => 5, 'commonStones' => 10, 'pctHpPotion' => 0, 'pctMpPotion' => 0],
    ];

    public static function getLeagueMultiplier(string $league): int
    {
        return array_search($league, self::ARENA_LEAGUES, true) + 1;
    }

    public static function getNextLeague(string $league): string
    {
        $idx = array_search($league, self::ARENA_LEAGUES, true);
        if ($idx === false) {
            return $league;
        }

        return self::ARENA_LEAGUES[min(count(self::ARENA_LEAGUES) - 1, $idx + 1)];
    }

    public static function getPreviousLeague(string $league): string
    {
        $idx = array_search($league, self::ARENA_LEAGUES, true);
        if ($idx === false) {
            return $league;
        }

        return self::ARENA_LEAGUES[max(0, $idx - 1)];
    }

    /**
     * @return array{attacker:array{arenaPoints:int, leaguePoints:int}, defender:array{arenaPoints:int, leaguePoints:int}}
     */
    public static function getMatchReward(bool $won, bool $attackerIsHigher): array
    {
        if ($won) {
            return $attackerIsHigher
                ? ['attacker' => ['arenaPoints' => 200, 'leaguePoints' => 2], 'defender' => ['arenaPoints' => 0, 'leaguePoints' => 0]]
                : ['attacker' => ['arenaPoints' => 100, 'leaguePoints' => 1], 'defender' => ['arenaPoints' => 0, 'leaguePoints' => 0]];
        }

        return $attackerIsHigher
            ? ['attacker' => ['arenaPoints' => 0, 'leaguePoints' => 0], 'defender' => ['arenaPoints' => 250, 'leaguePoints' => 1]]
            : ['attacker' => ['arenaPoints' => 0, 'leaguePoints' => 0], 'defender' => ['arenaPoints' => 250, 'leaguePoints' => 2]];
    }

    /**
     * @return array{type:string, toLeague?:string}
     */
    public static function getSeasonOutcome(string $league, int $finalRank): array
    {
        $b = self::LEAGUE_BOUNDARIES[$league];
        if ($b['promotedTop'] !== null && $finalRank <= $b['promotedTop']) {
            return ['type' => 'promote', 'toLeague' => self::getNextLeague($league)];
        }
        if ($b['relegatedBottom'] !== null) {
            $lo = 100 - $b['relegatedBottom'] + 1;
            if ($finalRank >= $lo) {
                return ['type' => 'relegate', 'toLeague' => self::getPreviousLeague($league)];
            }
        }

        return ['type' => 'stay'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getRewardBuckets(): array
    {
        return self::REWARD_BUCKETS;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findRewardBucket(int $rank): ?array
    {
        foreach (self::REWARD_BUCKETS as $b) {
            if ($rank >= $b['range'][0] && $rank <= $b['range'][1]) {
                return $b;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    public static function applyLeagueMultiplier(array $bucket, string $league): array
    {
        $m = self::getLeagueMultiplier($league);
        foreach (['arenaPoints', 'gold', 'mythicStones', 'legendaryStones', 'epicStones', 'rareStones', 'commonStones', 'pctHpPotion', 'pctMpPotion'] as $key) {
            $bucket[$key] *= $m;
        }

        return $bucket;
    }
}
