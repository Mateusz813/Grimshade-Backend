<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Character\EffectiveStats;
use App\Domain\Progression\LevelSystem;
use App\Models\Character;
use App\Models\GameSave;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class CharacterStateService
{
    public function lockedFor(Character $character): GameSave
    {
        $save = GameSave::query()
            ->where('character_id', $character->id)
            ->lockForUpdate()
            ->first();

        if ($save !== null) {
            return $save;
        }

        return new GameSave([
            'user_id' => $character->user_id,
            'character_id' => $character->id,
            'state' => ['_ownerCharacterId' => $character->id],
        ]);
    }

    public function persist(GameSave $save): void
    {
        $save->updated_at = now();
        $save->save();
    }


    public function commit(
        Character $character,
        GameSave $save,
        array $submittedState,
        EffectiveStats $effective,
        bool $strict,
        ?array $event = null,
        bool $eventStrict = false,
    ): array {
        $prev = is_array($save->state ?? null) ? $save->state : [];

        $sanitized = $this->sanitizeState($submittedState);

        $violations = $this->validateState($character, $sanitized, $effective);
        if ($violations !== []) {
            if ($strict) {
                throw new StateValidationException(
                    'Odrzucono zapis stanu: '.implode('; ', $violations),
                );
            }
            Log::warning('state.commit: naruszenia walidacji (SOFT — zapisuję mimo to)', [
                'character_id' => $character->id,
                'violations' => $violations,
            ]);
        }

        $this->guardInvariants($character, $prev, $sanitized, $effective, $strict);

        if ($event !== null) {
            $this->guardEvent($character, $prev, $sanitized, $event, $eventStrict);
        }

        $this->applyStatsToRow($character, $sanitized);

        $save->state = $sanitized;
        $this->persist($save);
        $character->save();

        return $sanitized;
    }

    private function guardInvariants(Character $character, array $prev, array $next, EffectiveStats $effective, bool $strict): void
    {
        $hard = [];

        foreach ((new EventValidation)->duplicateUuids($next) as $uuid) {
            $hard[] = "duplikat uuid itemu: {$uuid}";
        }

        $prevLevelRaw = $prev['_characterStats']['level'] ?? null;
        if ($prevLevelRaw !== null) {
            $prevLevel = (int) $this->finiteNumber($prevLevelRaw);
            $nextLevel = (int) $this->finiteNumber($next['_characterStats']['level'] ?? $prevLevel);
            if ($nextLevel - $prevLevel > EventValidation::MAX_LEVEL_JUMP) {
                $hard[] = "niewiarygodny skok poziomu w jednym commicie ({$prevLevel} -> {$nextLevel}, max +".EventValidation::MAX_LEVEL_JUMP.')';
            }
        }

        $inv = is_array($next['inventory'] ?? null) ? $next['inventory'] : [];
        $gold = $this->finiteNumber($inv['gold'] ?? 0);
        if ($gold > self::ABSURD_GOLD_CAP) {
            $hard[] = "gold {$gold} > absurdalny sufit (".self::ABSURD_GOLD_CAP.')';
        }
        foreach ((array) ($inv['consumables'] ?? []) as $id => $count) {
            if (is_numeric($count) && (float) $count > self::ABSURD_STACK_CAP) {
                $hard[] = "consumable {$id}={$count} > absurdalny sufit (".self::ABSURD_STACK_CAP.')';
            }
        }
        foreach ((array) ($inv['stones'] ?? []) as $type => $count) {
            if (is_numeric($count) && (float) $count > self::ABSURD_STACK_CAP) {
                $hard[] = "kamień {$type}={$count} > absurdalny sufit (".self::ABSURD_STACK_CAP.')';
            }
        }
        $arena = $this->finiteNumber($inv['arenaPoints'] ?? 0);
        if ($arena > self::ABSURD_ARENA_CAP) {
            $hard[] = "arenaPoints {$arena} > absurdalny sufit (".self::ABSURD_ARENA_CAP.')';
        }
        foreach ($this->skillLevelsFrom($next) as $skill => $lvl) {
            if (is_numeric($lvl) && (float) $lvl > self::ABSURD_SKILL_CAP) {
                $hard[] = "skillLevel {$skill}={$lvl} > absurdalny sufit (".self::ABSURD_SKILL_CAP.')';
            }
        }

        if ($hard !== []) {
            throw new StateValidationException(
                'Odrzucono commit (HARD niezmiennik): '.implode('; ', $hard),
            );
        }

        $soft = [];

        $prevGold = $this->finiteNumber($prev['inventory']['gold'] ?? 0);
        if ($gold - $prevGold > self::SOFT_GOLD_DELTA) {
            $soft[] = "przyrost golda w jednym commicie ({$prevGold} -> {$gold}, > ".self::SOFT_GOLD_DELTA.')';
        }

        $stats = is_array($next['_characterStats'] ?? null) ? $next['_characterStats'] : [];
        try {
            $recomputed = $effective->getEffectiveChar(
                $stats,
                $this->equipmentFrom($next),
                $this->skillLevelsFrom($next),
                (string) $character->class,
            );
            foreach (['attack', 'defense', 'max_hp'] as $field) {
                $claimed = (float) $this->finiteNumber($stats[$field] ?? 0);
                $ceil = (float) $this->finiteNumber($recomputed[$field] ?? 0) * self::BASE_STAT_MARGIN;
                if ($ceil > 0 && $claimed > $ceil) {
                    $soft[] = "bazowy stat {$field}={$claimed} > recompute×".self::BASE_STAT_MARGIN." (~{$ceil})";
                }
            }
        } catch (Throwable) {
        }

        if ($soft !== []) {
            if ($strict) {
                throw new StateValidationException(
                    'Odrzucono commit (STRICT niezmiennik): '.implode('; ', $soft),
                );
            }
            Log::warning('state.commit: invariant soft-violations (SOFT — zapisuję mimo to)', [
                'character_id' => $character->id,
                'violations' => $soft,
            ]);
        }
    }

    private function guardEvent(Character $character, array $prev, array $next, array $event, bool $eventStrict): void
    {
        $result = (new EventValidation)->evaluate($prev, $next, $event, $character);

        if ($result['soft'] !== []) {
            if ($eventStrict) {
                throw new StateValidationException(
                    'Odrzucono commit zdarzenia (STRICT): '.implode('; ', $result['soft']),
                );
            }
            Log::warning('state.commit: event soft-violations (SOFT — zapisuję mimo to)', [
                'character_id' => $character->id,
                'event' => $event,
                'violations' => $result['soft'],
                'newItems' => $result['newItems'],
            ]);
        }
    }

    public function sanitizeState(array $state): array
    {
        $state['inventory']['gold'] = $this->sanitizeGold($state['inventory']['gold'] ?? 0);

        if (isset($state['_characterStats']) && is_array($state['_characterStats'])) {
            foreach (self::STAT_INT_FIELDS as $field) {
                if (array_key_exists($field, $state['_characterStats'])) {
                    $state['_characterStats'][$field] = (int) $this->finiteNumber($state['_characterStats'][$field]);
                }
            }
            foreach (self::STAT_FLOAT_FIELDS as $field) {
                if (array_key_exists($field, $state['_characterStats'])) {
                    $state['_characterStats'][$field] = (float) $this->finiteNumber($state['_characterStats'][$field]);
                }
            }
            if (array_key_exists('gold', $state['_characterStats'])) {
                $state['_characterStats']['gold'] = $this->sanitizeGold($state['_characterStats']['gold']);
            }
        }

        return $state;
    }

    public function validateState(Character $character, array $state, EffectiveStats $effective): array
    {
        $violations = [];

        foreach ($this->collectItems($state) as $where => $items) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach ($this->itemStatViolations($item) as $msg) {
                    $violations[] = "{$where}: {$msg}";
                }
            }
        }

        $stats = is_array($state['_characterStats'] ?? null) ? $state['_characterStats'] : [];
        $level = max(1, (int) ($stats['level'] ?? $character->level));
        $xp = (int) ($stats['xp'] ?? 0);
        if ($xp < 0) {
            $violations[] = "xp ujemne ({$xp})";
        }
        $xpCeil = LevelSystem::xpToNextLevel($level) * 100;
        if ($xpCeil > 0 && $xp > $xpCeil) {
            $violations[] = "xp {$xp} niewiarygodne dla poziomu {$level} (max ~{$xpCeil})";
        }

        try {
            $effective->getEffectiveChar(
                is_array($state['_characterStats'] ?? null) ? $state['_characterStats'] : [],
                $this->equipmentFrom($state),
                $this->skillLevelsFrom($state),
                (string) $character->class,
            );
        } catch (Throwable $e) {
            $violations[] = 'getEffectiveChar rzucił: '.$e->getMessage();
        }

        return $violations;
    }


    private const STAT_INT_FIELDS = [
        'level', 'xp', 'hp', 'max_hp', 'mp', 'max_mp', 'attack', 'defense',
        'magic_level', 'stat_points', 'highest_level',
    ];

    private const STAT_FLOAT_FIELDS = ['attack_speed', 'crit_chance', 'crit_damage'];

    private const RARITY_BONUS_CEIL = [
        'common' => 5, 'rare' => 12, 'epic' => 18, 'legendary' => 35, 'mythic' => 60, 'heroic' => 100,
    ];


    private const ABSURD_GOLD_CAP = 1_000_000_000_000;

    private const ABSURD_STACK_CAP = 100_000;

    private const ABSURD_ARENA_CAP = 1_000_000_000;

    private const ABSURD_SKILL_CAP = 500;


    private const SOFT_GOLD_DELTA = 2_000_000_000;

    private const BASE_STAT_MARGIN = 1.25;

    private function applyStatsToRow(Character $character, array $state): void
    {
        $stats = is_array($state['_characterStats'] ?? null) ? $state['_characterStats'] : [];

        foreach (self::STAT_INT_FIELDS as $field) {
            if (array_key_exists($field, $stats)) {
                $character->{$field} = (int) $stats[$field];
            }
        }
        foreach (self::STAT_FLOAT_FIELDS as $field) {
            if (array_key_exists($field, $stats)) {
                $character->{$field} = (float) $stats[$field];
            }
        }

        $character->gold = (int) ($state['inventory']['gold'] ?? $character->gold);
    }

    private function collectItems(array $state): array
    {
        $inv = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];
        $equipment = is_array($inv['equipment'] ?? null) ? array_values($inv['equipment']) : [];
        $bag = is_array($inv['bag'] ?? null) ? $inv['bag'] : [];

        return ['equipment' => $equipment, 'bag' => $bag];
    }

    private function itemStatViolations(array $item): array
    {
        $rarity = (string) ($item['rarity'] ?? 'common');
        $itemLevel = max(1, (int) ($item['itemLevel'] ?? 1));
        $upgrade = max(0, (int) ($item['upgradeLevel'] ?? 0));

        $rarityCeil = self::RARITY_BONUS_CEIL[$rarity] ?? self::RARITY_BONUS_CEIL['heroic'];
        $upgradeMult = 1 + 0.10 * $upgrade;
        $perStatCeil = (int) ceil(($rarityCeil * 2 + $itemLevel * 25) * $upgradeMult * 3);

        $out = [];
        foreach ((array) ($item['bonuses'] ?? []) as $key => $val) {
            if (! is_numeric($val)) {
                continue;
            }
            if ((float) $val > $perStatCeil) {
                $id = (string) ($item['itemId'] ?? '?');
                $out[] = "item {$id} bonus {$key}={$val} > legalne max {$perStatCeil}";
            }
        }

        return $out;
    }

    private function equipmentFrom(array $state): array
    {
        $inv = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];

        return is_array($inv['equipment'] ?? null) ? $inv['equipment'] : [];
    }

    private function skillLevelsFrom(array $state): array
    {
        $skills = is_array($state['skills'] ?? null) ? $state['skills'] : [];
        $levels = $skills['skillLevels'] ?? ($state['skillLevels'] ?? []);

        return is_array($levels) ? $levels : [];
    }

    private function sanitizeGold(mixed $value): int
    {
        $n = $this->finiteNumber($value);

        return (int) max(0, $n);
    }

    private function finiteNumber(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }
        $n = $value + 0;

        return is_finite((float) $n) ? $n : 0;
    }


    public function gold(GameSave $save): int
    {
        return (int) ($save->state['inventory']['gold'] ?? 0);
    }

    public function addGold(GameSave $save, int $amount): void
    {
        if ($amount < 0) {
            throw new RuntimeException('addGold: ujemna kwota.');
        }
        $state = $save->state;
        $state['inventory']['gold'] = $this->gold($save) + $amount;
        $save->state = $state;
    }

    public function spendGold(GameSave $save, int $amount): void
    {
        if ($amount < 0) {
            throw new RuntimeException('spendGold: ujemna kwota.');
        }
        $current = $this->gold($save);
        if ($current < $amount) {
            throw new InsufficientFundsException("Za mało golda: masz {$current}, potrzeba {$amount}.");
        }
        $state = $save->state;
        $state['inventory']['gold'] = $current - $amount;
        $save->state = $state;
    }


    public function findBagItem(GameSave $save, string $uuid): ?array
    {
        foreach (($save->state['inventory']['bag'] ?? []) as $item) {
            if (($item['uuid'] ?? null) === $uuid) {
                return $item;
            }
        }

        return null;
    }

    public function addBagItem(GameSave $save, array $item): void
    {
        $state = $save->state;
        $state['inventory']['bag'] = [...($state['inventory']['bag'] ?? []), $item];
        $save->state = $state;
    }

    public function removeBagItem(GameSave $save, string $uuid): array
    {
        $state = $save->state;
        $bag = $state['inventory']['bag'] ?? [];
        foreach ($bag as $i => $item) {
            if (($item['uuid'] ?? null) === $uuid) {
                array_splice($bag, $i, 1);
                $state['inventory']['bag'] = $bag;
                $save->state = $state;

                return $item;
            }
        }

        throw new RuntimeException("Item {$uuid} nie istnieje w torbie.");
    }

    public function equipFromBag(GameSave $save, string $uuid, string $slot): ?array
    {
        $item = $this->removeBagItem($save, $uuid);
        $state = $save->state;
        $previous = $state['inventory']['equipment'][$slot] ?? null;
        $state['inventory']['equipment'][$slot] = $item;
        if ($previous !== null) {
            $state['inventory']['bag'] = [...($state['inventory']['bag'] ?? []), $previous];
        }
        $save->state = $state;

        return $previous;
    }

    public function unequipToBag(GameSave $save, string $slot): array
    {
        $state = $save->state;
        $item = $state['inventory']['equipment'][$slot] ?? null;
        if ($item === null) {
            throw new RuntimeException("Slot {$slot} jest pusty.");
        }
        $state['inventory']['equipment'][$slot] = null;
        $state['inventory']['bag'] = [...($state['inventory']['bag'] ?? []), $item];
        $save->state = $state;

        return $item;
    }

    public function updateBagItem(GameSave $save, string $uuid, array $newItem): void
    {
        $state = $save->state;
        $bag = $state['inventory']['bag'] ?? [];
        foreach ($bag as $i => $item) {
            if (($item['uuid'] ?? null) === $uuid) {
                $bag[$i] = $newItem;
                $state['inventory']['bag'] = $bag;
                $save->state = $state;

                return;
            }
        }

        throw new RuntimeException("Item {$uuid} nie istnieje w torbie.");
    }

    public function moveBagToDeposit(GameSave $save, string $uuid): void
    {
        $item = $this->removeBagItem($save, $uuid);
        $state = $save->state;
        $state['inventory']['deposit'] = [...($state['inventory']['deposit'] ?? []), $item];
        $save->state = $state;
    }

    public function moveDepositToBag(GameSave $save, string $uuid): void
    {
        $state = $save->state;
        $deposit = $state['inventory']['deposit'] ?? [];
        foreach ($deposit as $i => $item) {
            if (($item['uuid'] ?? null) === $uuid) {
                array_splice($deposit, $i, 1);
                $state['inventory']['deposit'] = $deposit;
                $state['inventory']['bag'] = [...($state['inventory']['bag'] ?? []), $item];
                $save->state = $state;

                return;
            }
        }

        throw new RuntimeException("Item {$uuid} nie istnieje w skrytce.");
    }


    public function addConsumable(GameSave $save, string $id, int $count): void
    {
        $state = $save->state;
        $state['inventory']['consumables'][$id] = max(0, (int) ($state['inventory']['consumables'][$id] ?? 0) + $count);
        $save->state = $state;
    }

    public function useConsumable(GameSave $save, string $id, int $count): void
    {
        $have = (int) ($save->state['inventory']['consumables'][$id] ?? 0);
        if ($have < $count) {
            throw new InsufficientFundsException("Za mało {$id}: masz {$have}, potrzeba {$count}.");
        }
        $this->addConsumable($save, $id, -$count);
    }

    public function addStones(GameSave $save, string $type, int $count): void
    {
        $state = $save->state;
        $state['inventory']['stones'][$type] = max(0, (int) ($state['inventory']['stones'][$type] ?? 0) + $count);
        $save->state = $state;
    }

    public function useStones(GameSave $save, string $type, int $count): void
    {
        $have = (int) ($save->state['inventory']['stones'][$type] ?? 0);
        if ($have < $count) {
            throw new InsufficientFundsException("Za mało kamieni {$type}: masz {$have}, potrzeba {$count}.");
        }
        $this->addStones($save, $type, -$count);
    }

    public function addArenaPoints(GameSave $save, int $amount): void
    {
        $state = $save->state;
        $state['inventory']['arenaPoints'] = max(0, (int) ($state['inventory']['arenaPoints'] ?? 0) + $amount);
        $save->state = $state;
    }


    public function writeClientPrefs(GameSave $save, array $settings): void
    {
        $state = $save->state;
        $state['settings'] = $settings;
        $save->state = $state;
    }
}
