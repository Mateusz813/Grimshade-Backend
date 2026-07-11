<?php

declare(strict_types=1);

namespace App\Domain\Guild;

use App\Domain\Support\Rng\RngInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Port 1:1 src/systems/guildSystem.ts (frontend). Czyste formuły gildii:
 * progresja poziomu (krzywa XP), skalowanie bossa (HP/tier/obrażenia), koszt +
 * limit członków, mnożnik nagrody z udziału w obrażeniach oraz klucze tygodnia
 * (Monday-start / dzień claim / dzisiejsza data). Zero RNG, zero Eloquent.
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/guildSystem.json (generowane
 * z TS) są tu odtwarzane bajt-w-bajt (GuildSystemTest). Zmiana którejkolwiek
 * formuły w TS regeneruje fixture i wymusza aktualizację tu.
 *
 * PRECYZJA: pow(1.25, tier-1) jest bit-identyczny między V8 a libm PHP w całym
 * zakresie gry (tier ≤ 50; boss i tak jest tam clampowany). guildXpToNextLevel
 * pozostaje ≤ 2^53 do poziomu ~80k, ale guildXpForLevel to suma narastająca —
 * powyżej ~poziomu 400 JS (double) traci precyzję integer, więc golden dla
 * guildXpForLevel jest ograniczony do ≤ 400 (tam JS == int64 PHP).
 *
 * DATY: funkcje `new Date()` z TS są sparametryzowane epoką w ms (int $epochMs),
 * liczone w UTC — identycznie z Date.UTC + getUTCDay/toISOString po stronie TS.
 */
final class GuildSystem
{
    /** Startowy limit członków świeżej gildii. */
    public const GUILD_INITIAL_MEMBER_CAP = 20;

    /** Koszt (w goldzie) założenia gildii = 10 cc (100 000 gp/cc). */
    public const GUILD_CREATE_COST_GOLD = 1_000_000;

    /** Brak górnego limitu poziomu gildii (sentinel; display „Lvl X/MAX"). */
    public const GUILD_MAX_LEVEL = INF;

    /** Maksymalny tier bossa — 50 (loch-1..loch-50); powyżej powtarza tier 50. */
    public const GUILD_BOSS_MAX_TIER = 50;

    /** Pojemność skarbca gildii. */
    public const GUILD_TREASURY_SLOTS = 1000;

    /** Maks. szansa dropu HEROIC z w pełni wyczyszczonego bossa (≤ 1%). */
    public const GUILD_BOSS_HEROIC_MAX_CHANCE = 0.01;

    /** Bramka % HP na atak: aktywny gracz trzyma arenę aż zabierze 10% HP bossa. */
    public const GUILD_BOSS_BLOCK_PCT = 0.10;

    /** Clamp tieru do [1, GUILD_BOSS_MAX_TIER]. Fractional → floor. */
    public static function clampGuildBossTier(int|float $tier): int
    {
        if (! is_finite($tier) || $tier < 1) {
            return 1;
        }
        if ($tier > self::GUILD_BOSS_MAX_TIER) {
            return self::GUILD_BOSS_MAX_TIER;
        }

        return (int) floor($tier);
    }

    /**
     * Skalowanie HP bossa: 2_000_000 × 1.25^(tier-1), tier dolnie clampowany do 1.
     * Bez górnego capa (górny cap to sprawa clampGuildBossTier u wołających).
     */
    public static function getGuildBossMaxHp(int|float $tier): int
    {
        $tBoss = max(1, $tier);

        return (int) floor(2_000_000 * (1.25 ** ($tBoss - 1)));
    }

    /**
     * XP by awansować z `level` → `level + 1`: level × HP(tier==clamp(level)).
     * „1 HP zadane = 1 XP gildii", więc koszt = N kill bossa na tier N.
     */
    public static function guildXpToNextLevel(int $level): int
    {
        if ($level <= 0) {
            return 0;
        }
        $tierForLevel = self::clampGuildBossTier($level);

        return (int) floor($level * self::getGuildBossMaxHp($tierForLevel));
    }

    /** Suma XP potrzebna, by osiągnąć `level` od poziomu 1 (pasek postępu). */
    public static function guildXpForLevel(int $level): int
    {
        $total = 0;
        for ($l = 1; $l < $level; $l++) {
            $total += self::guildXpToNextLevel($l);
        }

        return $total;
    }

    /** Limit członków z poziomu: 20 na poziomie 1, +1 za każdy poziom powyżej. */
    public static function guildMemberCap(int $level): int
    {
        return self::GUILD_INITIAL_MEMBER_CAP + max(0, $level - 1);
    }

