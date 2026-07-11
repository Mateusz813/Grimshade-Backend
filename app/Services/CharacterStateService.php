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
     * ALWAYS-RUN: guardInvariants() DIFFUJE poprzedni (zablokowany) blob `$prev`
     * względem przysłanego, zsanityzowanego `$next` na KAŻDYM commicie (z eventem
     * czy bez) i egzekwuje HARD niezmienniki — dupe uuid, skok poziomu >50,
     * absurdalne sufity (gold/stacki/arena/skill) — ZAWSZE → 422, niezależnie od
     * jakiegokolwiek flagu. To zamyka bypass "pomiń `event` = pomiń walidację".
     *
     * Gdy DODATKOWO podano `$event` (semantyczny opis walki), guardEvent() liczy
     * naruszenia WYMAGAJĄCE kontekstu zdarzenia (dzienne limity, spójność śmierci,
     * spadek poziomu bez śmierci) — SOFT, 422 tylko gdy $eventStrict.
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

        // ALWAYS-RUN: HARD niezmienniki na KAŻDYM commicie (event obecny czy nie) —
        // zamyka bypass "pomiń event = pomiń walidację". HARD → 422 niezależnie od flag.
        $this->guardInvariants($character, $prev, $sanitized, $effective, $strict);

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
     * ALWAYS-RUN bramka niezmienników: DIFF `$prev` (zablokowany poprzedni blob) vs
     * `$next` (zsanityzowany przysłany) na KAŻDYM commicie — z eventem czy bez.
     *
     * HARD (ZAWSZE → 422, niezależnie od jakiegokolwiek flagu; rollback transakcji):
     *   1. duplikat uuid itemu w całym blobie (bag ∪ deposit ∪ 12 slotów equipment
     *      ∪ opcjonalny skarbiec/escrow) — legalny zapis fizycznie nie może trzymać
     *      tego samego uuid dwa razy → zero false-reject. #1 fix (anty-dupe/clone).
     *   2. skok poziomu wzwyż > EventValidation::MAX_LEVEL_JUMP w jednym commicie
     *      (tylko gdy istnieje poprzedni blob z poziomem — pierwszy commit ustala
     *      bazę, nie ma czego diffować). Spadek poziomu DOZWOLONY (kara śmierci).
     *   3. absurdalne sufity absolutne (łapią tylko absurd, nie umiarkowane wartości
     *      — właściciel ma ~363M golda, więc sufity są ~2750× nad legit): gold,
     *      consumables/stones (per stack), arenaPoints, skillLevels (per skill).
     *
     * SOFT (Log::warning; 422 wyłącznie gdy $strict = state_commit_strict — te niosą
     * ryzyko false-reject wobec realnego end-game save'u właściciela):
     *   4. delta golda w jednym commicie (jeden high-level dungeon legalnie płaci
     *      ~360M — NIE limitujemy delty twardo; logujemy tylko absurdalne przyrosty).
     *   5. magnituda bazowych statów (attack/defense/max_hp) vs recompute
     *      getEffectiveChar × margines (drift transform/eliksir + zaokrąglenia).
     *
     * @param  array<string, mixed>  $prev
     * @param  array<string, mixed>  $next
     */
    private function guardInvariants(Character $character, array $prev, array $next, EffectiveStats $effective, bool $strict): void
    {
        $hard = [];

        // HARD 1) Żaden uuid itemu nie może wystąpić >1 raz w całym blobie (rdzeń dupe).
        foreach ((new EventValidation)->duplicateUuids($next) as $uuid) {
            $hard[] = "duplikat uuid itemu: {$uuid}";
        }

        // HARD 2) Skok poziomu wzwyż > MAX_LEVEL_JUMP (tylko gdy jest poprzedni blob z poziomem).
        $prevLevelRaw = $prev['_characterStats']['level'] ?? null;
        if ($prevLevelRaw !== null) {
            $prevLevel = (int) $this->finiteNumber($prevLevelRaw);
            $nextLevel = (int) $this->finiteNumber($next['_characterStats']['level'] ?? $prevLevel);
            if ($nextLevel - $prevLevel > EventValidation::MAX_LEVEL_JUMP) {
                $hard[] = "niewiarygodny skok poziomu w jednym commicie ({$prevLevel} -> {$nextLevel}, max +".EventValidation::MAX_LEVEL_JUMP.')';
            }
        }

        // HARD 3) Absurdalne sufity absolutne.
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

        // ---- SOFT (log; 422 tylko w state_commit_strict) ------------------------
        $soft = [];

        // SOFT 4) Delta golda w jednym commicie (logujemy tylko absurdalne przyrosty).
        $prevGold = $this->finiteNumber($prev['inventory']['gold'] ?? 0);
        if ($gold - $prevGold > self::SOFT_GOLD_DELTA) {
            $soft[] = "przyrost golda w jednym commicie ({$prevGold} -> {$gold}, > ".self::SOFT_GOLD_DELTA.')';
        }

        // SOFT 5) Magnituda bazowych statów vs recompute × margines (log-only default).
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
            // getEffectiveChar rzucił — już raportowane w validateState; tu ignorujemy.
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

    /**
     * Bramka zdarzenia (event-context): DIFF prev vs next przez EventValidation dla
     * naruszeń WYMAGAJĄCYCH `event` (dzienne limity, spójność śmierci, spadek poziomu
     * bez śmierci) — wszystkie SOFT. Twarde niezmienniki egzekwuje guardInvariants
     * WCZEŚNIEJ, na każdym commicie. SOFT → 422 tylko w $eventStrict; inaczej log.
     *
     * @param  array<string, mixed>  $prev
     * @param  array<string, mixed>  $next
     * @param  array<string, mixed>  $event
     */
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

    // ---- ALWAYS-RUN HARD: absurdalne sufity absolutne (łapią tylko absurd) -------
    // Właściciel ma legalnie ~363M golda / level 345 / skille kilkaset — sufity są
    // rzędy wielkości nad legit, więc zero false-reject, a blokują koszmarny cheat.

    /** gold: właściciel ~363M → ~2750× zapas. */
    private const ABSURD_GOLD_CAP = 1_000_000_000_000; // 1e12

    /** consumables[*] / stones[*] (per stack). */
    private const ABSURD_STACK_CAP = 100_000;

    /** inventory.arenaPoints. */
    private const ABSURD_ARENA_CAP = 1_000_000_000; // 1e9

    /** skills.skillLevels[*] (per skill). */
    private const ABSURD_SKILL_CAP = 500;

    // ---- ALWAYS-RUN SOFT (log-only default; 422 tylko w state_commit_strict) -----

    /** Przyrost golda w jednym commicie powyżej którego logujemy (jeden dungeon ~360M legit). */
    private const SOFT_GOLD_DELTA = 2_000_000_000; // 2e9

    /** Margines tolerancji: bazowy stat vs serwerowy recompute getEffectiveChar. */
    private const BASE_STAT_MARGIN = 1.25;

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
