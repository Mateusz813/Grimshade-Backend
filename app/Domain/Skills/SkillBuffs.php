<?php

declare(strict_types=1);

namespace App\Domain\Skills;

/**
 * Port src/systems/skillBuffs.ts (frontend). Parser pola `effect` skilla
 * (format v2 rozdzielany `;` i `:`, np. "crit_buff:30:10000" albo
 * "aoe;party_attack_up:50:30000") na operacje BuffStore. Czysta matematyka
 * stackowania/mnożników — bez RNG, bez zależności od frameworka.
 *
 * ZAKRES PORTU (parytet golden): applySkillBuff w TS to void MUTUJĄCY
 * BuffStore, więc nie zwraca wyniku. Tu zwracamy SEKWENCJĘ operacji (op-log)
 * jaką skill by wykonał na store — dokładnie te same wywołania w tej samej
 * kolejności co TS: addChargeBuff / removeBuffByEffect / addBuffGameTime.
 *
 * ŚWIADOMIE POMINIĘTE (UI, patrz golden test): etykiety PL (buffFromAtom
 * `label`), ikony (getSkillIcon, spec.icon) i displayowy `name`/`labelSuffix`.
 * W op-logu zostają tylko LICZBY i PROTOKÓŁ-KLUCZE: id/effect buffa,
 * chargesToAdd, cap, durationMs, healPctPerSec oraz flaga `isParty`
 * (po stronie TS wyprowadzana z `label.startsWith('Party')`, tu z klasyfikacji
 * atomu — patrz PARTY_TIMED_ATOMS).
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/skillBuffs.json (generowane
 * z TS) odtwarzane bajt-w-bajt (SkillBuffsTest). Zmiana logiki w TS regeneruje
 * fixture i wymusza aktualizację tu.
 */
final class SkillBuffs
{
    /**
     * Atomy śledzone jako CHARGE-buffy (BuffBar renderuje "×N" zamiast timera,
     * konsumowane per akcja). Wprost z CHARGE_ATOMS w skillBuffs.ts.
     *
     * @var list<string>
     */
    public const CHARGE_ATOMS = [
        'dodge_next', 'dmg_amp_next', 'crit_next', 'crit_buff_next',
        'block_next_party', 'next_ally_heal', 'party_lifesteal_next',
        'party_instant_kill_chance_next',
    ];

    /**
     * Timed atomy, których etykieta w buffFromAtom zaczyna się od "Party"
     * (`spec.label.startsWith('Party')`) → nazwa buffa dostaje suffix
     * " (party)". Tu reprezentowane jako flaga `isParty` w op-logu.
     *
     * @var list<string>
     */
    private const PARTY_TIMED_ATOMS = [
        'party_attack_up', 'party_defense_up', 'party_def_pen',
        'party_as_up', 'party_crit_up', 'party_immortal', 'heal_party_dot',
    ];

    /** @var array<string, array<string, mixed>>|null Płaski indeks skilli po id (lazy). */
    private ?array $skillIndex = null;

    /**
     * @param  array<string, mixed>  $skillsContent  zdekodowany skills.json (z ContentRepository)
     */
    public function __construct(private readonly array $skillsContent) {}

    /**
     * Port getSkillDef: lookup definicji skilla po id z activeSkills.
     * Zwraca surowy wiersz (jak w JSON) albo null gdy brak.
     *
     * @return array<string, mixed>|null
     */
    public function getSkillDef(string $skillId): ?array
    {
        if ($this->skillIndex === null) {
            $this->skillIndex = self::buildIndex($this->skillsContent);
        }

        return $this->skillIndex[$skillId] ?? null;
    }

    /**
     * Płaski indeks każdego active skilla po id. Kolejność iteracji =
     * kolejność kluczy activeSkills → duplikaty id: ostatni wygrywa (jak JS).
     *
     * @param  array<string, mixed>  $skillsContent
     * @return array<string, array<string, mixed>>
     */
    private static function buildIndex(array $skillsContent): array
    {
        $out = [];
        /** @var array<string, list<array<string, mixed>>> $active */
        $active = $skillsContent['activeSkills'] ?? [];
        foreach ($active as $classSkills) {
            foreach ($classSkills as $skill) {
                $out[$skill['id']] = $skill;
            }
        }

        return $out;
    }

    /**
     * Klucz effect BuffStore dla charge-buffa. Stały protokół `skill_charge_<head>`
     * — musi być identyczny w silniku i widoku (consumeBuffCharge go szuka).
     */
    public static function chargeBuffEffectKey(string $atomHead): string
    {
        return "skill_charge_{$atomHead}";
    }

    /** Czy dany head atomu jest CHARGE-buffem. */
    public static function isChargeAtom(string $head): bool
    {
        return in_array($head, self::CHARGE_ATOMS, true);
    }

    /**
     * Cap stacka charge-buffa = chargesToAdd × 2 (min 1). "Dwa casty w zapasie"
     * bez nieskończonego spamu. Wyjątek (Boski Filar) liczony w applySkillBuff.
     */
    public static function chargeStackCap(int $chargesToAdd): int
    {
        return max(1, $chargesToAdd * 2);
    }