    /**
     * Aplikuje zdobyte XP — może przeskoczyć wiele poziomów naraz. Bez górnego
     * limitu poziomu (port 1:1 TS — pętla zawsze terminuje, bo próg rośnie).
     *
     * @return array{level:int, xp:int, leveledUp:bool}
     */
    public static function applyGuildXp(int $currentLevel, int $currentXp, int $gain): array
    {
        $level = $currentLevel;
        $xp = $currentXp + max(0, $gain);
        $leveled = false;
        while ($xp >= self::guildXpToNextLevel($level)) {
            $xp -= self::guildXpToNextLevel($level);
            $level += 1;
            $leveled = true;
        }

        return ['level' => $level, 'xp' => $xp, 'leveledUp' => $leveled];
    }

    /**
     * Obrażenia zadane bossowi na atak postaci. Skalują się z poziomem postaci
     * (+level/120) oraz z tierem bossa (+5%/tier). Twardy cap = 5% max HP bossa,
     * więc jeden weteran nie zsoloi bossa (≥ 20 ciosów).
     */
    public static function computeGuildBossDamage(int|float $characterAttack, int|float $characterLevel, int|float $tier): int
    {
        $tBoss = max(1, $tier);
        $base = max(1, $characterAttack) * (1 + $characterLevel / 120);
        $scaled = $base * (1 + ($tBoss - 1) * 0.05);
        $cap = (int) floor(self::getGuildBossMaxHp($tier) * 0.05);

        return (int) max(1, min($cap, (int) floor($scaled)));
    }

    /**
     * Mnożnik nagrody z udziału w obrażeniach — ściśle proporcjonalny: 0.05× przy
     * „ledwie musnięciu" do 2.0× gdy jeden gracz zsoloi całego bossa. Krzywa
     * `max(0.05, 0.1 + share × 1.9)`, share = min(1, damage/bossMaxHp).
     */
    public static function contributionMultiplier(int|float $damageDealt, int|float $bossMaxHp): float
    {
        if ($bossMaxHp <= 0) {
            return 0.0;
        }
        $share = min(1, $damageDealt / $bossMaxHp);

        return max(0.05, 0.1 + $share * 1.9);
    }

    /**
     * Początek tygodnia (poniedziałek 00:00 UTC) dla danego znacznika czasu.
     * Klucz tygodniowego wiersza bossa + logu wkładów. Niedziela liczy się do
     * PONIEDZIAŁKU tego samego tygodnia (okno claim mieści się w tym `week_start`).
     */
    public static function getCurrentWeekStartIso(int $epochMs): string
    {
        $dt = self::utcFromMs($epochMs);
        // Mon=1..Sun=7 (ISO). PHP `w` daje Sun=0..Sat=6, więc niedzielę → 7.
        $dow = (int) $dt->format('w');
        $isoDow = $dow === 0 ? 7 : $dow;

        return $dt->modify('-'.($isoDow - 1).' days')->format('Y-m-d');
    }

    /** Czy trwa niedzielne okno claim? W niedzielę walka zablokowana, tylko claim. */
    public static function isGuildBossClaimDay(int $epochMs): bool
    {
        return (int) self::utcFromMs($epochMs)->format('w') === 0;
    }

    /** YYYY-MM-DD (UTC) dla per-atakowego klucza unikalności. */
    public static function getTodayIso(int $epochMs): string
    {
        return self::utcFromMs($epochMs)->format('Y-m-d');
    }

