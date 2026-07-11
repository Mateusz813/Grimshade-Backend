<?php

declare(strict_types=1);

namespace App\Domain\Party;

use App\Domain\Support\Rng\RngInterface;

/**
 * Port 1:1 src/systems/partySystem.ts (frontend). Party max 4: mnożniki
 * (drop/xp/difficulty per rozmiar), buffy klasowe, podział XP/gold, level
 * gating, ważona agresja. Wszystko czyste formuły — RNG tylko przez
 * RngInterface (pickWeightedAggroTarget woła nextFloat DOKŁADNIE raz).
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/partySystem.json (generowane
 * z TS) są tu odtwarzane (PartySystemTest). Zmiana formuły w TS regeneruje
 * fixture i wymusza aktualizację tu.
 *
 * ŚWIADOMIE POMINIĘTE (glue/UI, brak parytetu):
 *  - generatePartyId() — `Math.random().toString(36)` (base36 z floata
 *    nieodtwarzalny bajt-w-bajt); serwer nadaje własne id.
 *  - pole `id` w createBotHelper (Date.now + Math.random base36) — jw. Metoda
 *    zwraca deterministyczny kontrakt (klasa/poziom/hp/nazwa), którego używa
 *    logika party/combat; id dokleja warstwa serwera.
 */
final class PartySystem
{
    public const MAX_PARTY_SIZE = 4;

    /**
     * Buffy klasowe dostępne w party.
     *
     * @var array<string, array{id:string, name:string, sourceClass:string, effect:string, value:float, duration:int}>
     */
    public const CLASS_PARTY_BUFFS = [
        'Cleric' => ['id' => 'cleric_heal', 'name' => 'Holy Light', 'sourceClass' => 'Cleric', 'effect' => 'heal', 'value' => 0.15, 'duration' => 3],
        'Bard' => ['id' => 'bard_atk', 'name' => 'Inspiring Melody', 'sourceClass' => 'Bard', 'effect' => 'atk_boost', 'value' => 0.10, 'duration' => 5],
        'Knight' => ['id' => 'knight_def', 'name' => 'Battle Cry', 'sourceClass' => 'Knight', 'effect' => 'def_boost', 'value' => 0.10, 'duration' => 5],
    ];

    /**
     * Waga aggro per klasa. Wyższa = częściej wybierany jako cel potwora.
     *
     * @var array<string, int>
     */
    public const AGGRO_CLASS_WEIGHTS = [
        'Knight' => 80,
        'Rogue' => 60,
        'Archer' => 50,
        'Necromancer' => 40,
        'Mage' => 30,
        'Cleric' => 20,
        'Bard' => 20,
    ];

    /**
     * Nazwy botów — dobierane do klasy bota (createBotHelper wybiera tylko
     * Knight/Cleric/Mage/Archer). Plain text (bez emoji shortcodes).
     *
     * @var array<string, string>
     */
    private const BOT_NAMES = [
        'Knight' => 'Bot Pancerny',
        'Cleric' => 'Bot Lecznik',
        'Archer' => 'Bot Łucznik',
        'Mage' => 'Bot Mag',
    ];

    // ---- Mnożniki (per rozmiar party) ---------------------------------------

    private static function clampSize(int $partySize): int
    {
        return (int) max(1, min($partySize, self::MAX_PARTY_SIZE));
    }

    /** Mnożnik drop rate — +0.5% za każdego dodatkowego sojusznika. */
    public static function calculateDropMultiplier(int $partySize): float
    {
        $size = self::clampSize($partySize);

        return 1 + ($size - 1) * 0.005;
    }

    /** Mnożnik XP — +6.5% za każdego dodatkowego sojusznika. */
    public static function calculateXpMultiplier(int $partySize): float
    {
        $size = self::clampSize($partySize);

        return 1 + ($size - 1) * 0.065;
    }

    /** Mnożnik trudności potwora — +20% za każdego dodatkowego sojusznika. */
    public static function calculateDifficultyMultiplier(int $partySize): float
    {
        $size = self::clampSize($partySize);

        return 1 + ($size - 1) * 0.2;
    }

    // ---- Capacity helpers ---------------------------------------------------

    public static function canJoinParty(int $currentSize): bool
    {
        return $currentSize < self::MAX_PARTY_SIZE;
    }

