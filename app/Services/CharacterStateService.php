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

    // ---- Autorytatywny commit pełnego stanu (klient liczy walkę) -------------

    /**
     * Przyjmuje PEŁNY blob stanu od klienta (który liczy walkę własnym silnikiem)
     * i zapisuje autorytatywnie: game_saves.state = zsanityzowany blob, kolumny
     * characters ← `_characterStats`. Zawsze SANITYZUJE (gold >=0, pola numeryczne).
     * Waliduje niezmienniki; w trybie SOFT (domyślnym) tylko loguje naruszenia i
     * zapisuje mimo to — NIE odrzuca legalnego end-game gearu właściciela. Tylko
     * strict === true rzuca StateValidationException (→ 422).
     *
     * Wywoływać w transakcji z lockForUpdate na characters + game_saves.
     *
     * Gdy podano `$event` (semantyczny opis walki), DODATKOWO uruchamia bramkę
     * EventValidation: DIFFUJE poprzedni (zablokowany) blob względem przysłanego i
     * egzekwuje HARD (dupe uuid / gold — ZAWSZE 422) oraz SOFT (nowe itemy, dzienne
     * limity, spójność śmierci, skok poziomu — 422 tylko gdy $eventStrict). Bez
     * `$event` zachowanie jest identyczne jak dotąd (wsteczna zgodność).
     *
     * @param  array<string, mixed>  $submittedState  pełny blob (jak GET /state.state)
     * @param  array<string, mixed>|null  $event  semantyczny opis zdarzenia (opcjonalny)
     * @return array<string, mixed> zsanityzowany blob (zapisany)
     */
    public function commit(
        Character $character,
        GameSave $save,
        array $submittedState,
        EffectiveStats $effective,
        bool $strict,
        ?array $event = null,
        bool $eventStrict = false,
    ): array {
        // Poprzedni (zablokowany) blob PRZED nadpisaniem — baza diffu zdarzeń.
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

        if ($event !== null) {
            $this->guardEvent($character, $prev, $sanitized, $event, $eventStrict);
        }

        $this->applyStatsToRow($character, $sanitized);

        $save->state = $sanitized;
        $this->persist($save);
        $character->save(); // bump characters.updated_at

        return $sanitized;
    }

    /**
     * Bramka zdarzenia: DIFF prev vs next przez EventValidation. HARD (dupe uuid /
     * gold) → 422 ZAWSZE (rollback transakcji, nic nie zapisane). SOFT → 422 tylko
     * w trybie $eventStrict; inaczej Log::warning i zapis mimo to.
     *
     * @param  array<string, mixed>  $prev
     * @param  array<string, mixed>  $next
     * @param  array<string, mixed>  $event
     */
    private function guardEvent(Character $character, array $prev, array $next, array $event, bool $eventStrict): void
    {
        $result = (new EventValidation)->evaluate($prev, $next, $event, $character);

        if ($result['hard'] !== []) {
            throw new StateValidationException(
                'Odrzucono commit zdarzenia (HARD): '.implode('; ', $result['hard']),
            );
        }

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

    /**
     * Sanityzacja (ZAWSZE, nigdy nie rzuca): gold skończony i >= 0, pola numeryczne
     * `_characterStats` skoercowane do liczb (undefined/NaN/Inf → 0).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function sanitizeState(array $state): array
    {
        // Prawdziwy gold gry: state.inventory.gold — klamp >= 0, skończony.
        $state['inventory']['gold'] = $this->sanitizeGold($state['inventory']['gold'] ?? 0);

        // Pola numeryczne kolumn postaci — skoercuj do liczb; gold klamp >= 0.
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

    /**
     * Niezmienniki (używane w SOFT do logowania, w STRICT do 422). GENEROUS bounds —
     * legalny end-game gear MUSI przechodzić. Zwraca listę naruszeń (puste = OK).
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public function validateState(Character $character, array $state, EffectiveStats $effective): array
    {
        $violations = [];

        // 1) Bounds statów itemów (equipment + bag) — nie mogą przekraczać
        //    hojnego legalnego maksimum dla rarity/poziomu/upgrade.
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

        // 2) Plausibilność level <-> xp (LevelSystem). Gracz może bankować XP,
        //    więc próg jest bardzo luźny.
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

        // 3) Efektywne staty muszą się policzyć bez wyjątku (parytet z frontem).
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

    // ---- Wewnętrzne helpery commitu -----------------------------------------

    /** @var list<string> */
    private const STAT_INT_FIELDS = [
        'level', 'xp', 'hp', 'max_hp', 'mp', 'max_mp', 'attack', 'defense',
        'magic_level', 'stat_points', 'highest_level',
    ];

    /** @var list<string> */
    private const STAT_FLOAT_FIELDS = ['attack_speed', 'crit_chance', 'crit_damage'];

    /** Hojne maks. na pojedynczy bonus itemu per rarity (przed skalowaniem poziomem/upgrade). */
    private const RARITY_BONUS_CEIL = [
        'common' => 5, 'rare' => 12, 'epic' => 18, 'legendary' => 35, 'mythic' => 60, 'heroic' => 100,
    ];

    /**
     * Zapisuje kolumny characters z `_characterStats` (fallback: bieżąca wartość).
     * Gold kolumny = inventory.gold (szczątkowa spójność — prawdziwy gold w blobie).
     *
     * @param  array<string, mixed>  $state
     */
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

        // Szczątkowa kolumna gold = prawdziwy gold z blobu (spójność rankingów).
        $character->gold = (int) ($state['inventory']['gold'] ?? $character->gold);
    }

    /** @return array<string, list<mixed>> where => items */
    private function collectItems(array $state): array
    {
        $inv = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];
        $equipment = is_array($inv['equipment'] ?? null) ? array_values($inv['equipment']) : [];
        $bag = is_array($inv['bag'] ?? null) ? $inv['bag'] : [];

        return ['equipment' => $equipment, 'bag' => $bag];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function itemStatViolations(array $item): array
    {
        $rarity = (string) ($item['rarity'] ?? 'common');
        $itemLevel = max(1, (int) ($item['itemLevel'] ?? 1));
        $upgrade = max(0, (int) ($item['upgradeLevel'] ?? 0));

        // Hojny sufit: (rarityBonusCeil + poziom*sufitNaPoziom) * upgradeMult * bufor.
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

    /** @return array<string, mixed> */
    private function equipmentFrom(array $state): array
    {
        $inv = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];

        return is_array($inv['equipment'] ?? null) ? $inv['equipment'] : [];
    }

    /** @return array<string, int> */
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

    /** Koercja do skończonej liczby; śmieci/NaN/Inf → 0. */
    private function finiteNumber(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }
        $n = $value + 0;

        return is_finite((float) $n) ? $n : 0;
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
