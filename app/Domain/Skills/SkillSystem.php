<?php

declare(strict_types=1);

namespace App\Domain\Skills;

use App\Domain\Support\Rng\RngInterface;

/**
 * Port 1:1 src/systems/skillSystem.ts (frontend). Krzywe XP skilli/statów,
 * treningi offline, koszty odblokowania/ulepszania spelli i spell-chestów.
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/skillSystem.json (generowane
 * z TS) są tu odtwarzane bajt-w-bajt (SkillSystemTest). Prawie wszystko jest
 * czyste/deterministyczne → bit-parity. Jedyny RNG to rollSkillUpgrade: bierze
 * RngInterface i konsumuje go DOKŁADNIE tak jak TS `Math.random` (1× nextFloat),
 * więc z tym samym seedem mulberry32 daje identyczny wynik.
 *
 * pow: krzywe używają `**` (1.8 / 2.2 / 1.15 / 1.08). Zweryfikowane bajt-w-bajt
 * z Math.pow V8 na tej platformie — te same wektory dają identyczne wyniki.
 *
 * ZERO Eloquent / mt_rand / random_int / now(). RNG tylko przez RngInterface.
 *
 * POMINIĘTE (świadomie): SKILL_NAMES_PL — etykiety UI (polskie nazwy do
 * wyświetlenia), nie logika. Warstwa prezentacji trzyma własne tłumaczenia.
 */
final class SkillSystem
{
    /** Maks. 24h zakumulowanego treningu offline. */
    public const MAX_OFFLINE_TRAINING_SECONDS = 24 * 60 * 60;

    /** Klasy zdobywające MLVL także z auto-ataków (magiczne). */
    public const MLVL_FROM_ATTACKS_CLASSES = ['Mage', 'Cleric', 'Necromancer'];

    /**
     * Mnożniki prędkości treningu offline per stat. Broń/magia = 1.0 (baseline),
     * potężne staty (crit/AS/regen) trenują dużo wolniej.
     *
     * @var array<string, float>
     */
    public const OFFLINE_TRAINING_SPEED_MULTIPLIER = [
        'sword_fighting' => 1.0,
        'shielding' => 1.0,
        'distance_fighting' => 1.0,
        'dagger_fighting' => 1.0,
        'magic_level' => 1.0,
        'bard_level' => 1.0,
        'max_hp' => 0.6,
        'max_mp' => 0.6,
        'defense' => 0.5,
        'mp_regen' => 0.15,
        'hp_regen' => 0.15,
        'crit_chance' => 0.12,
        'crit_dmg' => 0.12,
        'attack_speed' => 0.1,
    ];

    /** @var array<string, list<string>> weapon skille per klasa */
    public const CLASS_WEAPON_SKILLS = [
        'Knight' => ['sword_fighting', 'shielding'],
        'Mage' => ['magic_level'],
        'Cleric' => ['magic_level'],
        'Archer' => ['distance_fighting'],
        'Rogue' => ['dagger_fighting'],
        'Necromancer' => ['magic_level'],
        'Bard' => ['bard_level'],
    ];

    /** @var array<string, string> pojedynczy główny weapon skill per klasa */
    public const CLASS_WEAPON_SKILL = [
        'Knight' => 'sword_fighting',
        'Mage' => 'magic_level',
        'Cleric' => 'magic_level',
        'Archer' => 'distance_fighting',
        'Rogue' => 'dagger_fighting',
        'Necromancer' => 'magic_level',
        'Bard' => 'bard_level',
    ];

    /** @var list<string> wszystkie ID weapon skilli (jeden per klasa) */
    public const ALL_WEAPON_SKILL_IDS = [
        'sword_fighting', 'shielding', 'distance_fighting', 'dagger_fighting', 'magic_level', 'bard_level',
    ];

    /** @var list<string> ogólne trenowalne staty dostępne dla WSZYSTKICH klas */
    public const GENERAL_TRAINABLE_STATS = [
        'attack_speed', 'max_hp', 'max_mp', 'hp_regen', 'mp_regen', 'defense', 'crit_chance', 'crit_dmg',
    ];

