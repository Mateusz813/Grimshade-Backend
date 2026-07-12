<?php

declare(strict_types=1);

namespace App\Domain\Transform;

final class TransformBonuses
{
    private const ZERO_BONUS = [
        'hpPercent' => 0, 'mpPercent' => 0, 'defPercent' => 0, 'dmgPercent' => 0,
        'atkPercent' => 0, 'flatHp' => 0, 'flatMp' => 0, 'attack' => 0, 'defense' => 0,
        'hpRegen' => 0, 'mpRegen' => 0, 'hpRegenFlat' => 0, 'mpRegenFlat' => 0, 'classSkillBonus' => 0,
    ];

    private const SUMMED_FIELDS = [
        'hpPercent', 'mpPercent', 'defPercent', 'dmgPercent', 'atkPercent',
        'flatHp', 'flatMp', 'attack', 'defense', 'hpRegenFlat', 'mpRegenFlat',
    ];

    public function __construct(private readonly TransformSystem $system) {}

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

    public function getTransformFlatHp(array $completed, ?string $characterClass): int
    {
        return (int) $this->sumCompletedBonuses($completed, $characterClass)['flatHp'];
    }

    public function getTransformFlatMp(array $completed, ?string $characterClass): int
    {
        return (int) $this->sumCompletedBonuses($completed, $characterClass)['flatMp'];
    }

    public function getTransformFlatAttack(array $completed, ?string $characterClass): int
    {
        return (int) $this->sumCompletedBonuses($completed, $characterClass)['attack'];
    }

    public function getTransformFlatDefense(array $completed, ?string $characterClass): int
    {
        return (int) $this->sumCompletedBonuses($completed, $characterClass)['defense'];
    }

    public function getTransformHpRegenFlat(array $completed, ?string $characterClass): float
    {
        return (float) $this->sumCompletedBonuses($completed, $characterClass)['hpRegenFlat'];
    }

    public function getTransformMpRegenFlat(array $completed, ?string $characterClass): float
    {
        return (float) $this->sumCompletedBonuses($completed, $characterClass)['mpRegenFlat'];
    }

    public function getTransformHpPctMultiplier(array $completed, ?string $characterClass): float
    {
        return self::pctToMultiplier($this->sumCompletedBonuses($completed, $characterClass)['hpPercent']);
    }

    public function getTransformMpPctMultiplier(array $completed, ?string $characterClass): float
    {
        return self::pctToMultiplier($this->sumCompletedBonuses($completed, $characterClass)['mpPercent']);
    }

    public function getTransformDefPctMultiplier(array $completed, ?string $characterClass): float
    {
        return self::pctToMultiplier($this->sumCompletedBonuses($completed, $characterClass)['defPercent']);
    }

    public function getTransformAtkPctMultiplier(array $completed, ?string $characterClass): float
    {
        return self::pctToMultiplier($this->sumCompletedBonuses($completed, $characterClass)['atkPercent']);
    }

    public function getLiveTransformBreakdown(array $completed, ?string $characterClass): array
    {
        if ($characterClass === null || count($completed) === 0) {
            return self::zeroBreakdown(false);
        }

        return self::mapBreakdown($this->sumCompletedBonuses($completed, $characterClass), true, false);
    }

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

    private static function zeroBreakdown(bool $baked): array
    {
        return [
            'dmgPercent' => 0, 'hpPercent' => 0, 'mpPercent' => 0, 'defPercent' => 0, 'atkPercent' => 0,
            'flatHp' => 0, 'flatMp' => 0, 'flatAttack' => 0, 'flatDefense' => 0,
            'hpRegenFlat' => 0, 'mpRegenFlat' => 0, 'active' => false, 'baked' => $baked,
        ];
    }
}
