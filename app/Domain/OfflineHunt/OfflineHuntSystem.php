<?php

declare(strict_types=1);

namespace App\Domain\OfflineHunt;

use App\Domain\Loot\LootSystem;
use App\Domain\Skills\SkillSystem;
use App\Domain\Support\Rng\RngInterface;

/**
 * Port src/systems/offlineHuntSystem.ts (czysta logika liczbowa; podzbiór
 * autorytatywny + portowalny).
 *
 * Frontowy offlineHuntSystem żyje na store'ach (offlineHunt/mastery/buff/skill/
 * task/quest/inventory) i na Date.now(). Wrappery previewOfflineHunt() /
 * claimOfflineHunt() (odczyt + MUTACJA store'ów: addXp/addGold/addItem/
 * addConsumable/addStones/addMasteryKills/addKill/addProgress/stopHunt oraz
 * warstwa Realtime/UI) są POMINIĘTE (reguła 4/5). Tutaj serwer-autorytatywna,
 * bezstanowa logika:
 *
 *  - preview()               — deterministyczny podgląd (kills/xp/gold/skillXp),
 *  - aggregateClaimRewards() — deterministyczna agregacja nagród XP/Gold przy
 *                              danym rozkładzie killsByRarity,
 *  - weightedTaskKills()     — ważona liczba zabójstw (task/quest/mastery),
 *  - getOfflineHuntSpeedMultiplier() — tempo zabójstw z poziomu mastery,
 *  - rollKillsByRarity()     — prymityw rzutu rzadkości N× (RngInterface).
 *
 * CZAS: parametryzowany (reguła 6) — nowMs/startedAtMs zamiast Date.now().
 *
 * PARYTET:
 *  - Deterministyczne metody: golden bit-exact (tests/Golden/fixtures/
 *    offlineHuntSystem.json generowany z TS). Każde mnożenie float zachowuje
 *    kolejność źródła (JS ewaluuje lewostronnie).
 *  - rollKillsByRarity: woła LootSystem::rollMonsterRarity N× w sekwencji —
 *    każde wywołanie konsumuje 1× nextFloat, więc z tym samym seedem
 *    (mulberry32) daje identyczny agregat co TS (seeded golden).
 *
 * NIEPORTOWALNE / SERWER-AUTORYTATYWNE (świadomie): pełny claimOfflineHunt()
 * przeplata rzuty rzadkości z rollLoot() (sort-shuffle → zmienna liczba rzutów
 * RNG, patrz LootSystem docblock), rollPotionDrop/rollSpellChestDrop/
 * rollStoneDrop oraz generuje realne itemy. Rozkład killsByRarity i dropy w
 * kliencie są więc serwer-autorytatywne (serwer losuje własnym RNG). Tu
 * dowodzimy jedynie parytetu prymitywu rzadkości i deterministycznej matematyki
 * nagród przy zadanym rozkładzie.
 *
 * ZERO Eloquent / mt_rand / random_int / now(). RNG tylko przez RngInterface.
 */
final class OfflineHuntSystem
{
    /** Baza: 1 zabójstwo co 10 sekund. */
    public const OFFLINE_HUNT_BASE_SECONDS_PER_KILL = 10;

    /** Maks. czas polowania w sekundach (12 godzin). */
    public const OFFLINE_HUNT_MAX_SECONDS = 12 * 60 * 60;

    /** @var list<string> kanoniczna kolejność rzadkości potworów */
    public const RARITIES = ['normal', 'strong', 'epic', 'legendary', 'boss'];

    /**
     * Rzadkość → mnożnik XP za zabójstwo (mirror module-private RARITY_XP_MULT
     * z offlineHuntSystem.ts; odzwierciedla żywy silnik walki).
     *
     * @var array<string, int|float>
     */
    public const RARITY_XP_MULT = [
        'normal' => 1, 'strong' => 1.5, 'epic' => 2.5, 'legendary' => 4, 'boss' => 8,
    ];

    /**
     * Rzadkość → mnożnik złota za zabójstwo (mirror module-private RARITY_GOLD_MULT).
     *
     * @var array<string, int|float>
     */
    public const RARITY_GOLD_MULT = [
        'normal' => 1, 'strong' => 1.5, 'epic' => 2.5, 'legendary' => 4, 'boss' => 8,
    ];

    /**
     * Ile zabójstw tasku liczy każda rzadkość (mirror MONSTER_RARITY_TASK_KILLS
     * z lootSystem.ts; używane do ważenia postępu task/quest/mastery).
     *
     * @var array<string, int>
     */
    public const MONSTER_RARITY_TASK_KILLS = [
        'normal' => 1, 'strong' => 3, 'epic' => 10, 'legendary' => 50, 'boss' => 200,
    ];

    /** Maksymalny poziom mastery per potwór. */
    private const MASTERY_MAX_LEVEL = 25;