    /**
     * @deprecated Użyj getTrainableStatsForClass. Zachowane dla parytetu z TS.
     *
     * @var list<string>
     */
    public const ALL_TRAINABLE_STATS = [
        'sword_fighting', 'shielding', 'distance_fighting', 'dagger_fighting', 'magic_level', 'bard_level',
        'attack_speed', 'max_hp', 'max_mp', 'hp_regen', 'mp_regen', 'defense', 'crit_chance', 'crit_dmg',
    ];

    /** @var list<int> poziomy odblokowania spelli (rosnąco) — źródło dla spell chestów */
    public const SPELL_CHEST_LEVELS = [5, 10, 20, 30, 40, 50, 60, 70, 80, 100, 150, 300, 600, 800, 1000];

    /** @var array<int, int> tabela szans powodzenia ulepszenia spella */
    private const UPGRADE_SUCCESS_RATES = [
        1 => 100, 2 => 90, 3 => 75, 4 => 60, 5 => 45, 6 => 30, 7 => 20, 8 => 15, 9 => 10, 10 => 3,
    ];

    /** @var array<int, array{chests:int, gold:int, successRate:int}> tabela kosztu spell-chest upgrade */
    private const SPELL_CHEST_UPGRADE_TABLE = [
        1 => ['chests' => 1, 'gold' => 100, 'successRate' => 100],
        2 => ['chests' => 1, 'gold' => 500, 'successRate' => 90],
        3 => ['chests' => 2, 'gold' => 1500, 'successRate' => 75],
        4 => ['chests' => 3, 'gold' => 5000, 'successRate' => 60],
        5 => ['chests' => 4, 'gold' => 15000, 'successRate' => 45],
        6 => ['chests' => 5, 'gold' => 50000, 'successRate' => 30],
        7 => ['chests' => 7, 'gold' => 150000, 'successRate' => 20],
        8 => ['chests' => 10, 'gold' => 500000, 'successRate' => 15],
        9 => ['chests' => 15, 'gold' => 1500000, 'successRate' => 10],
        10 => ['chests' => 20, 'gold' => 5000000, 'successRate' => 5],
    ];

    /** @var array<string, float> HP regen z treningu per klasa (na poziom) */
    private const CLASS_HP_REGEN_RATE = [
        'Knight' => 0.20, 'Mage' => 0.05, 'Cleric' => 0.15, 'Archer' => 0.10,
        'Rogue' => 0.08, 'Necromancer' => 0.06, 'Bard' => 0.12,
    ];

    /** @var array<string, float> MP regen z treningu per klasa (na poziom) */
    private const CLASS_MP_REGEN_RATE = [
        'Knight' => 0.05, 'Mage' => 0.20, 'Cleric' => 0.18, 'Archer' => 0.08,
        'Rogue' => 0.06, 'Necromancer' => 0.18, 'Bard' => 0.15,
    ];

    // ---- Krzywa XP skilli (broń/magia — bez capa) ---------------------------

    /** XP potrzebne, by awansować z `skillLevel` na kolejny: ceil(100 * lvl^1.8). */
    public static function skillXpToNextLevel(int $skillLevel): int
    {
        if ($skillLevel <= 0) {
            return 100;
        }

        return (int) ceil(100 * ($skillLevel ** 1.8));
    }

    /** XP z trafienia bronią, lekko malejące z poziomem. */
    public static function skillXpPerHit(int $skillLevel): int
    {
        return max(1, (int) floor(10 / (1 + $skillLevel * 0.05)));
    }

    /** XP z rzucenia czaru (magia). */
    public static function skillXpPerCast(int $skillLevel): int
    {
        return max(1, (int) floor(15 / (1 + $skillLevel * 0.05)));
    }

    // ---- MLVL z auto-ataków (tylko klasy magiczne) --------------------------

    /** MLVL XP z auto-ataku (klasy magiczne). */
    public static function mlvlXpPerAttack(int $mlvl): int
    {
        return max(1, (int) floor(8 / (1 + $mlvl * 0.04)));
    }