    /** Epoka w ms → DateTimeImmutable w UTC (odpowiednik new Date(ms) z TS). */
    private static function utcFromMs(int $epochMs): DateTimeImmutable
    {
        $seconds = intdiv($epochMs, 1000);

        return (new DateTimeImmutable('@'.$seconds))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * SERWEROWY roller nagród za pokonanie tygodniowego bossa gildii — port 1:1
     * `rollGuildBossRewards` + `applyRolledRewards` z frontu (Guild.tsx). Na
     * froncie nagrody mintowały się po stronie klienta (`Math.random` +
     * `addGold/addStones/...`) — tu CZYSTO liczymy strukturę nagrody z RNG
     * serwera, a KREDYTOWANIE (blob + XP postaci) robi kontroler.
     *
     * KOLEJNOŚĆ rolli 1:1 z TS (każdy `Math.random()` = jeden `nextFloat()`):
     *  1) gold: `0.8 + rand*0.4`, 2) gate kamienia rzadkiego, 3) gate epickiego,
     *  4) gate dropu itemu, 5) rarity itemu (tylko gdy drop). XP + kamień zwykły
     *  + potiony są deterministyczne (bez RNG). `$contribution` = mnożnik z
     *  `contributionMultiplier(total_damage, boss_max_hp)` (0.05..2.0).
     *
     * Skalowanie: gold `1_000_000 × tier × udział × (1 + level/50)`, XP
     * `50_000 × tier × udział × (1 + level/30)`. Item to placeholder (label,
     * BEZ realnego przedmiotu do bag — tak jak front: „loot grants happen via
     * gold/stones above"). Heroic dropi ≤ 1% (`GUILD_BOSS_HEROIC_MAX_CHANCE`).
     *
     * @return list<array<string, mixed>> Każdy wpis: {kind, icon, label, ...effect}.
     */
    public static function rollGuildBossRewards(int $tier, int $level, float $contribution, RngInterface $rng): array
    {
        $out = [];

        // Gold — zawsze. Skaluje się z tierem × udziałem × poziomem.
        $goldBase = 1_000_000 * $tier * $contribution * (1 + $level / 50);
        $goldAmount = (int) floor($goldBase * (0.8 + $rng->nextFloat() * 0.4));
        if ($goldAmount > 0) {
            $out[] = ['kind' => 'gold', 'icon' => 'money-bag', 'label' => self::formatGoldShort($goldAmount).' golda', 'gold' => $goldAmount];
        }

        // XP — dla postaci.
        $xpAmount = (int) floor(50_000 * $tier * $contribution * (1 + $level / 30));
        if ($xpAmount > 0) {
            $out[] = ['kind' => 'xp', 'icon' => 'star', 'label' => '+'.self::formatPlInt($xpAmount).' XP', 'xp' => $xpAmount];
        }

        // Kamień zwykły — zawsze.
        $commonStones = (int) max(1, floor(5 * $tier * $contribution));
        $out[] = ['kind' => 'stones', 'icon' => 'rock', 'label' => '+'.$commonStones.'× Kamień zwykły', 'stoneType' => 'common_stone', 'amount' => $commonStones];

        // Kamień rzadki — rosnąca szansa z tierem (cap 0.8).
        if ($rng->nextFloat() < min(0.8, 0.3 + $tier * 0.05)) {
            $rareStones = (int) max(1, floor(2 * $tier * $contribution));
            $out[] = ['kind' => 'stones', 'icon' => 'gem-stone', 'label' => '+'.$rareStones.'× Kamień rzadki', 'stoneType' => 'rare_stone', 'amount' => $rareStones];
        }

        // Kamień epicki — rosnąca szansa z tierem (cap 0.4).
        if ($rng->nextFloat() < min(0.4, 0.1 + $tier * 0.03)) {
            $epicStones = (int) max(1, floor(1 * $tier * $contribution));
            $out[] = ['kind' => 'stones', 'icon' => 'large-blue-diamond', 'label' => '+'.$epicStones.'× Kamień epicki', 'stoneType' => 'epic_stone', 'amount' => $epicStones];
        }

        // Potiony — mały flat HP/MP każdy claim.
        $potionCount = (int) max(1, floor(3 * $contribution));
        $out[] = ['kind' => 'potion', 'icon' => 'test-tube', 'label' => '+'.$potionCount.'× Mała mikstura HP + MP', 'consumables' => ['hp_potion_small' => $potionCount, 'mp_potion_small' => $potionCount]];

        // Drop itemu — szansa skaluje się z tierem × udziałem. Heroic cap 1%.
        $itemChance = min(0.95, 0.4 + $tier * 0.04);
        if ($rng->nextFloat() < $itemChance) {
            $r = $rng->nextFloat();
            $rarity = 'common';
            $heroicChance = min(self::GUILD_BOSS_HEROIC_MAX_CHANCE, $contribution * 0.01);
            if ($r < $heroicChance) {
                $rarity = 'heroic';
            } elseif ($r < 0.05) {
                $rarity = 'legendary';
            } elseif ($r < 0.2) {
                $rarity = 'epic';
            } elseif ($r < 0.5) {
                $rarity = 'rare';
            }
            $out[] = ['kind' => 'item', 'icon' => 'wrapped-gift', 'label' => 'Przedmiot '.strtoupper($rarity).' (lvl '.$level.')', 'rarity' => $rarity];
        }

        return $out;
    }

    /** Kompaktowy zapis golda (port formatGoldShort z frontu): sc/cc/k/gp, przecinek PL. */
    private static function formatGoldShort(int $gold): string
    {
        $g = max(0, $gold);
        if ($g >= 10_000_000) {
            return self::formatTwoDecimals($g / 10_000_000).' sc';
        }
        if ($g >= 100_000) {
            return self::formatTwoDecimals($g / 100_000).' cc';
        }
        if ($g >= 1_000) {
            return self::formatTwoDecimals($g / 1_000).' k';
        }

        return $g.' gp';
    }

    /** Obcięcie do 2 miejsc (nie zaokrąglenie) + przecinek PL — jak front. */
    private static function formatTwoDecimals(float $n): string
    {
        $truncated = floor($n * 100) / 100;

        return str_replace('.', ',', number_format($truncated, 2, '.', ''));
    }

    /** Liczba całkowita z separatorem tysięcy PL (spacja nierozdzielająca) — jak toLocaleString('pl-PL'). */
    private static function formatPlInt(int $n): string
    {
        return number_format($n, 0, ',', "\u{00A0}");
    }
}
