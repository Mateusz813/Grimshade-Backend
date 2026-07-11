<?php

declare(strict_types=1);

namespace App\Domain\Boss;

use App\Domain\Progression\LevelSystem;
use App\Domain\Support\Rng\RngInterface;

/**
 * Port 1:1 src/systems/bossSystem.ts (frontend). Skalowanie bossów pod balans
 * party (×3.5 HP / ×1.75 ATK / ×1.3 DEF), progi cooldownu/wejścia, krzywa nagród
 * (level-driven) i deterministyczna symulacja walki + loot.
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/bossSystem.json (generowane
 * z TS) odtwarzane bit-w-bit (BossSystemTest).
 *
 * KLASYFIKACJA:
 *  - Czyste/deterministyczne → bit-exact: getScaledBossStats, getBossCooldown,
 *    getBossDrops, getBossPhaseMultiplier, isBossEnraged, computeBossRewards,
 *    getBossGoldRange, getBossXp, getBossRecommendedLevel.
 *  - RNG w STAŁEJ kolejności → RngInterface (mulberry32 z seedem): rollBossGold
 *    (1 rzut), rollBossLoot (1 rzut na wpis dropu), resolveBoss (pętla walki bez
 *    RNG, potem loot + gold przy wygranej — dokładnie ta sama kolejność co TS).
 *  - Date.now()/new Date() sparametryzowane: canChallengeBoss/getBossRemainingMs
 *    biorą now (ms) oraz lastDefeated (ms) jako argumenty — parsowanie ISO daty
 *    (new Date(str)) żyje na granicy (kontroler), nie w Domenie (reguła 6).
 *
 * ŚWIADOMIE POMINIĘTE: formatBossCooldown (czysty formatter UI "5m 30s"; serwer
 * wysyła remainingMs z getBossRemainingMs, klient renderuje etykietę).
 *
 * Boss reprezentowany jako tablica asocjacyjna z bosses.json (front src/data ==
 * backend resources/game-content, zweryfikowane identyczne).
 */
final class BossSystem
{
    /** Mnożnik HP dla balansu party (bossy mają ~3.5× więcej HP). */
    public const BOSS_HP_MULTIPLIER = 3.5;

    /** Mnożnik ATK dla balansu party (bossy biją ~1.75× mocniej). */
    public const BOSS_ATK_MULTIPLIER = 1.75;

    /** Mnożnik DEF dla balansu party. */
    public const BOSS_DEF_MULTIPLIER = 1.3;

    /**
     * Legacy — krzywa jest już wchłonięta w computeBossRewards, więc mnożenie
     * ponownie by dubel-liczyło. Trzymane na 1 dla zgodności wstecznej.
     */
    public const BOSS_REWARD_MULTIPLIER = 1;

    /** Domyślny cooldown (s) gdy brak cooldown i dailyAttempts. */
    private const DEFAULT_COOLDOWN_SECONDS = 28800;

    /** Efektywna lista dropów niezależnie od nazwy pola (uniqueDrops ?? dropTable ?? []). */
    public static function getBossDrops(array $boss): array
    {
        return $boss['uniqueDrops'] ?? $boss['dropTable'] ?? [];
    }

    /**
     * Efektywny cooldown (s). TS: `cooldown ?? (dailyAttempts ? floor(86400/da) : 28800)`.
     * Uwaga na semantykę: `??` łapie tylko null/undefined (cooldown=0 zwraca 0),
     * a `dailyAttempts ?` to JS-truthiness (0 → fallback 28800; wartość ≠ 0 → dzieli).
     */
    public static function getBossCooldown(array $boss): int
    {
        if (array_key_exists('cooldown', $boss) && $boss['cooldown'] !== null) {
            return (int) $boss['cooldown'];
        }

        $dailyAttempts = $boss['dailyAttempts'] ?? null;
        if ($dailyAttempts !== null && $dailyAttempts != 0) {
            return (int) floor(86400 / $dailyAttempts);
        }

        return self::DEFAULT_COOLDOWN_SECONDS;
    }

    /**
     * Staty bossa przeskalowane pod balans party.
     *
     * @return array{hp:int, attack:int, attack_min:int, attack_max:int, defense:int}
     */
    public static function getScaledBossStats(array $boss): array
    {
        $atk = $boss['attack'];
        $baseMin = (int) floor($atk * 0.8);
        $baseMax = (int) floor($atk * 1.2);

        return [
            'hp' => (int) floor($boss['hp'] * self::BOSS_HP_MULTIPLIER),
            'attack' => (int) floor($atk * self::BOSS_ATK_MULTIPLIER),
            'attack_min' => (int) max(1, floor($baseMin * self::BOSS_ATK_MULTIPLIER)),
            'attack_max' => (int) max(1, floor($baseMax * self::BOSS_ATK_MULTIPLIER)),
            'defense' => (int) floor($boss['defense'] * self::BOSS_DEF_MULTIPLIER),
        ];
    }

    /** Mnożnik ataku bossa przy danym ułamku HP (enrage < 30% → 1.5, inaczej 1.0). */
    public static function getBossPhaseMultiplier(int|float $bossHpFraction): float
    {
        return $bossHpFraction < 0.3 ? 1.5 : 1.0;
    }

    /** Czy boss jest w fazie wściekłości (< 30% HP). */
    public static function isBossEnraged(int|float $currentHp, int|float $maxHp): bool
    {
        return $maxHp > 0 && $currentHp / $maxHp < 0.3;
    }

    /** XP jako procent xpToNextLevel — wysoki na start, asymptota ~1.8% przy capie. */
    private static function bossXpPercent(int|float $level): float
    {
        return 0.005 + 0.19 / (1 + max(1, $level) / 80);
    }