    /** MLVL XP z użycia skilla — klasy magiczne pełny rate, reszta 1/3. */
    public static function mlvlXpPerSkillUse(int $mlvl, string $characterClass): int
    {
        $base = max(1, (int) floor(12 / (1 + $mlvl * 0.04)));
        $isMagicClass = in_array($characterClass, self::MLVL_FROM_ATTACKS_CLASSES, true);

        return $isMagicClass ? $base : max(1, (int) floor($base / 3));
    }

    /** Czy klasa zdobywa MLVL z auto-ataków. */
    public static function doesClassGainMlvlFromAttacks(string $cls): bool
    {
        return in_array($cls, self::MLVL_FROM_ATTACKS_CLASSES, true);
    }

    // ---- Skill Obrona Tarczą (Knight) ---------------------------------------

    /** Shielding XP za udany blok. */
    public static function shieldingXpPerBlock(int $shieldingLevel): int
    {
        return max(1, (int) floor(15 / (1 + $shieldingLevel * 0.06)));
    }

    /** Bonus DEF z poziomu Shielding: floor(level/2). */
    public static function getShieldingDefBonus(int $shieldingLevel): int
    {
        return (int) floor($shieldingLevel / 2);
    }

    /** Bonus do szansy bloku z poziomu Shielding: +0.5% / poziom. */
    public static function getShieldingBlockBonus(int $shieldingLevel): float
    {
        return $shieldingLevel * 0.005;
    }

    // ---- Trening offline ----------------------------------------------------

    /** Bazowy rate XP/s treningu offline, malejący z poziomem. */
    public static function offlineXpRate(int|float $skillLevel): float
    {
        return max(0.05, 2.0 / (1 + $skillLevel * 0.1));
    }

    /** Rate XP/s dla konkretnego statu (uwzględnia trudność statu; fallback 0.5). */
    public static function offlineXpRateForStat(int|float $skillLevel, string $skillId): float
    {
        $baseRate = self::offlineXpRate($skillLevel);
        $multiplier = self::OFFLINE_TRAINING_SPEED_MULTIPLIER[$skillId] ?? 0.5;

        return $baseRate * $multiplier;
    }

    /**
     * Zdobyte XP offline z symulacją level-upów w trakcie (rate zwalnia mid-session).
     * Bez skillId → legacy flat. Z skillId → symulacja w chunkach po 60s.
     */
    public static function calculateOfflineSkillXp(int $elapsedSeconds, int $skillLevel, ?string $skillId = null): int
    {
        $cappedSeconds = min($elapsedSeconds, self::MAX_OFFLINE_TRAINING_SECONDS);

        if ($skillId === null) {
            // Legacy fallback: płaskie liczenie bez mnożnika statu.
            return (int) floor($cappedSeconds * self::offlineXpRate($skillLevel));
        }

        $chunkSize = 60;
        $currentLevel = $skillLevel;
        $currentXp = 0.0;
        $totalXpGained = 0.0;
        $remainingSeconds = $cappedSeconds;

        while ($remainingSeconds > 0) {
            $chunk = min($remainingSeconds, $chunkSize);
            $rate = self::offlineXpRateForStat($currentLevel, $skillId);
            $xpThisChunk = $chunk * $rate;
            $totalXpGained += $xpThisChunk;
            $currentXp += $xpThisChunk;
            $remainingSeconds -= $chunk;

            $needed = self::skillXpToNextLevel($currentLevel);
            while ($currentXp >= $needed) {
                $currentXp -= $needed;
                $currentLevel++;
            }
        }

        return (int) floor($totalXpGained);
    }

    // ---- Przetwarzanie XP / kary --------------------------------------------

    /**
     * Przetwarza zdobyte XP — może wywołać wiele level-upów naraz.
     *
     * @return array{newLevel:int, remainingXp:int, levelsGained:int}
     */
    public static function processSkillXp(int $currentLevel, int $currentXp, int $xpGained): array
    {
        $level = $currentLevel;
        $xp = $currentXp + $xpGained;
        $levelsGained = 0;

        while ($xp >= self::skillXpToNextLevel($level)) {
            $xp -= self::skillXpToNextLevel($level);
            $level++;
            $levelsGained++;
        }

        return ['newLevel' => $level, 'remainingXp' => $xp, 'levelsGained' => $levelsGained];
    }

