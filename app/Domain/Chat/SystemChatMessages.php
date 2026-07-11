<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use JsonException;

/**
 * Port 1:1 src/systems/systemChatMessages.ts (frontend). PROTOKÓŁ czatu
 * systemowego (NIE UI): format + parse wiadomości broadcastowanych po drucie
 * jako `[SYS]{...json...}`. Czyste, deterministyczne funkcje — bez RNG, bez
 * czasu, bez store.
 *
 * Format na drucie:
 *   [SYS]{"type":"upgrade","itemId":"luk","rarity":"common","upgradeLevel":5,"itemName":"Krótki Łuk"}
 *   [SYS]{"type":"skillUpgrade","skillId":"power_strike","skillName":"Potężny Cios","upgradeLevel":10}
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/systemChatMessages.json
 * (generowane z TS) są tu odtwarzane bajt-w-bajt (SystemChatMessagesTest).
 *
 * PARYTET JSON: JS JSON.stringify NIE escape'uje unicode ani slashy i zachowuje
 * kolejność wstawiania kluczy. Dlatego json_encode używa
 * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES — inaczej polskie znaki
 * poszłyby jako ó a "/" jako "\/" i stringi by się rozjechały.
 */
final class SystemChatMessages
{
    private const SYS_MARKER = '[SYS]';

    /**
     * Czy dany poziom ulepszenia zasługuje na broadcast do zakładki System.
     *
     * 2026-05-20 spec: +5 i +7 to wczesne progi hype, +10 to pierwszy „prawdziwy"
     * milestone, a każdy poziom od +10 w górę też jest milestone (+11, +12, …).
     * Ta sama reguła dla itemów i aktywnych skilli.
     */
    public static function isUpgradeMilestone(int $level): bool
    {
        return $level === 5 || $level === 7 || $level >= 10;
    }

    /**
     * Enkoduje payload systemowy do stringa treści wiadomości czatu.
     *
     * Kolejność kluczy w tablicy wejściowej JEST znacząca (PHP zachowuje
     * kolejność wstawiania) — musi odpowiadać kolejności pól interfejsu TS,
     * bo golden bit-parity porównuje surowy string.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public static function formatSystemMessage(array $payload): string
    {
        return self::SYS_MARKER.json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Próbuje sparsować treść wiadomości czatu jako payload systemowy. Zwraca
     * null dla wszystkiego, co nie zaczyna się markerem, ma puste body albo
     * którego JSON jest niepoprawny / nie pasuje do żadnego wariantu — wtedy
     * caller renderuje jako zwykły tekst (wsteczna kompatybilność).
     *
     * @return array<string, mixed>|null
     */
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
            /** @var mixed $parsed */
            $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        // Odpowiednik `parsed.type` na prymitywie/null/array w JS: dla obiektu
        // czytamy pole, dla prymitywu i tablicy bez klucza 'type' → null.
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

    /**
     * Odpowiednik `typeof x === 'number'` z JS na wartości sparsowanej z JSON.
     * JSON liczba → int lub float w PHP; bool NIE jest liczbą (is_int/is_float
     * odrzucają true/false, tak jak JS `typeof true === 'boolean'`).
     */
    private static function isJsonNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
