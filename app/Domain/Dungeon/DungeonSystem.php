<?php

declare(strict_types=1);

namespace App\Domain\Dungeon;

use App\Domain\Loot\ItemGenerator;
use App\Domain\Support\Rng\RngInterface;
use DateTimeImmutable;

/**
 * Port 1:1 src/systems/dungeonSystem.ts (frontend). Skalowanie fal lochów,
 * kompozycje potworów, limity dziennych prób / cooldown / min-level, symulacja
 * fali (deterministyczna) oraz dropy (RNG).
 *
 * PARYTET (tests/Golden/fixtures/dungeonSystem.json — generowane z TS):
 *  - DETERMINISTYCZNE (bez RNG) → bit-parity: getDungeon*, skalowania, kompozycje,
 *    pick po poziomie (sort deterministyczny), resolveWave, estimateDungeonRewards,
 *    formatCooldown, canEnterDungeon/getDungeonRemainingMs (czas parametryzowany).
 *  - RNG STAŁA KOLEJNOŚĆ (1 rzut) → seeded bit-parity: rollDungeonRarity,
 *    rollDungeonGold (RngInterface konsumowany w tej samej kolejności co TS
 *    Math.random → Mulberry32Rng(seed) daje identyczny wynik).
 *  - RNG + ItemGenerator: rollDungeonItemDrop / resolveDungeon. Dla lochów o
 *    maxRarity 'common' rarity itemów zawsze 'common' → 0 slotów bonusów → BRAK
 *    sort-shuffle w ItemGenerator → CAŁA sekwencja RNG deterministyczna → wektory
 *    bit-parity (IGeneratedItem nie zawiera uuid). Ścieżki rare+ (shuffle w
 *    generateBonusStats) NIE są bit-parity — patrz ItemGenerator; tam własnościowe.
 *
 * Czas: TS używa Date.now()/new Date(str). Tu now przekazywany jako $nowMs (reguła
 * parametryzacji), a lastCompletedAt (ISO-8601 UTC 'Z') parsowany do epoch-ms
 * identycznie jak JS Date.getTime() (format 'Uv' = sekundy + 3-cyfrowe ms).
 *
 * ZERO Eloquent/mt_rand/now(): losowość WYŁĄCZNIE przez RngInterface.
 */
final class DungeonSystem
{
    /** @var list<string> */
    public const DUNGEON_RARITY_ORDER = ['common', 'rare', 'epic', 'legendary', 'mythic', 'heroic'];

    /** Wagi rzadkości (do maxRarity) — indeksowane jak DUNGEON_RARITY_ORDER. */
    private const RARITY_WEIGHTS = [50, 25, 15, 7, 2.5, 0.5];

    /** @var array<string, array{hp:float, atk:float, def:float}> */
    public const DUNGEON_MONSTER_TYPE_MULTIPLIERS = [
        'Normal' => ['hp' => 1.0, 'atk' => 1.0, 'def' => 1.0],
        'Strong' => ['hp' => 1.5, 'atk' => 1.3, 'def' => 1.2],
        'Epic' => ['hp' => 2.0, 'atk' => 1.5, 'def' => 1.3],
        'Legendary' => ['hp' => 3.0, 'atk' => 1.8, 'def' => 1.5],
        'Boss' => ['hp' => 5.0, 'atk' => 2.5, 'def' => 2.0],
    ];

    /** @var list<string> kolejność tierów typu do step-down w kompozycji fali */
    private const TYPE_ORDER = ['Normal', 'Strong', 'Epic', 'Legendary', 'Boss'];

    // ---- Helpery lochu (deterministyczne) -----------------------------------

    /**
     * @param  array<string, mixed>  $dungeon
     */
    public static function getDungeonMinLevel(array $dungeon): int
    {
        return (int) ($dungeon['minLevel'] ?? $dungeon['level']);
    }

    /**
     * @param  array<string, mixed>  $dungeon
     */
    public static function getDungeonWaves(array $dungeon): int
    {
        return (int) ($dungeon['waves'] ?? max(3, min(10, (int) floor($dungeon['level'] / 15) + 3)));
    }