    /** Kara śmierci dla skilla: −5% XP kolejnego poziomu, min 0. */
    public static function applySkillDeathPenalty(int $currentXp, int $skillLevel): int
    {
        $penalty = (int) floor(self::skillXpToNextLevel($skillLevel) * 0.05);

        return max(0, $currentXp - $penalty);
    }

    /** Bonus obrażeń z poziomu skilla: level * damageBonus (damageBonus z skills.json). */
    public static function getSkillDamageBonus(int $skillLevel, float $damageBonus): float
    {
        return $skillLevel * $damageBonus;
    }

    // ---- Mapy klasa → skille ------------------------------------------------

    /**
     * @return list<string>
     */
    public static function getClassWeaponSkills(string $cls): array
    {
        return self::CLASS_WEAPON_SKILLS[$cls] ?? [];
    }

    /**
     * Trenowalne staty dla klasy: weapon skille klasy + ogólne staty.
     *
     * @return list<string>
     */
    public static function getTrainableStatsForClass(string $cls): array
    {
        $weaponSkills = self::CLASS_WEAPON_SKILLS[$cls] ?? [];

        return [...$weaponSkills, ...self::GENERAL_TRAINABLE_STATS];
    }

    /**
     * Efektywne bonusy statów z wytrenowanych poziomów (mapa skillId → poziom).
     * Regen HP/MP skalowany per klasa (fallback 0.1 gdy brak klasy / nieznana).
     *
     * @param  array<string, int>  $skillLevels
     * @return array{attack_speed:float, max_hp:int|float, max_mp:int|float, hp_regen:float, mp_regen:float, defense:int, crit_chance:float, crit_dmg:float}
     */
    public static function getTrainingBonuses(array $skillLevels, ?string $characterClass = null): array
    {
        $hasClass = $characterClass !== null && $characterClass !== '';
        $hpRate = $hasClass ? (self::CLASS_HP_REGEN_RATE[$characterClass] ?? 0.1) : 0.1;
        $mpRate = $hasClass ? (self::CLASS_MP_REGEN_RATE[$characterClass] ?? 0.1) : 0.1;

        return [
            'attack_speed' => ($skillLevels['attack_speed'] ?? 0) * 0.1,
            'max_hp' => ($skillLevels['max_hp'] ?? 0) * 5,
            'max_mp' => ($skillLevels['max_mp'] ?? 0) * 5,
            'hp_regen' => ($skillLevels['hp_regen'] ?? 0) * $hpRate,
            'mp_regen' => ($skillLevels['mp_regen'] ?? 0) * $mpRate,
            'defense' => ($skillLevels['defense'] ?? 0),
            'crit_chance' => ($skillLevels['crit_chance'] ?? 0) * 0.005,
            'crit_dmg' => ($skillLevels['crit_dmg'] ?? 0) * 0.02,
        ];
    }

    /** Postęp XP w obrębie bieżącego poziomu skilla (0–1). */
    public static function skillXpProgress(int|float $currentXp, int $skillLevel): float
    {
        $needed = self::skillXpToNextLevel($skillLevel);

        return $needed > 0 ? min(1, $currentXp / $needed) : 0;
    }

    // ---- Koszty odblokowania / ulepszania -----------------------------------

    /** Koszt złota za odblokowanie active skilla: floor(100 * lvl^1.8). */
    public static function getSkillUnlockCost(int $unlockLevel): int
    {
        if ($unlockLevel <= 0) {
            return 100;
        }

        return (int) floor(100 * ($unlockLevel ** 1.8));
    }

