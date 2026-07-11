<?php

declare(strict_types=1);

namespace App\Domain\Transform;

/**
 * Port src/systems/transformSystem.ts. Logika progresji transformacji: skalowanie
 * potworów questowych, nagrody, kumulatywne bonusy klasowe, kolejność transformacji.
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/transformSystem.json (generowane
 * z TS) odtwarzane tu bajt-w-bajt (TransformSystemTest). Metody czyste są static;
 * metody czytające treść gry (transforms.json / monsters.json) są instancyjne —
 * konstruktor bierze te same tablice co front (z ContentRepository).
 *
 * ŚWIADOMIE POMINIĘTE (UI / RNG / inny system — patrz golden test docblock):
 *  - getTransformColor: kolory/CSS wyświetlania (UI),
 *  - getTransformWaveLineup: buduje spriteImageUrl przez getMonsterImage (assety
 *    UI); jego rdzeń liczbowy (skalowanie escortów) pokrywa applyTransformTierStats
 *    + scaleMonsterStats,
 *  - weapon w calculateTransformRewards: generateWeapon = RNG z systemu
 *    itemGenerator (poza zakresem tego systemu). Portujemy część deterministyczną:
 *    calculateTransformRewardsDeterministic (consumables + permanentBonuses).
 *
 * SPELL_CHEST_LEVELS to stała kodu z src/systems/skillSystem.ts (nie treść gry) —
 * trzymana tu jako const z odwołaniem do źródła.
 */
final class TransformSystem
{
    /** Mnożnik BOSS dla potwora questu transformacji (slot 3 fali). */
    public const TRANSFORM_BOSS_MULTIPLIER = ['hp' => 5.0, 'atk' => 3.0, 'def' => 3.0];

    /** @var array<string, array{hp:float, atk:float, def:float}> mnożniki per slot fali */
    public const TRANSFORM_TIER_MULTIPLIERS = [
        'Normal' => ['hp' => 1.0, 'atk' => 1.0, 'def' => 1.0],
        'Strong' => ['hp' => 2.0, 'atk' => 1.5, 'def' => 1.3],
        'Epic' => ['hp' => 4.0, 'atk' => 2.5, 'def' => 1.8],
        'Boss' => ['hp' => 5.0, 'atk' => 3.0, 'def' => 3.0],
    ];

    /** @var list<string> slot index → tier */
    public const TRANSFORM_SLOT_TIERS = ['Normal', 'Strong', 'Epic', 'Boss'];

    /**
     * Poziomy istniejących spell chestów (z src/systems/skillSystem.ts). Nagroda
     * spellChestLevel jest „snapowana" w górę do najbliższego istniejącego poziomu.
     *
     * @var list<int>
     */
    public const SPELL_CHEST_LEVELS = [5, 10, 20, 30, 40, 50, 60, 70, 80, 100, 150, 300, 600, 800, 1000];

    /** @var array<string, int|float> wszystkie 14 pól zerowe */
    private const EMPTY_BONUSES = [
        'hpPercent' => 0, 'mpPercent' => 0, 'defPercent' => 0, 'dmgPercent' => 0,
        'atkPercent' => 0, 'flatHp' => 0, 'flatMp' => 0, 'attack' => 0, 'defense' => 0,
        'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0, 'mpRegenFlat' => 0, 'classSkillBonus' => 0,
    ];