    /** Środek zakresu złota: floor(38 · level^1.8). */
    private static function bossGoldMid(int|float $level): int
    {
        return (int) floor(38 * (max(1, $level) ** 1.8));
    }

    /**
     * Kanoniczna krzywa nagród — level → {goldMin, goldMax, xp}. Monotoniczna,
     * liczona wyłącznie z poziomu bossa (pola xp/gold z bosses.json to metadata).
     *
     * @return array{goldMin:int, goldMax:int, xp:int}
     */
    public static function computeBossRewards(int $level): array
    {
        $mid = self::bossGoldMid($level);

        return [
            'goldMin' => (int) max(1, floor($mid * 0.6)),
            'goldMax' => (int) max(1, floor($mid * 1.6)),
            'xp' => (int) max(1, floor(LevelSystem::xpToNextLevel($level) * self::bossXpPercent($level))),
        ];
    }

    /**
     * UI helper — ten sam zakres min/max co losuje rollBossGold.
     *
     * @return array{0:int, 1:int}
     */
    public static function getBossGoldRange(array $boss): array
    {
        $r = self::computeBossRewards((int) $boss['level']);

        return [$r['goldMin'], $r['goldMax']];
    }

    /** XP bossa z krzywej level-driven. */
    public static function getBossXp(array $boss): int
    {
        return self::computeBossRewards((int) $boss['level'])['xp'];
    }

    /** Minimalny sugerowany poziom postaci na rozsądną szansę. */
    public static function getBossRecommendedLevel(array $boss): int
    {
        return (int) $boss['level'] + 5;
    }

    /**
     * Czy postać może wyzwać bossa. TS używa Date.now()/new Date() — tu now oraz
     * lastDefeated są przekazane jako ms (parsowanie ISO na granicy, nie w Domenie).
     */
    public static function canChallengeBoss(
        array $boss,
        int $characterLevel,
        ?int $lastDefeatedAtMs,
        int $nowMs,
    ): bool {
        if ($characterLevel < $boss['level']) {
            return false;
        }
        if ($lastDefeatedAtMs === null) {
            return true;
        }
        $elapsed = $nowMs - $lastDefeatedAtMs;

        return $elapsed >= self::getBossCooldown($boss) * 1000;
    }

    /** Pozostały cooldown w ms (0 gdy brak lastDefeated albo minął). */
    public static function getBossRemainingMs(array $boss, ?int $lastDefeatedAtMs, int $nowMs): int
    {
        if ($lastDefeatedAtMs === null) {
            return 0;
        }
        $elapsed = $nowMs - $lastDefeatedAtMs;

        return (int) max(0, self::getBossCooldown($boss) * 1000 - $elapsed);
    }

    /** Losowanie złota: 1 rzut RNG (goldMin + floor(rand · rozpiętość)). */
    public static function rollBossGold(RngInterface $rng, array $boss): int
    {
        $r = self::computeBossRewards((int) $boss['level']);

        return $r['goldMin'] + (int) floor($rng->nextFloat() * ($r['goldMax'] - $r['goldMin'] + 1));
    }

    /**
     * Losowanie unikatowych dropów: 1 rzut na wpis dropu, w kolejności listy
     * (dokładnie jak TS `.filter(drop => Math.random() < drop.chance)`).
     *
     * @return list<array<string, mixed>>
     */
    public static function rollBossLoot(RngInterface $rng, array $boss): array
    {
        $drops = [];
        foreach (self::getBossDrops($boss) as $drop) {
            if ($rng->nextFloat() < $drop['chance']) {
                $drops[] = $drop;
            }
        }

        return $drops;
    }

    /**
     * Symulacja walki z bossem. Pętla walki jest DETERMINISTYCZNA (bez RNG);
     * RNG konsumowany dopiero przy wygranej — loot (rollBossLoot) potem gold
     * (rollBossGold), w tej samej kolejności co TS.
     *
     * @param  array{attack:int|float, defense:int|float, max_hp:int|float, level:int|float}  $character
     * @return array{won:bool, playerHpLeft:int, turns:int, drops:list<array<string,mixed>>, gold:int, xp:int}
     */
    public static function resolveBoss(RngInterface $rng, array $boss, array $character): array
    {
        $scaled = self::getScaledBossStats($boss);
        $playerHp = $character['max_hp'];
        $bossHp = $scaled['hp'];
        $bossMaxHp = $scaled['hp'];
        $playerDmg = max(1, $character['attack'] - $scaled['defense']);
        $baseBossDmg = max(1, $scaled['attack'] - $character['defense']);
        $turns = 0;

        while ($bossHp > 0 && $playerHp > 0 && $turns < 100000) {
            // Gracz atakuje bossa
            $bossHp -= $playerDmg;
            if ($bossHp <= 0) {
                break;
            }

            // Boss atakuje gracza (enrage < 30% HP)
            $mult = self::getBossPhaseMultiplier($bossHp / $bossMaxHp);
            $bossDmg = max(1, (int) floor($baseBossDmg * $mult));
            $playerHp -= $bossDmg;
            $turns++;
        }

        $won = $bossHp <= 0 && $playerHp > 0;
        $drops = $won ? self::rollBossLoot($rng, $boss) : [];
        $gold = $won ? self::rollBossGold($rng, $boss) : 0;

        return [
            'won' => $won,
            'playerHpLeft' => (int) max(0, $playerHp),
            'turns' => $turns,
            'drops' => $drops,
            'gold' => $gold,
            'xp' => $won ? self::getBossXp($boss) : 0,
        ];
    }
}
