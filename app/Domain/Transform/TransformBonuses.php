<?php

declare(strict_types=1);

namespace App\Domain\Transform;

/**
 * Port src/systems/transformBonuses.ts. LIVE helpery bonusów transformacji.
 *
 * W TS te gettery czytają Zustand (useCharacterStore + useTransformStore). Tu są
 * CZYSTYMI funkcjami przyjmującymi stan jawnie (rule 4): completedTransforms +
 * klasa postaci (+ bakedBonusesApplied dla breakdownu display). Brak postaci w TS
 * (char === null) → tu reprezentowany przez $characterClass === null.
 *
 * PARYTET: golden-vectory generowane w TS ustawiają store (setState) przed
 * wywołaniem getterów; tu odtwarzamy identyczne wyniki (TransformSystemTest).
 */
final class TransformBonuses
{
    /** @var array<string, int|float> wszystkie 14 pól zerowe (ZERO_BONUS z TS) */
    private const ZERO_BONUS = [
        'hpPercent' => 0, 'mpPercent' => 0, 'defPercent' => 0, 'dmgPercent' => 0,
        'atkPercent' => 0, 'flatHp' => 0, 'flatMp' => 0, 'attack' => 0, 'defense' => 0,
        'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0, 'mpRegenFlat' => 0, 'classSkillBonus' => 0,
    ];

    /** Pola sumowane przez sumCompletedBonuses (11 — bez hpRegen/mpRegen/classSkillBonus). */
    private const SUMMED_FIELDS = [
        'hpPercent', 'mpPercent', 'defPercent', 'dmgPercent', 'atkPercent',
        'flatHp', 'flatMp', 'attack', 'defense', 'hpRegenFlat', 'mpRegenFlat',
    ];

    public function __construct(private readonly TransformSystem $system) {}

    /**
     * Suma bonusów per-tier ze wszystkich ukończonych transformacji (dla klasy).
     * Zero gdy brak postaci (klasa null) lub brak ukończonych transformacji.
     *
     * @param  list<int>  $completed
     * @return array<string, int|float>
     */
    public function sumCompletedBonuses(array $completed, ?string $characterClass): array
    {
        if ($characterClass === null) {
            return self::ZERO_BONUS;
        }
        if (count($completed) === 0) {
            return self::ZERO_BONUS;
        }

        $sum = self::ZERO_BONUS;
        foreach ($completed as $tid) {
            if ($this->system->getTransformById($tid) === null) {
                continue;
            }
            $per = TransformSystem::getClassTransformBonuses($characterClass, $tid);
            foreach (self::SUMMED_FIELDS as $field) {
                $sum[$field] += $per[$field];
            }
        }

        return $sum;
    }

    /**
     * Mnożnik obrażeń wychodzących: 1 + Σ dmgPercent / 100. Domyślnie 1.0.
     *
     * @param  list<int>  $completed
     */
    public function getTransformDmgMultiplier(array $completed, ?string $characterClass): float
    {
        if ($characterClass === null || count($completed) === 0) {
            return 1.0;
        }

        $totalPct = 0;
        foreach ($completed as $tid) {
            if ($this->system->getTransformById($tid) !== null) {
                $totalPct += TransformSystem::getClassTransformBonuses($characterClass, $tid)['dmgPercent'];
            }
        }
        if ($totalPct <= 0) {
            return 1.0;
        }

        return 1 + $totalPct / 100;
    }

    /** @param list<int> $completed */
    public function getTransformFlatHp(array $completed, ?string $characterClass): int
    {
        return (int) $this->sumCompletedBonuses($completed, $characterClass)['flatHp'];
    }

    /** @param list<int> $completed */
    public function getTransformFlatMp(array $completed, ?string $characterClass): int
    {
        return (int) $this->sumCompletedBonuses($completed, $characterClass)['flatMp'];
    }

    /** @param list<int> $completed */
    public function getTransformFlatAttack(array $completed, ?string $characterClass): int
    {
        return (int) $this->sumCompletedBonuses($completed, $characterClass)['attack'];
    }

    /** @param list<int> $completed */
    public function getTransformFlatDefense(array $completed, ?string $characterClass): int
    {
        return (int) $this->sumCompletedBonuses($completed, $characterClass)['defense'];
    }

