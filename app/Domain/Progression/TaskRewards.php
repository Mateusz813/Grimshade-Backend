<?php

declare(strict_types=1);

namespace App\Domain\Progression;

final class TaskRewards
{
    public const TASK_XP_CURVE_THRESHOLD = 300;

    public const TASK_XP_GEOMETRIC_RATIO = 1.05;

    private array $overrideByLevel;

    public function __construct(array $monsters)
    {
        $this->overrideByLevel = self::buildOverride($monsters);
    }

    private static function buildOverride(array $monsters): array
    {
        $filtered = array_values(array_filter(
            $monsters,
            static fn (array $m): bool => $m['level'] >= self::TASK_XP_CURVE_THRESHOLD,
        ));
        usort($filtered, static fn (array $a, array $b): int => $a['level'] <=> $b['level']);

        $map = [];
        if (count($filtered) === 0) {
            return $map;
        }

        $anchorXp = (int) max(0, floor($filtered[0]['xp']));
        foreach ($filtered as $idx => $m) {
            $eff = (int) floor($anchorXp * (self::TASK_XP_GEOMETRIC_RATIO ** $idx));
            $map[$m['level']] = (int) max(1, $eff);
        }

        return $map;
    }

    public function getEffectiveTaskXpPerKill(array $monster): int|float
    {
        if ($monster['level'] >= self::TASK_XP_CURVE_THRESHOLD
            && isset($this->overrideByLevel[$monster['level']])) {
            return $this->overrideByLevel[$monster['level']];
        }

        $xp = $monster['xp'] ?? 0;

        return is_finite((float) $xp) ? $xp : 0;
    }

    public function computeTaskRewards(array $monster, int $killCount): array
    {
        $xpPerKill = $this->getEffectiveTaskXpPerKill($monster);
        $gold = $monster['gold'] ?? null;
        $maxGold = (is_array($gold) && count($gold) >= 2) ? $gold[1] : 0;

        return [
            'rewardXp' => (int) max(0, floor($xpPerKill * $killCount * 1.5)),
            'rewardGold' => (int) max(0, floor($maxGold * $killCount * 3)),
        ];
    }
}
