<?php

declare(strict_types=1);

namespace App\Domain\Combat;

final class DeathProtection
{
    public const DEATH_PROTECTION_ID = 'death_protection';

    public const AMULET_OF_LOSS_ID = 'amulet_of_loss';

    public static function hasDeathProtection(array $consumables): bool
    {
        return ($consumables[self::DEATH_PROTECTION_ID] ?? 0) > 0
            || ($consumables[self::AMULET_OF_LOSS_ID] ?? 0) > 0;
    }

    public static function consumeDeathProtection(array $consumables): array
    {
        if (self::tryUseConsumable($consumables, self::DEATH_PROTECTION_ID)) {
            return [
                'isProtected' => true,
                'consumedId' => self::DEATH_PROTECTION_ID,
                'consumables' => $consumables,
            ];
        }

        if (self::tryUseConsumable($consumables, self::AMULET_OF_LOSS_ID)) {
            return [
                'isProtected' => true,
                'consumedId' => self::AMULET_OF_LOSS_ID,
                'consumables' => $consumables,
            ];
        }

        return [
            'isProtected' => false,
            'consumedId' => null,
            'consumables' => $consumables,
        ];
    }

    private static function tryUseConsumable(array &$consumables, string $id): bool
    {
        $count = $consumables[$id] ?? 0;
        if ($count <= 0) {
            return false;
        }

        $consumables[$id] = max(0, ($consumables[$id] ?? 0) - 1);

        return true;
    }
}