    /** @param list<int> $completed */
    public function getTransformHpRegenFlat(array $completed, ?string $characterClass): float
    {
        return (float) $this->sumCompletedBonuses($completed, $characterClass)['hpRegenFlat'];
    }

    /** @param list<int> $completed */
    public function getTransformMpRegenFlat(array $completed, ?string $characterClass): float
    {
        return (float) $this->sumCompletedBonuses($completed, $characterClass)['mpRegenFlat'];
    }

    /** @param list<int> $completed */
    public function getTransformHpPctMultiplier(array $completed, ?string $characterClass): float
    {
        return self::pctToMultiplier($this->sumCompletedBonuses($completed, $characterClass)['hpPercent']);
    }

    /** @param list<int> $completed */
    public function getTransformMpPctMultiplier(array $completed, ?string $characterClass): float
    {
        return self::pctToMultiplier($this->sumCompletedBonuses($completed, $characterClass)['mpPercent']);
    }

    /** @param list<int> $completed */
    public function getTransformDefPctMultiplier(array $completed, ?string $characterClass): float
    {
        return self::pctToMultiplier($this->sumCompletedBonuses($completed, $characterClass)['defPercent']);
    }

    /** @param list<int> $completed */
    public function getTransformAtkPctMultiplier(array $completed, ?string $characterClass): float
    {
        return self::pctToMultiplier($this->sumCompletedBonuses($completed, $characterClass)['atkPercent']);
    }

    /**
     * LIVE breakdown dla panelu statystyk (wartości do DODANIA na bazę+eq).
     * Zawsze active (baza jest czystym floorem — nigdy baked).
     *
     * @param  list<int>  $completed
     * @return array<string, int|float|bool>
     */
    public function getLiveTransformBreakdown(array $completed, ?string $characterClass): array
    {
        if ($characterClass === null || count($completed) === 0) {
            return self::zeroBreakdown(false);
        }

        return self::mapBreakdown($this->sumCompletedBonuses($completed, $characterClass), true, false);
    }

    /**
     * DISPLAY-ONLY breakdown (atrybucja mocy transformacji), niezależnie od baked.
     * Zero gdy brak postaci / brak transformacji (flaga baked przekazywana dalej).
     *
     * @param  list<int>  $completed
     * @return array<string, int|float|bool>
     */
    public function getDisplayTransformBreakdown(array $completed, ?string $characterClass, bool $bakedBonusesApplied): array
    {
        if ($characterClass === null || count($completed) === 0) {
            return self::zeroBreakdown($bakedBonusesApplied);
        }

        $b = $this->system->getCumulativeTransformBonuses($completed, $characterClass);

        return self::mapBreakdown($b, true, $bakedBonusesApplied);
    }

    private static function pctToMultiplier(int|float $pct): float
    {
        if ($pct <= 0) {
            return 1.0;
        }

        return 1 + $pct / 100;
    }

    /**
     * @param  array<string, int|float>  $b
     * @return array<string, int|float|bool>
     */
    private static function mapBreakdown(array $b, bool $active, bool $baked): array
    {
        return [
            'dmgPercent' => $b['dmgPercent'],
            'hpPercent' => $b['hpPercent'],
            'mpPercent' => $b['mpPercent'],
            'defPercent' => $b['defPercent'],
            'atkPercent' => $b['atkPercent'],
            'flatHp' => $b['flatHp'],
            'flatMp' => $b['flatMp'],
            'flatAttack' => $b['attack'],
            'flatDefense' => $b['defense'],
            'hpRegenFlat' => $b['hpRegenFlat'],
            'mpRegenFlat' => $b['mpRegenFlat'],
            'active' => $active,
            'baked' => $baked,
        ];
    }

    /**
     * @return array<string, int|float|bool>
     */
    private static function zeroBreakdown(bool $baked): array
    {
        return [
            'dmgPercent' => 0, 'hpPercent' => 0, 'mpPercent' => 0, 'defPercent' => 0, 'atkPercent' => 0,
            'flatHp' => 0, 'flatMp' => 0, 'flatAttack' => 0, 'flatDefense' => 0,
            'hpRegenFlat' => 0, 'mpRegenFlat' => 0, 'active' => false, 'baked' => $baked,
        ];
    }
}