    /** Bonus N7: +2% XP / poziom mastery (max +50% na lvl 25). */
    private const MASTERY_XP_BONUS_PER_LEVEL = 0.02;

    /** Bonus N7: +2% Gold / poziom mastery (max +50% na lvl 25). */
    private const MASTERY_GOLD_BONUS_PER_LEVEL = 0.02;

    /**
     * Mnożnik kills/s z poziomu mastery potwora (mirror
     * getOfflineHuntSpeedMultiplier z offlineHuntStore.ts).
     *  mastery 0-4   → x1 (1/10s)
     *  mastery 5-11  → x2 (1/5s)
     *  mastery 12-19 → x3 (1/~3.33s)
     *  mastery 20+   → x4 (1/2.5s)
     */
    public static function getOfflineHuntSpeedMultiplier(int $masteryLevel): int
    {
        if ($masteryLevel >= 20) {
            return 4;
        }
        if ($masteryLevel >= 12) {
            return 3;
        }
        if ($masteryLevel >= 5) {
            return 2;
        }

        return 1;
    }

    /**
     * Deterministyczny podgląd polowania (mirror previewOfflineHunt() bez
     * store'ów/Date.now). Odczyty store'ów wstrzyknięte jako jawne pola $input;
     * mnożniki bufów (getBuffMultiplier) podane wprost (dyskretne 1 / 1.5 / 2.0).
     *
     * @param  array{
     *     nowMs:int, startedAtMs:int, masteryLevel:int, monsterXp:int,
     *     goldMin:int, goldMax:int, skillLevel:int, trainedSkillId:string,
     *     xpBuffMult:int|float, premiumXpMult:int|float,
     *     skillXpBoostMult:int|float, offlineTrainingBoostMult:int|float
     * }  $input
     * @return array{elapsedSeconds:int, cappedSeconds:int, kills:int, xpGained:int, goldGained:int, skillXpGained:int, speedMultiplier:int}
     */
    public static function preview(array $input): array
    {
        $nowMs = (int) $input['nowMs'];
        $startedAtMs = (int) $input['startedAtMs'];
        $masteryLevel = (int) $input['masteryLevel'];
        $monsterXp = (int) $input['monsterXp'];
        $goldMin = (int) $input['goldMin'];
        $goldMax = (int) $input['goldMax'];
        $skillLevel = (int) $input['skillLevel'];
        $trainedSkillId = (string) $input['trainedSkillId'];
        $xpBuffMult = (float) $input['xpBuffMult'];
        $premiumXpMult = (float) $input['premiumXpMult'];
        $skillXpBoostMult = (float) $input['skillXpBoostMult'];
        $offlineTrainingBoostMult = (float) $input['offlineTrainingBoostMult'];

        $elapsedSeconds = max(0, (int) floor(($nowMs - $startedAtMs) / 1000));
        $cappedSeconds = min($elapsedSeconds, self::OFFLINE_HUNT_MAX_SECONDS);

        $speedMultiplier = self::getOfflineHuntSpeedMultiplier($masteryLevel);
        $killsPerSecond = $speedMultiplier / self::OFFLINE_HUNT_BASE_SECONDS_PER_KILL;
        $kills = (int) floor($cappedSeconds * $killsPerSecond);

        $masteryXpMult = self::masteryXpMultiplier($masteryLevel);
        $masteryGoldMult = self::masteryGoldMultiplier($masteryLevel);

        // Kolejność mnożeń 1:1 z TS: xpMult * premiumMult * masteryXpMult.
        $totalXpMult = $xpBuffMult * $premiumXpMult * $masteryXpMult;
        $xpPerKill = (int) floor($monsterXp * $totalXpMult);
        $xpGained = $kills * $xpPerKill;

        // Preview: BEZ wewnętrznego floor na (min+max)/2 (inaczej niż claim).
        $goldPerKill = (int) floor((($goldMin + $goldMax) / 2) * $masteryGoldMult);
        $goldGained = $kills * $goldPerKill;

        $skillXpBaseRaw = SkillSystem::calculateOfflineSkillXp($cappedSeconds, $skillLevel, $trainedSkillId);
        $skillXpMult = $skillXpBoostMult * $offlineTrainingBoostMult * $premiumXpMult;
        $skillXpGained = (int) floor($skillXpBaseRaw * $skillXpMult);

        return [
            'elapsedSeconds' => $elapsedSeconds,
            'cappedSeconds' => $cappedSeconds,
            'kills' => $kills,
            'xpGained' => $xpGained,
            'goldGained' => $goldGained,
            'skillXpGained' => $skillXpGained,
            'speedMultiplier' => $speedMultiplier,
        ];
    }