    /**
     * Bazowe (tier 1) bonusy per klasa. Późniejsze transformacje skalują pola flat
     * przez getTransformTierMultiplier. Kopia 1:1 CLASS_TRANSFORM_BONUSES z TS.
     *
     * @var array<string, array<string, int|float>>
     */
    private const CLASS_TRANSFORM_BONUSES = [
        'Mage' => [
            'dmgPercent' => 8, 'hpPercent' => 2, 'mpPercent' => 3, 'defPercent' => 1, 'atkPercent' => 0,
            'flatHp' => 150, 'flatMp' => 400, 'attack' => 13, 'defense' => 3,
            'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0.2, 'mpRegenFlat' => 0.5, 'classSkillBonus' => 0,
        ],
        'Cleric' => [
            'dmgPercent' => 5, 'hpPercent' => 3, 'mpPercent' => 3, 'defPercent' => 2, 'atkPercent' => 0,
            'flatHp' => 220, 'flatMp' => 380, 'attack' => 10, 'defense' => 10,
            'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0.5, 'mpRegenFlat' => 0.4, 'classSkillBonus' => 0,
        ],
        'Necromancer' => [
            'dmgPercent' => 7, 'hpPercent' => 2, 'mpPercent' => 3, 'defPercent' => 1, 'atkPercent' => 0,
            'flatHp' => 180, 'flatMp' => 380, 'attack' => 12, 'defense' => 5,
            'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0.25, 'mpRegenFlat' => 0.4, 'classSkillBonus' => 0,
        ],
        'Archer' => [
            'dmgPercent' => 7, 'hpPercent' => 2, 'mpPercent' => 1, 'defPercent' => 1, 'atkPercent' => 7,
            'flatHp' => 220, 'flatMp' => 150, 'attack' => 0, 'defense' => 5,
            'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0.3, 'mpRegenFlat' => 0.2, 'classSkillBonus' => 0,
        ],
        'Rogue' => [
            'dmgPercent' => 7, 'hpPercent' => 2, 'mpPercent' => 1, 'defPercent' => 1, 'atkPercent' => 0,
            'flatHp' => 190, 'flatMp' => 150, 'attack' => 15, 'defense' => 4,
            'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0.3, 'mpRegenFlat' => 0.2, 'classSkillBonus' => 0,
        ],
        'Bard' => [
            'dmgPercent' => 5, 'hpPercent' => 3, 'mpPercent' => 3, 'defPercent' => 2, 'atkPercent' => 0,
            'flatHp' => 230, 'flatMp' => 260, 'attack' => 10, 'defense' => 9,
            'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0.4, 'mpRegenFlat' => 0.3, 'classSkillBonus' => 0,
        ],
        'Knight' => [
            'dmgPercent' => 3, 'hpPercent' => 4, 'mpPercent' => 1, 'defPercent' => 3, 'atkPercent' => 0,
            'flatHp' => 420, 'flatMp' => 70, 'attack' => 9, 'defense' => 16,
            'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0.5, 'mpRegenFlat' => 0.1, 'classSkillBonus' => 0,
        ],
    ];

    /** @var list<array<string, mixed>> transforms.json */
    private array $transforms;

    /** @var list<array<string, mixed>> monsters.json, posortowane rosnąco po poziomie */
    private array $sortedMonsters;

    /** @var array<int, list<array<string, mixed>>> cache wygenerowanych potworów per transform */
    private array $monsterCache = [];

    /**
     * @param  list<array<string, mixed>>  $transforms  pełna lista z transforms.json
     * @param  list<array<string, mixed>>  $monsters  pełna lista z monsters.json
     */
    public function __construct(array $transforms, array $monsters)
    {
        $this->transforms = array_values($transforms);

        $sorted = array_values($monsters);
        // Stabilny sort (PHP 8+) — tie na level zachowuje kolejność z pliku, jak JS.
        usort($sorted, static fn (array $a, array $b): int => $a['level'] <=> $b['level']);
        $this->sortedMonsters = $sorted;
    }

    // -- Pure (bez treści) -----------------------------------------------------

    /**
     * Mnożnik tieru dla danego id transformacji. T1 → 1.0, T6 → 2.5, T11 → 4.0.
     */
    public static function getTransformTierMultiplier(int $transformId): float
    {
        if ($transformId < 1) {
            return 1.0;
        }

        return 1 + ($transformId - 1) * 0.3;
    }

