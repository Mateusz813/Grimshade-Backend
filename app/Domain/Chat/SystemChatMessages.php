<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use JsonException;

final class SystemChatMessages
{
    private const SYS_MARKER = '[SYS]';

    public static function isUpgradeMilestone(int $level): bool
    {
        return $level === 5 || $level === 7 || $level >= 10;
    }

    public static function formatSystemMessage(array $payload): string
    {
        return self::SYS_MARKER.json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    public static function parseSystemMessage(string $content): ?array
    {
        if (! str_starts_with($content, self::SYS_MARKER)) {
            return null;
        }

        $json = trim(substr($content, strlen(self::SYS_MARKER)));
        if ($json === '') {
            return null;
        }

        try {
            $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($parsed)) {
            return null;
        }

        $type = $parsed['type'] ?? null;

        if ($type === 'upgrade'
            && is_string($parsed['itemId'] ?? null)
            && is_string($parsed['rarity'] ?? null)
            && self::isJsonNumber($parsed['upgradeLevel'] ?? null)
            && is_string($parsed['itemName'] ?? null)) {
            return [
                'type' => 'upgrade',
                'itemId' => $parsed['itemId'],
                'rarity' => $parsed['rarity'],
                'upgradeLevel' => $parsed['upgradeLevel'],
                'itemName' => $parsed['itemName'],
            ];
        }

        if ($type === 'skillUpgrade'
            && is_string($parsed['skillId'] ?? null)
            && is_string($parsed['skillName'] ?? null)
            && self::isJsonNumber($parsed['upgradeLevel'] ?? null)) {
            return [
                'type' => 'skillUpgrade',
                'skillId' => $parsed['skillId'],
                'skillName' => $parsed['skillName'],
                'upgradeLevel' => $parsed['upgradeLevel'],
            ];
        }

        return null;
    }

    private static function isJsonNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