    /**
     * Deterministyczna agregacja nagród claim przy danym rozkładzie
     * killsByRarity (mirror pętli claimOfflineHunt()). Per-kill XP/Gold danej
     * rzadkości to STAŁA (floor), więc suma N kopii = N * wartość (dokładna
     * arytmetyka int) — matematycznie tożsame z pętlą źródła.
     *
     * @param  array{
     *     monsterXp:int, goldMin:int, goldMax:int, masteryLevel:int,
     *     xpBuffMult:int|float, premiumXpMult:int|float,
     *     killsByRarity:array<string, int>
     * }  $input
     * @return array{xpGained:int, goldGained:int}
     */
    public static function aggregateClaimRewards(array $input): array
    {
        $monsterXp = (int) $input['monsterXp'];
        $goldMin = (int) $input['goldMin'];
        $goldMax = (int) $input['goldMax'];
        $masteryLevel = (int) $input['masteryLevel'];
        $xpBuffMult = (float) $input['xpBuffMult'];
        $premiumXpMult = (float) $input['premiumXpMult'];
        /** @var array<string, int> $kbr */
        $kbr = $input['killsByRarity'];

        // claim: xpMult = getBuff('xp_boost') * getBuff('premium_xp_boost').
        $xpMult = $xpBuffMult * $premiumXpMult;
        $masteryXpMult = self::masteryXpMultiplier($masteryLevel);
        $masteryGoldMult = self::masteryGoldMultiplier($masteryLevel);
        // claim: goldBase floor'owany PRZED mnożeniem (inaczej niż preview).
        $goldBase = (int) floor(($goldMin + $goldMax) / 2);

        $xpGained = 0;
        $goldGained = 0;
        foreach (self::RARITIES as $r) {
            $n = (int) ($kbr[$r] ?? 0);
            // Kolejność mnożeń 1:1 z TS: monster.xp * rarityXpMult * xpMult * masteryXpMult.
            $xpPerKill = (int) floor($monsterXp * self::RARITY_XP_MULT[$r] * $xpMult * $masteryXpMult);
            $goldPerKill = (int) floor($goldBase * self::RARITY_GOLD_MULT[$r] * $masteryGoldMult);
            $xpGained += $n * $xpPerKill;
            $goldGained += $n * $goldPerKill;
        }

        return ['xpGained' => $xpGained, 'goldGained' => $goldGained];
    }

    /**
     * Ważona liczba zabójstw (task/quest/mastery), mirror claimOfflineHunt():
     * Σ killsByRarity[r] * MONSTER_RARITY_TASK_KILLS[r].
     *
     * @param  array<string, int>  $killsByRarity
     */
    public static function weightedTaskKills(array $killsByRarity): int
    {
        $total = 0;
        foreach (self::MONSTER_RARITY_TASK_KILLS as $rarity => $weight) {
            $total += (int) ($killsByRarity[$rarity] ?? 0) * $weight;
        }

        return $total;
    }

    /**
     * Prymityw: rzuca rzadkość potwora $kills razy (mirror pętli claim
     * `killsByRarity[rollMonsterRarity(false, masteryBonuses)]++`) i agreguje.
     * Konsumuje RngInterface DOKŁADNIE tak jak TS (1× nextFloat / zabójstwo),
     * więc z tym samym seedem = bit-parity.
     *
     * @param  array{strong:float, epic:float, legendary:float, mythic:float, heroic:float}|null  $masteryBonuses
     * @return array{normal:int, strong:int, epic:int, legendary:int, boss:int}
     */
    public static function rollKillsByRarity(RngInterface $rng, int $kills, ?array $masteryBonuses = null): array
    {
        $kbr = ['normal' => 0, 'strong' => 0, 'epic' => 0, 'legendary' => 0, 'boss' => 0];
        for ($i = 0; $i < $kills; $i++) {
            $rarity = LootSystem::rollMonsterRarity($rng, false, $masteryBonuses);
            $kbr[$rarity]++;
        }

        return $kbr;
    }

    /**
     * Mnożnik XP z mastery (mirror getMasteryXpMultiplier z masteryStore.ts):
     * 1 + clamp(0..25, level) * 0.02.
     */
    private static function masteryXpMultiplier(int $masteryLevel): float
    {
        $lvl = max(0, min(self::MASTERY_MAX_LEVEL, $masteryLevel));

        return 1 + $lvl * self::MASTERY_XP_BONUS_PER_LEVEL;
    }

    /**
     * Mnożnik złota z mastery (mirror getMasteryGoldMultiplier z masteryStore.ts):
     * 1 + clamp(0..25, level) * 0.02.
     */
    private static function masteryGoldMultiplier(int $masteryLevel): float
    {
        $lvl = max(0, min(self::MASTERY_MAX_LEVEL, $masteryLevel));

        return 1 + $lvl * self::MASTERY_GOLD_BONUS_PER_LEVEL;
    }
}