    /**
     * Koszt + szansa ulepszenia dla docelowego poziomu. Gold = floor(200 * lvl^2.2).
     *
     * @return array{gold:int, successRate:int|float}
     */
    public static function getSkillUpgradeCost(int $targetLevel): array
    {
        $gold = (int) floor(200 * ($targetLevel ** 2.2));

        if ($targetLevel <= 10) {
            return ['gold' => $gold, 'successRate' => self::UPGRADE_SUCCESS_RATES[$targetLevel] ?? 100];
        }

        // Powyżej +10: success * 0.5 na każdy poziom ponad 10 (min 0.1%).
        $levelsAbove10 = $targetLevel - 10;

        return ['gold' => $gold, 'successRate' => max(0.1, 3 * (0.5 ** $levelsAbove10))];
    }

    /**
     * Bonus DMG/Heal per poziom ulepszenia (mnożnik addytywny; +1.15 = +115%).
     * Poziomy 1-10 = 1.15^level; 11+ = 1.15^10 * 1.08^(level-10) na wierzchu.
     */
    public static function getSkillUpgradeBonus(int $upgradeLevel): float
    {
        if ($upgradeLevel <= 0) {
            return 0;
        }

        $mult = $upgradeLevel <= 10
            ? (1.15 ** $upgradeLevel)
            : ((1.15 ** 10) * (1.08 ** ($upgradeLevel - 10)));

        return $mult - 1;
    }

    /**
     * Bojowy mnożnik ulepszenia skilla — SKROMNY & CAPOWANY (aplikowany raz do
     * finalnych obrażeń skilla). +1→+2% (do +10), potem +1%/poziom.
     */
    public static function getCombatSkillUpgradeMultiplier(int $upgradeLevel): float
    {
        return $upgradeLevel <= 0
            ? 1
            : 1 + min($upgradeLevel, 10) * 0.02 + max(0, $upgradeLevel - 10) * 0.01;
    }

    // ---- Spell Chest System -------------------------------------------------

    /**
     * Koszt spell-chest + złota za odblokowanie active skilla: 1 chest poziomu
     * unlockLevel + zredukowane złoto (1/5 starego kosztu).
     *
     * @return array{chests:int, chestLevel:int, gold:int}
     */
    public static function getSpellChestUnlockCost(int $unlockLevel): array
    {
        $gold = (int) floor(self::getSkillUnlockCost($unlockLevel) / 5);

        return ['chests' => 1, 'chestLevel' => $unlockLevel, 'gold' => $gold];
    }

    /**
     * Koszt spell-chest ulepszenia dla docelowego poziomu. chestLevel = unlockLevel
     * ulepszanego skilla.
     *
     * @return array{chests:int, chestLevel:int, gold:int, successRate:int|float}
     */
    public static function getSpellChestUpgradeCost(int $targetLevel, int $skillUnlockLevel): array
    {
        if ($targetLevel <= 10) {
            $entry = self::SPELL_CHEST_UPGRADE_TABLE[$targetLevel]
                ?? ['chests' => 20, 'gold' => 5_000_000, 'successRate' => 5];

            return [
                'chests' => $entry['chests'],
                'chestLevel' => $skillUnlockLevel,
                'gold' => $entry['gold'],
                'successRate' => $entry['successRate'],
            ];
        }

        // Powyżej +10: chests*2, gold*2, success*0.5 na poziom ponad 10 (min 0.1%).
        $levelsAbove10 = $targetLevel - 10;
        $baseChests = 20;
        $baseGold = 5_000_000;
        $baseSuccess = 5;

        return [
            'chests' => (int) floor($baseChests * (2 ** $levelsAbove10)),
            'chestLevel' => $skillUnlockLevel,
            'gold' => (int) floor($baseGold * (2 ** $levelsAbove10)),
            'successRate' => max(0.1, $baseSuccess * (0.5 ** $levelsAbove10)),
        ];
    }

    /**
     * Próba ulepszenia skilla — true gdy powodzenie. RNG w tej samej kolejności
     * co TS (`Math.random() * 100 < successRate`), więc seeded golden = parytet.
     */
    public static function rollSkillUpgrade(RngInterface $rng, int $targetLevel): bool
    {
        $successRate = self::getSkillUpgradeCost($targetLevel)['successRate'];

        return $rng->nextFloat() * 100 < $successRate;
    }
}
