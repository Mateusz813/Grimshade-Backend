<?php

declare(strict_types=1);

namespace App\Domain\Progression;

final class DailyQuestSystem
{
    public const DAILY_QUEST_COUNT = 12;

    private const UINT32 = 4294967296;

    private const INT32_SIGN = 2147483648;

    public static function todayKey(int $year, int $month, int $day): string
    {
        return sprintf('%d-%02d-%02d', $year, $month, $day);
    }

    public static function needsRefresh(?string $lastRefreshDate, string $todayKey): bool
    {
        if ($lastRefreshDate === null || $lastRefreshDate === '') {
            return true;
        }

        return $lastRefreshDate !== $todayKey;
    }

    public static function selectDailyQuests(array $allQuests, int $playerLevel, string $todayKey): array
    {
        $eligible = array_values(array_filter(
            $allQuests,
            static fn (array $q): bool => $playerLevel >= $q['minLevel'],
        ));
        if (count($eligible) <= self::DAILY_QUEST_COUNT) {
            return $eligible;
        }

        $seed = 0;
        $len = strlen($todayKey);
        for ($i = 0; $i < $len; $i++) {
            $seed = self::toInt32(31 * $seed + \ord($todayKey[$i]));
        }

        $shuffled = $eligible;
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $seed = self::advanceSeed($seed);
            $j = $seed % ($i + 1);
            [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
        }

        return array_slice($shuffled, 0, self::DAILY_QUEST_COUNT);
    }

    public static function scaleRewards(array $base, int $playerLevel): array
    {
        $goldMultiplier = 1 + $playerLevel * 0.25;
        $xpMultiplier = 1 + $playerLevel * 0.3;

        $result = [
            'gold' => (int) floor($base['gold'] * $goldMultiplier * 0.6),
            'xp' => (int) floor($base['xp'] * $xpMultiplier),
        ];
        if (isset($base['elixir'])) {
            $result['elixir'] = $base['elixir'];
        }

        return $result;
    }

    private static function advanceSeed(int $seed): int
    {
        $product = (float) $seed * 1103515245.0 + 12345.0;

        return self::toUint32($product) & 0x7FFFFFFF;
    }

    private static function toUint32(float $x): int
    {
        $m = fmod($x, (float) self::UINT32);
        if ($m < 0.0) {
            $m += self::UINT32;
        }

        return (int) $m;
    }

    private static function toInt32(int|float $x): int
    {
        $u = self::toUint32((float) $x);

        return $u >= self::INT32_SIGN ? $u - self::UINT32 : $u;
    }
}
