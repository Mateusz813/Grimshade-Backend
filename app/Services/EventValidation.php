<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Carbon;

final class EventValidation
{
    private const EQUIPMENT_SLOTS = [
        'helmet', 'armor', 'pants', 'gloves', 'shoulders', 'boots',
        'mainHand', 'offHand', 'ring1', 'ring2', 'earrings', 'necklace',
    ];

    private const DAILY_ATTEMPT_CAP = 5;

    public const MAX_LEVEL_JUMP = 50;

    private const ATTEMPT_PATHS = [
        'dungeon' => ['dungeons', 'dailyAttempts', 'used'],
        'boss' => ['bosses', 'dailyAttempts', 'used'],
        'raid' => ['raid', 'attempts', 'count'],
    ];

    private const OPTIONAL_ITEM_PATHS = [
        ['guildTreasury'],
        ['market', 'escrow'],
    ];

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

        if (! $died && $nextLevel < $prevLevel) {
            $soft[] = "poziom spadł bez śmierci ({$prevLevel} -> {$nextLevel})";
        }

        return ['soft' => $soft, 'newItems' => $newItems];
    }

    public function duplicateUuids(array $state): array
    {
        $counts = array_count_values($this->collectItemUuids($state));

        return array_values(array_keys(array_filter($counts, static fn (int $n): bool => $n > 1)));
    }

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