    /**
     * Bonusy per klasa; z transformId pola flat skalowane przez tier. Bez id → baza.
     *
     * @return array<string, int|float>
     */
    public static function getClassTransformBonuses(string $characterClass, ?int $transformId = null): array
    {
        $base = self::CLASS_TRANSFORM_BONUSES[$characterClass];
        if ($transformId === null || $transformId < 1) {
            return $base;
        }

        $mult = self::getTransformTierMultiplier($transformId);

        return array_merge($base, [
            'flatHp' => (int) floor($base['flatHp'] * $mult),
            'flatMp' => (int) floor($base['flatMp'] * $mult),
            'attack' => (int) floor($base['attack'] * $mult),
            'defense' => (int) floor($base['defense'] * $mult),
            'hpRegenFlat' => self::jsRound($base['hpRegenFlat'] * $mult * 10) / 10,
            'mpRegenFlat' => self::jsRound($base['mpRegenFlat'] * $mult * 10) / 10,
        ]);
    }

    /**
     * Skalowanie statystyk potwora (BAZA, przed mnożnikiem boss). Formuły z CLAUDE.md.
     *
     * @return array{hp:int, attack:int, attack_min:int, attack_max:int, defense:int, xp:int}
     */
    public static function scaleMonsterStats(int|float $level): array
    {
        // CAPSTONE: finalny transform (T11, poziomy 901-1000) dostaje ×3.5 HP.
        $capstone = $level >= 901 ? 3.5 : 1;
        $hp = (int) floor((95 * ($level ** 1.1) + 30) * $capstone);
        $dmgBase = 8 + $level * 1.0;
        $attack = (int) floor($dmgBase);
        $attackMin = (int) max(1, floor($dmgBase * 0.8));
        $attackMax = (int) max($attackMin, floor($dmgBase * 1.2));
        $defense = (int) floor($level * 0.4);
        $xp = (int) floor($level * 15 + ($level ** 1.5) * 2);

        return [
            'hp' => $hp,
            'attack' => $attack,
            'attack_min' => $attackMin,
            'attack_max' => $attackMax,
            'defense' => $defense,
            'xp' => $xp,
        ];
    }

    /**
     * Aplikuje mnożniki BOSS na potwora. Zwraca kopię ze skalowanymi statystykami.
     *
     * @param  array<string, mixed>  $monster
     * @return array<string, mixed>
     */
    public static function applyTransformBossStats(array $monster): array
    {
        return self::applyMultiplier($monster, self::TRANSFORM_BOSS_MULTIPLIER);
    }

    /**
     * Aplikuje mnożnik tieru (Normal/Strong/Epic/Boss) na bazowego potwora.
     *
     * @param  array<string, mixed>  $monster
     * @return array<string, mixed>
     */
    public static function applyTransformTierStats(array $monster, string $tier): array
    {
        return self::applyMultiplier($monster, self::TRANSFORM_TIER_MULTIPLIERS[$tier]);
    }

    /**
     * @param  array<string, mixed>  $monster
     * @param  array{hp:float, atk:float, def:float}  $mult
     * @return array<string, mixed>
     */
    private static function applyMultiplier(array $monster, array $mult): array
    {
        $atkMin = $monster['attack_min'] ?? (int) floor($monster['attack'] * 0.8);
        $atkMax = $monster['attack_max'] ?? (int) floor($monster['attack'] * 1.2);

        $monster['hp'] = (int) floor($monster['hp'] * $mult['hp']);
        $monster['attack'] = (int) floor($monster['attack'] * $mult['atk']);
        $monster['attack_min'] = (int) max(1, floor($atkMin * $mult['atk']));
        $monster['attack_max'] = (int) max(1, floor($atkMax * $mult['atk']));
        $monster['defense'] = (int) floor($monster['defense'] * $mult['def']);

        return $monster;
    }

    /**
     * Slot pierwszego żywego escorta (0-2), albo 3 (boss) gdy wszystkie escorty padły.
     *
     * @param  list<array{currentHp:int|float}|null>  $escorts
     */
    public static function resolveActiveOpponentSlot(array $escorts): int
    {
        for ($s = 0; $s < 3; $s++) {
            $e = $escorts[$s] ?? null;
            if ($e !== null && $e['currentHp'] > 0) {
                return $s;
            }
        }

        return 3;
    }

