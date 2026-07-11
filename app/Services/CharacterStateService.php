<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Character;
use App\Models\GameSave;
use RuntimeException;

/**
 * Serwerowa władza nad blobem `game_saves.state`. WSZYSTKIE mutacje ekonomii
 * (prawdziwy gold = state.inventory.gold, itemy, consumables, kamienie) idą
 * przez ten serwis — kontrolery NIE dotykają bloba bezpośrednio.
 *
 * Konwencja: kontroler otwiera transakcję + lockForUpdate na wierszu game_saves
 * (przez lockedFor()), woła metody mutujące, na końcu persist(). Wszystkie
 * metody walidują niezmienniki (gold >= 0, item istnieje, stack >= ilość) i
 * rzucają InsufficientFundsException/RuntimeException → kontroler mapuje na 4xx.
 */
final class CharacterStateService
{
    /**
     * Wiersz bloba danej postaci Z LOCKIEM (wywoływać w transakcji).
     * Brak bloba = postać nigdy nie zapisała gry — tworzymy pusty szkielet.
     */
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

    /** Zapis bloba + świeży updated_at (front rozstrzyga konflikty po nim). */
    public function persist(GameSave $save): void
    {
        $save->updated_at = now();
        $save->save();
    }

    // ---- Gold (state.inventory.gold — PRAWDZIWY gold gry) -------------------

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

    // ---- Itemy (bag / equipment / deposit) ----------------------------------

    /**
     * @return array<string, mixed>|null item z bag po uuid
     */
    public function findBagItem(GameSave $save, string $uuid): ?array
    {
        foreach (($save->state['inventory']['bag'] ?? []) as $item) {
            if (($item['uuid'] ?? null) === $uuid) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function addBagItem(GameSave $save, array $item): void
    {
        $state = $save->state;
        $state['inventory']['bag'] = [...($state['inventory']['bag'] ?? []), $item];
        $save->state = $state;
    }

    /** Usuwa item z bag po uuid. Rzuca, gdy nie istnieje (anty-dupe). */
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

    /**
     * Załóż item z bag do slotu equipment (swap: poprzedni wraca do bag).
     *
     * @return array<string, mixed>|null zdjęty item (jeśli był)
     */
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

    /** Zdejmij item ze slotu do bag. */
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

    /** Mutacja itemu w bag (np. upgradeLevel po ulepszeniu). */
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

    /** Przenieś item bag → deposit (skrytka). */
    public function moveBagToDeposit(GameSave $save, string $uuid): void
    {
        $item = $this->removeBagItem($save, $uuid);
        $state = $save->state;
        $state['inventory']['deposit'] = [...($state['inventory']['deposit'] ?? []), $item];
        $save->state = $state;
    }

    /** Przenieś item deposit → bag. */
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

    // ---- Consumables / stones (stacki) --------------------------------------

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

    // ---- Preferencje klienta (jedyny wycinek, który klient może nadpisać) ----

    /**
     * Nadpisuje WYŁĄCZNIE wycinek `settings` (UI prefs — nie autorytet).
     * Wszystkie inne wycinki bloba są serwerowe.
     *
     * @param  array<string, mixed>  $settings
     */
    public function writeClientPrefs(GameSave $save, array $settings): void
    {
        $state = $save->state;
        $state['settings'] = $settings;
        $save->state = $state;
    }
}
