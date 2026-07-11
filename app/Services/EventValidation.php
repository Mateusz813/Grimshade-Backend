<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Carbon;

/**
 * Bramka zdarzeń combatu dla autorytatywnego commitu stanu. Klient liczy walkę
 * lokalnie i pushuje PEŁNY nowy blob (`state`) RAZEM z semantycznym `event`
 * opisującym co się stało. Serwer DIFFUJE zablokowany poprzedni blob (prev)
 * względem przysłanego, zsanityzowanego (next) i sprawdza, czy przejście jest
 * legalne — "nic nie idzie do bazy na ślepo".
 *
 * PODZIAŁ: TWARDE niezmienniki, które nie potrzebują kontekstu zdarzenia (dupe
 * uuid, skok poziomu, absurdalne sufity) egzekwuje CharacterStateService::
 * guardInvariants na KAŻDYM commicie (z eventem czy bez) — patrz duplicateUuids()
 * i stała MAX_LEVEL_JUMP, wystawione publicznie dla tej ścieżki. Ta klasa liczy
 * tylko naruszenia wymagające `event` (dzienne limity, spójność śmierci, spadek
 * poziomu bez śmierci) — wszystkie SOFT: domyślnie logowane, 422 wyłącznie gdy
 * config('supabase.event_validation_strict') === true.
 *
 * @phpstan-type EventPayload array{
 *     type?: string, sourceId?: string|null, outcome?: string|null,
 *     died?: bool, protectionConsumed?: string|null, wavesCompleted?: int|null
 * }
 */
final class EventValidation
{
    /** Sloty ekwipunku (parytet CharacterController::EQUIPMENT_SLOTS) — 12 slotów. */
    private const EQUIPMENT_SLOTS = [
        'helmet', 'armor', 'pants', 'gloves', 'shoulders', 'boots',
        'mainHand', 'offHand', 'ring1', 'ring2', 'earrings', 'necklace',
    ];

    /** Hojny dzienny limit ukończeń (dungeonStore.MAX_DAILY_ATTEMPTS = 5). */
    private const DAILY_ATTEMPT_CAP = 5;

    /**
     * Maks. wiarygodny przyrost poziomu w JEDNYM commicie (hojny bufor). Publiczna,
     * bo egzekwowana jako ALWAYS-RUN HARD w CharacterStateService::guardInvariants.
     */
    public const MAX_LEVEL_JUMP = 50;

    /** Zdarzenia z dziennym licznikiem prób i ścieżka wpisu w blobie. */
    private const ATTEMPT_PATHS = [
        'dungeon' => ['dungeons', 'dailyAttempts', 'used'],
        'boss' => ['bosses', 'dailyAttempts', 'used'],
        'raid' => ['raid', 'attempts', 'count'],
    ];

    /**
     * Opcjonalne wycinki bloba, które MOGĄ trzymać itemy z uuid (skarbiec gildii /
     * escrow rynku). W tym repo żyją w osobnych tabelach, ale gdyby front kiedyś
     * mirrorował je do bloba — łapiemy je w skanie dupe. Bezpieczne, gdy nieobecne.
     *
     * @var list<list<string>>
     */
    private const OPTIONAL_ITEM_PATHS = [
        ['guildTreasury'],
        ['market', 'escrow'],
    ];