    /**
     * Port applySkillBuff: przechodzi po atomach effectu i emituje operacje
     * BuffStore (op-log). No-op dla skilli obrażeniowych, debuffów wroga,
     * pasywek bez czasu i summonów.
     *
     * @return list<array<string, mixed>> uporządkowana sekwencja operacji
     */
    public static function applySkillBuff(string $skillId, ?string $effect): array
    {
        $ops = [];
        // TS: `if (!effect) return;` — null i pusty string kończą wcześnie.
        if ($effect === null || $effect === '') {
            return $ops;
        }

        // Multi-atom (np. "aoe;party_attack_up:50:30000") — jedna operacja
        // per kwalifikujący się atom; indeks `i` zachowany także dla
        // pominiętych atomów (klucze buffów go używają).
        $atoms = explode(';', $effect);
        foreach ($atoms as $i => $rawAtom) {
            $atom = trim($rawAtom);
            $head = self::atomHead($atom);

            // -- Gałąź CHARGE-buffa (Krok Cienia / Unik / next-N) -----------
            if (self::isChargeAtom($head)) {
                $parts = explode(':', $atom);
                if ($head === 'dmg_amp_next' || $head === 'next_ally_heal' || $head === 'party_lifesteal_next') {
                    $chargesToAdd = self::jsParseIntOr($parts[2] ?? '1', 1);
                } elseif ($head === 'crit_buff_next') {
                    $chargesToAdd = 1;
                } else {
                    $chargesToAdd = self::jsParseIntOr($parts[1] ?? '0', 0);
                }
                if ($chargesToAdd <= 0) {
                    continue;
                }
                $effectKey = self::chargeBuffEffectKey($head);
                // Boski Filar → flat cap (bez ×2); reszta → chargesToAdd × 2.
                $cap = $head === 'party_lifesteal_next'
                    ? max(1, $chargesToAdd)
                    : self::chargeStackCap($chargesToAdd);
                $ops[] = [
                    'op' => 'addChargeBuff',
                    'id' => "skill_charge_{$skillId}_{$i}",
                    'effect' => $effectKey,
                    'chargesToAdd' => $chargesToAdd,
                    'cap' => $cap,
                ];

                continue;
            }

            // -- Gałąź timed buffa (buffFromAtom) ---------------------------
            // buffFromAtom parsuje po `.toLowerCase()`, więc n1/n2 z lowercased.
            $lparts = explode(':', strtolower($atom));
            $n1 = self::jsParseFloat($lparts[1] ?? '0');
            $n2 = self::jsParseFloat($lparts[2] ?? '0');
            $duration = self::timedDurationMs($head, $n1, $n2);
            // TS: `if (!spec || spec.durationMs <= 0) continue;` (NaN <= 0 = false).
            if ($duration === null || $duration <= 0) {
                continue;
            }

            $effectKey = "skill_{$skillId}_{$i}";
            // Refresh semantics: re-cast resetuje timer (remove + add).
            $ops[] = ['op' => 'removeBuffByEffect', 'effect' => $effectKey];

            $healPctPerSec = null;
            if ($head === 'heal_party_dot') {
                // TS: parseFloat(atom.split(':')[2] ?? '0') || 0 — NaN → 0.
                $splitParts = explode(':', $atom);
                $hp = self::jsParseFloat($splitParts[2] ?? '0');
                $healPctPerSec = is_nan($hp) ? 0.0 : $hp;
            }
            $ops[] = [
                'op' => 'addBuffGameTime',
                'id' => "skill_buff_{$skillId}_{$i}",
                'effect' => $effectKey,
                'durationMs' => $duration,
                'isParty' => in_array($head, self::PARTY_TIMED_ATOMS, true),
                'healPctPerSec' => $healPctPerSec,
            ];
        }

        return $ops;
    }

    /** Head atomu = lowercased pierwszy segment przed `:` (jak w TS). */
    private static function atomHead(string $atom): string
    {
        return explode(':', strtolower($atom), 2)[0];
    }

    /**
     * Odwzorowanie buffFromAtom → durationMs dla TIMED atomów. null gdy atom
     * nie jest rozpoznanym timed buffem (buffFromAtom zwróciłby null).
     * Atomy *_next są CHARGE i nigdy nie trafiają tutaj.
     */
    private static function timedDurationMs(string $head, float $n1, float $n2): ?float
    {
        return match ($head) {
            'crit_buff', 'attack_up', 'dodge_buff',
            'party_attack_up', 'party_defense_up', 'party_def_pen',
            'party_as_up', 'party_crit_up' => $n2,
            'immortal', 'mana_shield', 'party_immortal', 'heal_party_dot' => $n1,
            'aggro_steal' => 2000.0,
            default => null,
        };
    }

    /**
     * Odwzorowanie JS `parseInt(str, 10)`: pomija wiodące białe znaki, czyta
     * opcjonalny znak + cyfry base-10, zatrzymuje na pierwszym nie-cyfrze.
     * null = NaN (brak liczby).
     */
    private static function jsParseInt(string $s): ?int
    {
        $s = ltrim($s);
        if (preg_match('/^[+-]?\d+/', $s, $m) === 1) {
            return (int) $m[0];
        }

        return null;
    }

    /**
     * Odwzorowanie JS `parseInt(str, 10) || default`: falsy (NaN lub 0) →
     * default; wartości truthy (w tym ujemne) zachowane.
     */
    private static function jsParseIntOr(string $s, int $default): int
    {
        $n = self::jsParseInt($s);
        if ($n === null || $n === 0) {
            return $default;
        }

        return $n;
    }

    /**
     * Odwzorowanie JS `parseFloat(str)`: pomija wiodące białe znaki, czyta
     * opcjonalny znak, mantysę i wykładnik. NAN gdy brak liczby.
     */
    private static function jsParseFloat(string $s): float
    {
        $s = ltrim($s);
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $s, $m) === 1) {
            return (float) $m[0];
        }

        return NAN;
    }
}