    /**
     * @param  list<array<string, mixed>>  $members  TS czyta tylko party.members.length
     */
    public static function isFull(array $members): bool
    {
        return count($members) >= self::MAX_PARTY_SIZE;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    public static function getHumanCount(array $members): int
    {
        return count(array_filter($members, static fn (array $m): bool => empty($m['isBot'])));
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    public static function getBotCount(array $members): int
    {
        return count(array_filter($members, static fn (array $m): bool => ! empty($m['isBot'])));
    }

    /**
     * Sugeruj dodanie bota, gdy jest mniej niż 2 graczy-ludzi.
     *
     * @param  list<array<string, mixed>>  $members
     */
    public static function shouldSuggestBot(array $members): bool
    {
        return self::getHumanCount($members) < 2;
    }

    // ---- Bot helper factory -------------------------------------------------

    /**
     * Tworzy bota dobranego do najsłabszej roli party. Zwraca deterministyczny
     * kontrakt (bez `id` — patrz nagłówek klasy).
     *
     * @param  list<array{class:string, level:int|float}>  $members
     * @return array{name:string, class:string, level:int, hp:int, maxHp:int, isBot:bool, isOnline:bool}
     */
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

    // ---- Podział XP / gold --------------------------------------------------

    /** XP dla każdego członka przy równym podziale. */
    public static function getXpShare(int $totalXp, int $partySize): int
    {
        return (int) floor($totalXp / max(1, $partySize));
    }

    /** Gold dla każdego członka przy równym podziale. */
    public static function getGoldShare(int $totalGold, int $partySize): int
    {
        return (int) floor($totalGold / max(1, $partySize));
    }

    // ---- Podsumowanie party -------------------------------------------------

    /**
     * @param  list<array{level:int|float, isBot?:bool}>  $members
     * @return array{totalMembers:int, humanMembers:int, botMembers:int, avgLevel:int, dropMultiplier:float, xpMultiplier:float, difficultyMultiplier:float}
     */
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

    // ---- Party combat & buffs ----------------------------------------------

    /**
     * Ile pomagają skończeni członkowie — helper zadaje 50% swojego ataku.
     * Drugi argument (pozostałe HP potwora) celowo nieużywany (parytet z TS).
     */
    public static function calculateHelpDamage(int|float $finishedMemberAttack, int|float $remainingMonsterHp): int
    {
        return (int) floor($finishedMemberAttack * 0.5);
    }

    /**
     * Aktywne buffy party na podstawie klas członków (kolejność wejścia).
     *
     * @param  list<string>  $memberClasses
     * @return list<array{id:string, name:string, sourceClass:string, effect:string, value:float, duration:int}>
     */
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

    /**
     * Aplikuje efekty buffów na staty.
     *
     * @param  list<array{effect:string, value:float}>  $buffs
     * @return array{attack:int|float, defense:int|float, healPerRound:int}
     */
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

    /**
     * Czy party ma optymalną kompozycję (≥ 3 różne klasy).
     *
     * @param  list<string>  $memberClasses
     */
    public static function hasOptimalComposition(array $memberClasses): bool
    {
        return count(array_unique($memberClasses)) >= 3;
    }

    /**
     * Mnożnik kompozycji (dodatkowe XP/gold za różnorodność party).
     *
     * @param  list<string>  $memberClasses
     */
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

    // ---- Level gating -------------------------------------------------------

    /**
     * Efektywny poziom party do bramkowania treści — blokuje NAJSŁABSZY
     * człowiek. Boty pominięte (auto-skalują się do średniej ludzi).
     *
     * @param  list<array{level:int|float, isBot?:bool}>|null  $members
     */
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

    /**
     * Efektywny cap odblokowanych potworów party = MIN po wszystkich ludziach
     * (z presence). Boty i self pominięte; brak snapshotu = pominięty.
     *
     * @param  list<array{id:string, isBot?:bool}>|null  $members
     * @param  array<string, array{maxUnlockedMonsterLevel?:int|float}>  $presenceByMember
     */
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

    // ---- Aggro --------------------------------------------------------------

    /** Waga aggro dla klasy (domyślnie 30 dla nieznanej). */
    public static function getAggroWeight(string $cls): int
    {
        return self::AGGRO_CLASS_WEIGHTS[$cls] ?? 30;
    }

    /**
     * Losuje cel z ważonej listy wg klasy. Konsumuje RNG DOKŁADNIE raz (tylko
     * gdy lista niepusta i suma wag > 0) — jak TS `Math.random()`.
     *
     * @param  list<array{id:string, class:string}>  $targets
     */
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