    /**
     * DIFF prev↔next dla naruszeń WYMAGAJĄCYCH kontekstu zdarzenia (event obecny):
     * dzienne limity prób, spójność śmierci, spadek poziomu bez śmierci. Wszystkie
     * SOFT. Twarde niezmienniki (dupe uuid, skok poziomu wzwyż, absurdalne sufity)
     * egzekwuje CharacterStateService::guardInvariants na KAŻDYM commicie — NIE tutaj.
     *
     * @param  array<string, mixed>  $prev  zablokowany poprzedni blob (przed nadpisaniem)
     * @param  array<string, mixed>  $next  zsanityzowany przysłany blob (do zapisu)
     * @param  EventPayload  $event
     * @return array{soft: list<string>, newItems: int}
     */
    public function evaluate(array $prev, array $next, array $event, Character $character): array
    {
        $soft = [];

        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $sourceId = is_string($event['sourceId'] ?? null) ? $event['sourceId'] : '';
        $outcome = is_string($event['outcome'] ?? null) ? $event['outcome'] : '';
        $died = ($event['died'] ?? null) === true;
        $protection = is_string($event['protectionConsumed'] ?? null) ? $event['protectionConsumed'] : null;
        $today = Carbon::now()->toDateString();

        $prevUuids = array_flip($this->collectItemUuids($prev));
        $newItems = 0;
        foreach ($this->collectItemUuids($next) as $uuid) {
            if (! isset($prevUuids[$uuid])) {
                $newItems++;
            }
        }

        // 1) Nowe itemy — bounds loota nie są jeszcze zweryfikowane vs realne dane,
        //    więc NIE flagujemy; liczbę raportujemy do logu (przez `newItems`).

        // 2) Dzienne próby (dungeon/boss/raid) — poprawny inkrement + limit.
        if (isset(self::ATTEMPT_PATHS[$type]) && $sourceId !== '') {
            $prevUsed = $this->attemptCount($prev, $type, $sourceId, $today);
            $nextUsed = $this->attemptCount($next, $type, $sourceId, $today);

            if ($nextUsed > self::DAILY_ATTEMPT_CAP) {
                $soft[] = "dzienne próby {$type}:{$sourceId} przekraczają limit ({$nextUsed} > ".self::DAILY_ATTEMPT_CAP.')';
            } elseif ($nextUsed < $prevUsed) {
                $soft[] = "dzienne próby {$type}:{$sourceId} spadły ({$prevUsed} -> {$nextUsed})";
            } elseif (in_array($outcome, ['won', 'settled'], true) && $nextUsed !== $prevUsed + 1) {
                $soft[] = "dzienne próby {$type}:{$sourceId} nie wzrosły o 1 przy '{$outcome}' ({$prevUsed} -> {$nextUsed})";
            }
        }

        // 3) Spójność śmierci (event-context — wymaga event.died/protectionConsumed).
        $prevLevel = (int) ($prev['_characterStats']['level'] ?? $character->level);
        $nextLevel = (int) ($next['_characterStats']['level'] ?? $prevLevel);
        if ($died) {
            if ($protection !== null && $protection !== '') {
                $prevCount = (int) ($prev['inventory']['consumables'][$protection] ?? 0);
                $nextCount = (int) ($next['inventory']['consumables'][$protection] ?? 0);
                if ($nextCount !== $prevCount - 1) {
                    $soft[] = "ochrona {$protection} nie zużyta dokładnie raz (prev={$prevCount}, next={$nextCount})";
                }
            } elseif ($nextLevel > $prevLevel) {
                $soft[] = "śmierć bez ochrony, a poziom wzrósł ({$prevLevel} -> {$nextLevel})";
            }
        }

        // 4) Spadek poziomu bez śmierci (event-context SOFT). Skok WZWYŻ >MAX_LEVEL_JUMP
        //    to teraz ALWAYS-RUN HARD w guardInvariants — nie sprawdzamy go tutaj.
        if (! $died && $nextLevel < $prevLevel) {
            $soft[] = "poziom spadł bez śmierci ({$prevLevel} -> {$nextLevel})";
        }

        return ['soft' => $soft, 'newItems' => $newItems];
    }

    /**
     * Uuidy występujące >1 raz w item-kontenerach `next` (bag + deposit + 12
     * slotów equipment + opcjonalny skarbiec/escrow). Itemy bez uuid pomijane
     * (stackowalne consumables/kamienie identyfikowane po id+count, nie uuid).
     *
     * Publiczna: CharacterStateService::guardInvariants woła ją na KAŻDYM commicie
     * (ALWAYS-RUN HARD), niezależnie od obecności `event`.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public function duplicateUuids(array $state): array
    {
        $counts = array_count_values($this->collectItemUuids($state));

        return array_values(array_keys(array_filter($counts, static fn (int $n): bool => $n > 1)));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function collectItemUuids(array $state): array
    {
        $inv = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];

        $containers = [];
        $containers[] = is_array($inv['bag'] ?? null) ? $inv['bag'] : [];
        $containers[] = is_array($inv['deposit'] ?? null) ? $inv['deposit'] : [];

        $equipment = is_array($inv['equipment'] ?? null) ? $inv['equipment'] : [];
        $slots = [];
        foreach (self::EQUIPMENT_SLOTS as $slot) {
            if (isset($equipment[$slot])) {
                $slots[] = $equipment[$slot];
            }
        }
        // Dodatkowe klucze equipment poza kanonicznymi 12 też liczymy (obronnie).
        foreach ($equipment as $slot => $item) {
            if (! in_array($slot, self::EQUIPMENT_SLOTS, true)) {
                $slots[] = $item;
            }
        }
        $containers[] = $slots;

        foreach (self::OPTIONAL_ITEM_PATHS as $path) {
            $slice = $this->nested($state, $path);
            $containers[] = is_array($slice) ? array_values($slice) : [];
        }

        $uuids = [];
        foreach ($containers as $items) {
            foreach ($items as $item) {
                if (is_array($item) && isset($item['uuid']) && is_string($item['uuid']) && $item['uuid'] !== '') {
                    $uuids[] = $item['uuid'];
                }
            }
        }

        return $uuids;
    }

    /**
     * Liczba dziennych prób danego zdarzenia dla sourceId (0, gdy wpis z innego
     * dnia — świeży dzień = 0 zużytych). Raid używa klucza `count`, dungeon/boss `used`.
     *
     * @param  array<string, mixed>  $state
     */
    private function attemptCount(array $state, string $type, string $sourceId, string $today): int
    {
        [$root, $bucket, $key] = self::ATTEMPT_PATHS[$type];
        $entry = $state[$root][$bucket][$sourceId] ?? null;
        if (! is_array($entry)) {
            return 0;
        }
        if (($entry['date'] ?? null) !== $today) {
            return 0;
        }

        return (int) ($entry[$key] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $path
     */
    private function nested(array $state, array $path): mixed
    {
        $cursor = $state;
        foreach ($path as $key) {
            if (! is_array($cursor) || ! array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }
}