    /**
     * @param  array<string, mixed>  $dungeon
     */
    public static function getDungeonCooldown(array $dungeon): int
    {
        $dailyAttempts = $dungeon['dailyAttempts'] ?? 0;

        return (int) ($dungeon['cooldown'] ?? ($dailyAttempts ? (int) floor(86400 / $dailyAttempts) : 17280));
    }

    /**
     * @param  array<string, mixed>  $dungeon
     * @return array{0:int, 1:int}
     */
    public static function getDungeonRewardGold(array $dungeon): array
    {
        if (isset($dungeon['rewardGold'])) {
            return [(int) $dungeon['rewardGold'][0], (int) $dungeon['rewardGold'][1]];
        }

        return [(int) $dungeon['level'] * 10, (int) $dungeon['level'] * 25];
    }

    /**
     * @param  array<string, mixed>  $dungeon
     */
    public static function getDungeonRewardXp(array $dungeon): int
    {
        return (int) ($dungeon['rewardXp'] ?? $dungeon['level'] * 50);
    }

    /**
     * Czy postać może wejść: min-level + cooldown. Czas parametryzowany.
     *
     * @param  array<string, mixed>  $dungeon
     */
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

    /**
     * @param  array<string, mixed>  $dungeon
     */
    public static function getDungeonRemainingMs(array $dungeon, ?string $lastCompletedAt, int $nowMs): int
    {
        if ($lastCompletedAt === null) {
            return 0;
        }
        $elapsed = $nowMs - self::isoToMs($lastCompletedAt);

        return (int) max(0, self::getDungeonCooldown($dungeon) * 1000 - $elapsed);
    }

    /** Format cooldownu jak TS: "5s" / "3m 20s" / "1h 5m" (Math.ceil ms→s). */
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

    // ---- Typy/kompozycje fal (deterministyczne) -----------------------------

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

    /**
     * Ile potworów spawnuje się w fali (boss zawsze zatłoczony; regularne 1→2→3;
     * lochy 30+ dobijają +1). Cap 4.
     */
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

    /**
     * Kompozycja typów fali: lead (najtwardszy) + wypełniacze wg tieru lochu.
     *
     * @return list<string>
     */
    public static function getWaveComposition(int $dungeonLevel, int $wave, int $totalWaves): array
    {
        $lead = self::getWaveMonsterType($wave, $totalWaves, $dungeonLevel);
        $count = self::getWaveMonsterCount($dungeonLevel, $wave, $totalWaves);
        if ($count <= 1) {
            return [$lead];
        }

        $out = [$lead];

        // Top tier (800+): wszyscy równi lidera.
        if ($dungeonLevel >= 800) {
            while (count($out) < $count) {
                $out[] = $lead;
            }

            return $out;
        }

        // Low tier (1-14): schodkowa drabinka w dół do Normal.
        if ($dungeonLevel <= 14) {
            $current = $lead;
            while (count($out) < $count) {
                $current = self::stepDownType($current);
                $out[] = $current;
            }

            return $out;
        }

        // Mid + high (15-799): 1 lead + (count-1) o jeden tier niżej.
        $filler = self::stepDownType($lead);
        while (count($out) < $count) {
            $out[] = $filler;
        }

        return $out;
    }

    // ---- Pick potworów po poziomie (sort deterministyczny) ------------------

    /**
     * @param  array<string, mixed>  $dungeon
     * @param  list<array<string, mixed>>  $allMonsters
     * @return array<string, mixed>
     */
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

    /**
     * Pick 1–4 potworów fali: lead przez pickWaveMonster, eskorty z puli po
     * poziomie (lead wykluczony, powtarzany gdy pula za mała).
     *
     * @param  array<string, mixed>  $dungeon
     * @param  list<array<string, mixed>>  $allMonsters
     * @return list<array<string, mixed>>
     */
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

    // ---- Skalowanie potworów -------------------------------------------------