    /**
     * Najwyższy numer ukończonej transformacji (0 gdy brak).
     *
     * @param  list<int>  $completedTransformIds
     */
    public static function getHighestCompletedTransform(array $completedTransformIds): int
    {
        if (count($completedTransformIds) === 0) {
            return 0;
        }

        return (int) max($completedTransformIds);
    }

    // -- Treść (transforms/monsters.json) --------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllTransforms(): array
    {
        return $this->transforms;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTransformById(int $transformId): ?array
    {
        foreach ($this->transforms as $t) {
            if ($t['id'] === $transformId) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Najbliższy potwór na/poniżej poziomu; fallback do najniższego.
     *
     * @return array<string, mixed>
     */
    public function findClosestMonster(int|float $level): array
    {
        $best = $this->sortedMonsters[0];
        foreach ($this->sortedMonsters as $m) {
            if ($m['level'] <= $level) {
                $best = $m;
            } else {
                break;
            }
        }

        return $best;
    }

    /**
     * Generuje potwora boss questu transformacji dla danego poziomu.
     *
     * @return array<string, mixed>
     */
    public function generateTransformBossMonster(int $level): array
    {
        $template = $this->findClosestMonster($level);
        $stats = self::scaleMonsterStats($level);

        return [
            'id' => "transform_boss_{$level}",
            'name_pl' => $template['name_pl'],
            'name_en' => $template['name_en'],
            'level' => $level,
            'hp' => $stats['hp'],
            'attack' => $stats['attack'],
            'attack_min' => $stats['attack_min'],
            'attack_max' => $stats['attack_max'],
            'defense' => $stats['defense'],
            'speed' => $template['speed'],
            'xp' => $stats['xp'],
            'gold' => [(int) floor($level * 10), (int) floor($level * 20)],
            'dropTable' => [],
            'sprite' => $template['sprite'],
        ];
    }

    /**
     * Lista potworów do pokonania dla transformacji (jeden boss na każdy poziom zakresu).
     *
     * @return list<array<string, mixed>>
     */
    public function getTransformMonsters(int $transformId): array
    {
        $transform = $this->getTransformById($transformId);
        if ($transform === null) {
            return [];
        }

        if (isset($this->monsterCache[$transformId])) {
            return $this->monsterCache[$transformId];
        }

        [$minLvl, $maxLvl] = $transform['monsterLevelRange'];
        $monsters = [];
        for ($lvl = $minLvl; $lvl <= $maxLvl; $lvl++) {
            $monsters[] = $this->generateTransformBossMonster($lvl);
        }

        return $this->monsterCache[$transformId] = $monsters;
    }

    /**
     * Liczba potworów wymaganych dla transformacji. Deterministycznie = długość
     * zakresu (max-min+1); 0 dla nieistniejącego id — jak długość listy z TS.
     */
    public function getTransformMonsterCount(int $transformId): int
    {
        $transform = $this->getTransformById($transformId);
        if ($transform === null) {
            return 0;
        }

        [$minLvl, $maxLvl] = $transform['monsterLevelRange'];

        return $maxLvl - $minLvl + 1;
    }

    /**
     * Bonusy per transform: EMPTY gdy id nieistnieje lub brak klasy; inaczej klasowe.
     *
     * @return array<string, int|float>
     */
    public function getTransformBonuses(int $transformId, ?string $characterClass = null): array
    {
        if ($this->getTransformById($transformId) === null) {
            return self::EMPTY_BONUSES;
        }
        if ($characterClass === null) {
            return self::EMPTY_BONUSES;
        }

        return self::getClassTransformBonuses($characterClass, $transformId);
    }

    /**
     * Kumulatywne bonusy ze wszystkich ukończonych transformacji dla klasy.
     *
     * @param  list<int>  $completedTransformIds
     * @return array<string, int|float>
     */
    public function getCumulativeTransformBonuses(array $completedTransformIds, ?string $characterClass = null): array
    {
        $result = self::EMPTY_BONUSES;
        if ($characterClass === null) {
            return $result;
        }

        foreach ($completedTransformIds as $tid) {
            if ($this->getTransformById($tid) === null) {
                continue;
            }
            $per = self::getClassTransformBonuses($characterClass, $tid);
            foreach ($result as $key => $_) {
                $result[$key] += $per[$key];
            }
        }

        return $result;
    }

    /**
     * Czy poziom postaci wystarcza do transformacji.
     */
    public function isLevelSufficient(int $characterLevel, int $transformId): bool
    {
        $transform = $this->getTransformById($transformId);
        if ($transform === null) {
            return false;
        }

        return $characterLevel >= $transform['level'];
    }

    /**
     * Następna dostępna transformacja (kolejność wymuszona) albo null.
     *
     * @param  list<int>  $completedTransformIds
     * @return array<string, mixed>|null
     */
    public function getNextAvailableTransform(array $completedTransformIds, int $characterLevel): ?array
    {
        foreach ($this->transforms as $transform) {
            if (! in_array($transform['id'], $completedTransformIds, true)) {
                if ($characterLevel >= $transform['level']) {
                    return $transform;
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Nazwa pliku avatara na bazie klasy + najwyższej ukończonej transformacji.
     *
     * @param  list<int>  $completedTransformIds
     */
    public function getActiveAvatar(string $characterClass, array $completedTransformIds): ?string
    {
        $highest = self::getHighestCompletedTransform($completedTransformIds);
        if ($highest === 0) {
            return null;
        }

        $transform = $this->getTransformById($highest);
        if ($transform === null) {
            return null;
        }

        return strtolower($characterClass).$transform['avatarSuffix'].'.png';
    }

    /**
     * Deterministyczna część nagród transformacji: consumables + permanentBonuses.
     * Broń (generateWeapon = RNG, itemGenerator) świadomie pominięta — poza zakresem.
     *
     * @return array{consumables:list<array{id:string, count:int}>, permanentBonuses:array<string, int|float>}
     */
    public function calculateTransformRewardsDeterministic(int $transformId, string $characterClass): array
    {
        $transform = $this->getTransformById($transformId);
        if ($transform === null) {
            return ['consumables' => [], 'permanentBonuses' => self::EMPTY_BONUSES];
        }

        $r = $transform['rewards'];
        $consumables = [];

        if ($r['premiumXpElixirCount'] > 0) {
            $consumables[] = ['id' => 'premium_xp_elixir', 'count' => $r['premiumXpElixirCount']];
        }
        if ($r['hpPotionCount'] > 0) {
            $consumables[] = ['id' => $r['hpPotionId'], 'count' => $r['hpPotionCount']];
        }
        if ($r['mpPotionCount'] > 0) {
            $consumables[] = ['id' => $r['mpPotionId'], 'count' => $r['mpPotionCount']];
        }
        if ($r['spellChestCount'] > 0) {
            // Snap UP do najbliższego istniejącego poziomu spell chestu; brak → pomiń.
            $chestLevel = null;
            foreach (self::SPELL_CHEST_LEVELS as $l) {
                if ($l >= $r['spellChestLevel']) {
                    $chestLevel = $l;
                    break;
                }
            }
            if ($chestLevel !== null) {
                $consumables[] = ['id' => "spell_chest_{$chestLevel}", 'count' => $r['spellChestCount']];
            }
        }
        if ($r['mythicStoneCount'] > 0) {
            $consumables[] = ['id' => 'mythic_stone', 'count' => $r['mythicStoneCount']];
        }

        return [
            'consumables' => $consumables,
            'permanentBonuses' => self::getClassTransformBonuses($characterClass, $transformId),
        ];
    }

    /** Math.round z JS (half-up dla wartości nieujemnych, które tu występują). */
    private static function jsRound(float $x): int
    {
        return (int) floor($x + 0.5);
    }
}