    /**
     * Skaluje statystyki potwora dla fali lochu (tiery 1-8 / 9-18 / 20+),
     * a na wierzch mnożniki typu (Epic/Legendary/Boss/Strong).
     *
     * @param  array<string, mixed>  $monster
     * @return array<string, mixed>
     */
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

    /**
     * Skaluje potwora jako inny typ niż lead (eskorty). Re-baza przez podział
     * przez mnożnik leada i mnożenie przez mnożnik docelowy.
     *
     * @param  array<string, mixed>  $monster
     * @return array<string, mixed>
     */
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

    // ---- Symulacja fali (czysta, deterministyczna) --------------------------

    /**
     * @return array{playerHpLeft:int, won:bool}
     */
    public static function resolveWave(
        int $playerHp,
        int $playerAtk,
        int $playerDef,
        int $monsterHp,
        int $monsterAtk,
        int $monsterDef,
    ): array {
        $pHp = $playerHp;
        $mHp = $monsterHp;
        $pDmg = max(1, $playerAtk - $monsterDef);
        $mDmg = max(1, $monsterAtk - $playerDef);

        // Bezpiecznik: max 10 000 ciosów.
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

    // ---- Dropy (RNG) ---------------------------------------------------------

    /** Rzut rzadkości dropu lochu (ważony, capowany maxRarity). 1 rzut RNG. */
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

    /**
     * @param  array{0:int, 1:int}  $range
     */
    public static function rollDungeonGold(RngInterface $rng, array $range): int
    {
        return $range[0] + (int) floor($rng->nextFloat() * ($range[1] - $range[0] + 1));
    }

    /**
     * Rzut dropu itemu z fali lochu. Item level = poziom lochu (nie postaci).
     * Konsumpcja RNG jak TS: [drop-check] → [rarity] → [generateRandomItem].
     *
     * @param  array<string, mixed>  $dungeon
     * @return array<string, mixed>|null
     */
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

    // ---- Pełna symulacja lochu (per-wave) -----------------------------------

    /**
     * @param  array<string, mixed>  $dungeon
     * @param  array{attack:int, defense:int, max_hp:int, level:int}  $character
     * @param  list<array<string, mixed>>  $allMonsters
     * @return array{waveResults:list<array<string, mixed>>, result:array<string, mixed>}
     */
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
                (int) $character['attack'], (int) $character['defense'],
                (int) $monster['hp'], (int) $monster['attack'], (int) $monster['defense'],
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

    // ---- Estymacja nagród (spawny × 4 + bonus poziomu) ----------------------

    /**
     * @param  array<string, mixed>  $dungeon
     * @param  list<array<string, mixed>>  $allMonsters
     * @param  list<array{id:string, gold:array{0:int, 1:int}}>  $monstersRawData
     * @return array{goldMin:int, goldMax:int, xp:int}
     */
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

    // ---- Prywatne helpery ----------------------------------------------------

    private static function stepDownType(string $type): string
    {
        $idx = array_search($type, self::TYPE_ORDER, true);
        if ($idx === false || $idx <= 0) {
            return 'Normal';
        }

        return self::TYPE_ORDER[$idx - 1];
    }

    /**
     * Sort stabilny po |level - dungeonLevel| (jak TS `[...].sort(...)`).
     * PHP 8 usort jest stabilny — remis zachowuje kolejność wejścia.
     *
     * @param  list<array<string, mixed>>  $monsters
     * @return list<array<string, mixed>>
     */
    private static function sortByLevelDistance(array $monsters, int $dungeonLevel): array
    {
        $sorted = array_values($monsters);
        usort(
            $sorted,
            static fn (array $a, array $b): int => abs($a['level'] - $dungeonLevel) <=> abs($b['level'] - $dungeonLevel),
        );

        return $sorted;
    }

    /** Epoch-ms z ISO-8601 UTC — jak JS `new Date(str).getTime()` ('Uv'=s+ms). */
    private static function isoToMs(string $iso): int
    {
        return (int) (new DateTimeImmutable($iso))->format('Uv');
    }
}
