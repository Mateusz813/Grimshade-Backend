<?php

declare(strict_types=1);

namespace App\Domain\Skills;

use App\Domain\Support\Rng\RngInterface;

/**
 * Port 1:1 src/systems/skillEffectsV2.ts (frontend). Ujednolicony runtime
 * efektów skilli: parsowanie stringów efektów, mutacja stanu statusów
 * (DOT/stun/marki/buffy), rozwiązanie trafień podstawowych i redukcja
 * przychodzących obrażeń/leczenia.
 *
 * PARYTET: golden-vectory w tests/Golden/fixtures/skillEffectsV2.json (generowane
 * z TS) są tu odtwarzane 1:1 (SkillEffectsV2Test). Stan combatanta jest
 * reprezentowany jako tablica asocjacyjna (bliźniak TS interfejsu IStatusState);
 * funkcje mutujące biorą stan przez REFERENCJĘ (`array &$s`) tak jak TS mutuje
 * obiekt w miejscu.
 *
 * SEMANTYKA LICZB JS: JS nie rozróżnia int/float, więc porównania `d.mult === m`
 * portujemy jako luźne `==` (dwie liczby są równe wartością bez względu na typ).
 * `Math.floor` → `(int) floor`, `x || 1` → `x ?: 1`.
 *
 * RNG (rule #2 — stała kolejność konsumpcji Math.random):
 *  - applyEffects: `stun_chance` (rzut per cel), `instant_kill_chance` (1 rzut).
 *  - resolveBasicHit: `dodge_buff`, `crit_buff_next`, party instant-kill.
 *  - consumeCasterBasicHitMods: `crit_next` z ułamkowym mult (<1).
 * Każda z tych funkcji bierze RngInterface i konsumuje go w DOKŁADNIE tej samej
 * kolejności co TS, więc z tym samym seedem (mulberry32) daje identyczny wynik.
 *
 * PARYTET ALIASINGU: golden-vectory nie współdzielą tożsamości obiektów (caster
 * nie jest jednocześnie elementem `partyStatus`). Tablice PHP kopiowane są przez
 * wartość, więc niezależne obiekty gwarantują ten sam wynik po obu stronach.
 */
final class SkillEffectsV2
{
    /** Klasy magiczne — pomijane przez `dodge_next` o zakresie `non_magic`. */
    private const MAGIC_CLASSES = ['Mage', 'Cleric', 'Necromancer'];

    /** Głowy atomów, które oznaczają że cast „ląduje" na wrogu (skillTargetsEnemy). */
    private const ENEMY_AFFINITY_HEADS = [
        'aoe', 'def_pen', 'dot', 'stun', 'stun_chance', 'paralyze',
        'instant_kill_chance', 'execute_below', 'mark_amp', 'mark_amp_all',
        'mark_no_heal', 'mark_heal_to_dmg', 'enemy_atk_down', 'enemy_no_heal',
        'multistrike', 'dark_ritual', 'death_apocalypse',
    ];

    /**
     * Parsuje string `skills.json.effect` (np. "aoe;dot:5000:5") na atomy.
     *
     * @return list<array{key:string, raw:string, a?:float, b?:float, c?:float, s?:string}>
     */
    public static function parseEffects(?string $effect): array
    {
        if ($effect === null || $effect === '') {
            return [];
        }

        $out = [];
        foreach (explode(';', $effect) as $rawPiece) {
            $piece = trim($rawPiece);
            if ($piece === '') {
                continue;
            }

            $parts = explode(':', $piece);
            $keyRaw = array_shift($parts);
            $args = $parts;

            $parsed = ['key' => $keyRaw, 'raw' => $piece];
            if (count($args) >= 1) {
                $n = self::jsParseFloat($args[0]);
                if (! is_finite($n)) {
                    $parsed['s'] = $args[0];
                } else {
                    $parsed['a'] = $n;
                }
            }
            if (count($args) >= 2) {
                $n = self::jsParseFloat($args[1]);
                if (! is_finite($n)) {
                    $prefix = (isset($parsed['s']) && $parsed['s'] !== '') ? $parsed['s'].':' : '';
                    $parsed['s'] = $prefix.$args[1];
                } else {
                    $parsed['b'] = $n;
                }
            }
            if (count($args) >= 3) {
                $n = self::jsParseFloat($args[2]);
                if (is_finite($n)) {
                    $parsed['c'] = $n;
                }
            }
            $out[] = $parsed;
        }

        return $out;
    }

    /**
     * @param  list<array{key:string}>  $effects
     */
    public static function hasEffect(array $effects, string $key): bool
    {
        foreach ($effects as $e) {
            if ($e['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $effects
     * @return array<string, mixed>|null
     */
    public static function findEffect(array $effects, string $key): ?array
    {
        foreach ($effects as $e) {
            if ($e['key'] === $key) {
                return $e;
            }
        }

        return null;
    }

    /**
     * Domyślny, pusty stan statusu jednego combatanta (bliźniak newStatusState TS).
     *
     * @return array<string, mixed>
     */
    public static function newStatusState(): array
    {
        return [
            'stunMs' => 0,
            'immortalMs' => 0,
            'cannotDieMs' => 0,
            'cannotDieReviveAt' => null,
            'dots' => [],
            'dmgAmpNext' => [],
            'critNext' => [],
            'critBuffNext' => 0,
            'critBuffPct' => 0,
            'critBuffMs' => 0,
            'dodgeNext' => [],
            'dodgeBuffPct' => 0,
            'dodgeBuffMs' => 0,
            'atkBuffPct' => 0,
            'atkBuffMs' => 0,
            'defBuffPct' => 0,
            'defBuffMs' => 0,
            'asMult' => 1,
            'asMultMs' => 0,
            'partyCritPct' => 0,
            'partyCritMs' => 0,
            'defPenPct' => 0,
            'defPenMs' => 0,
            'markAmp' => [],
            'markAmpAll' => null,
            'markNoHealMs' => 0,
            'enemyAtkDownPct' => 0,
            'enemyAtkDownMs' => 0,
            'enemyNoHealMs' => 0,
            'lifestealNext' => [],
            'nextAllyHeal' => [],
            'nextAllyInstantKillPct' => [],
            'manaShieldMs' => 0,
            'darkRitualPending' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     */
    public static function isStunned(array $s): bool
    {
        return ($s['stunMs'] ?? 0) > 0;
    }

    /**
     * Dekrementuje timery / usuwa wygasłe efekty. Mutuje `$s` w miejscu.
     * Zwraca skumulowane obrażenia DOT + obrażenia i flagę Mrocznego Rytuału.
     *
     * @param  array<string, mixed>  $s
     * @return array{dotDamage:int, darkRitualDamage:int, darkRitualTriggered:bool}
     */
    public static function tickStatus(array &$s, int|float $deltaMs, int|float $targetMaxHp): array
    {
        $drain = static fn (int|float $n): int|float => max(0, $n - $deltaMs);

        $s['stunMs'] = $drain($s['stunMs']);
        $s['immortalMs'] = $drain($s['immortalMs']);
        $s['cannotDieMs'] = $drain($s['cannotDieMs']);
        $s['manaShieldMs'] = $drain($s['manaShieldMs']);
        $s['critBuffMs'] = $drain($s['critBuffMs']);
        if ($s['critBuffMs'] <= 0) {
            $s['critBuffPct'] = 0;
        }
        $s['dodgeBuffMs'] = $drain($s['dodgeBuffMs']);
        if ($s['dodgeBuffMs'] <= 0) {
            $s['dodgeBuffPct'] = 0;
        }
        $s['atkBuffMs'] = $drain($s['atkBuffMs']);
        if ($s['atkBuffMs'] <= 0) {
            $s['atkBuffPct'] = 0;
        }
        $s['defBuffMs'] = $drain($s['defBuffMs']);
        if ($s['defBuffMs'] <= 0) {
            $s['defBuffPct'] = 0;
        }
        $s['asMultMs'] = $drain($s['asMultMs']);
        if ($s['asMultMs'] <= 0) {
            $s['asMult'] = 1;
        }
        $s['partyCritMs'] = $drain($s['partyCritMs']);
        if ($s['partyCritMs'] <= 0) {
            $s['partyCritPct'] = 0;
        }
        $s['defPenMs'] = $drain($s['defPenMs']);
        if ($s['defPenMs'] <= 0) {
            $s['defPenPct'] = 0;
        }
        $s['markNoHealMs'] = $drain($s['markNoHealMs']);
        $s['enemyAtkDownMs'] = $drain($s['enemyAtkDownMs']);
        if ($s['enemyAtkDownMs'] <= 0) {
            $s['enemyAtkDownPct'] = 0;
        }
        $s['enemyNoHealMs'] = $drain($s['enemyNoHealMs']);

        $newMarkAmp = [];
        foreach ($s['markAmp'] as $m) {
            $m['remainingMs'] = max(0, $m['remainingMs'] - $deltaMs);
            if ($m['remainingMs'] > 0 && $m['count'] > 0) {
                $newMarkAmp[] = $m;
            }
        }
        $s['markAmp'] = $newMarkAmp;

        if ($s['markAmpAll'] !== null) {
            $s['markAmpAll']['remainingMs'] = max(0, $s['markAmpAll']['remainingMs'] - $deltaMs);
            if ($s['markAmpAll']['remainingMs'] <= 0) {
                $s['markAmpAll'] = null;
            }
        }

        $dotDamage = 0;
        if (count($s['dots']) > 0) {
            $survivors = [];
            foreach ($s['dots'] as $dot) {
                $elapsedSec = $deltaMs / 1000;
                $dotDamage += (int) floor($targetMaxHp * ($dot['pctPerSec'] / 100) * $elapsedSec);
                $next = $dot['remainingMs'] - $deltaMs;
                if ($next > 0) {
                    $survivors[] = ['remainingMs' => $next, 'pctPerSec' => $dot['pctPerSec']];
                }
            }
            $s['dots'] = $survivors;
        }

        // Necromancer Mroczny Rytuał — drenuj każdy wpis o deltaMs; przy 0 odpal.
        $darkRitualDamage = 0;
        $darkRitualTriggered = false;
        if (count($s['darkRitualPending']) > 0) {
            $survivors = [];
            foreach ($s['darkRitualPending'] as $r) {
                $next = $r['triggerInMs'] - $deltaMs;
                if ($next <= 0) {
                    $darkRitualDamage += max(1, (int) floor($targetMaxHp * ($r['pctOfMaxHp'] / 100)));
                    $darkRitualTriggered = true;
                } else {
                    $survivors[] = ['triggerInMs' => $next, 'pctOfMaxHp' => $r['pctOfMaxHp']];
                }
            }
            $s['darkRitualPending'] = $survivors;
        }

        return ['dotDamage' => $dotDamage, 'darkRitualDamage' => $darkRitualDamage, 'darkRitualTriggered' => $darkRitualTriggered];
    }

    /**
     * Domyślny wynik `applyEffects` (bliźniak blank() TS).
     *
     * @return array<string, mixed>
     */
    private static function blank(): array
    {
        return [
            'aoe' => false,
            'castDmgMult' => 1,
            'defPenPct' => 0,
            'instantKill' => false,
            'instantKillPct' => 0,
            'executeBurstPct' => 0,
            'healCasterPctOfDmg' => 0,
            'healCasterPctOfMaxHp' => 0,
            'healLowestAllyPct' => 0,
            'healPartyDotMs' => 0,
            'healPartyDotPctPerSec' => 0,
            'healPartyPctInstant' => 0,
            'multistrike' => 0,
            'addBlockNextPartyHits' => 0,
            'aggroSteal' => false,
            'summons' => [],
            'executeBelowPct' => 0,
            'revivePartyProtectMs' => 0,
            'revivePartyGraceMs' => 0,
            'reviveDeadAllies' => false,
            'partyImmortalMs' => 0,
            'stunApplied' => false,
            'aoeStunIdxs' => [],
            'paralyzeApplied' => false,
            'aoeParalyzeIdxs' => [],
            'deathApocalypse' => false,
            'deathApocalypseSelfHpFloor' => 0,
            'deathApocalypseTargetMaxHpPct' => 0,
        ];
    }

    /**
     * Aplikuje listę sparsowanych efektów do stanów (caster/target/party/enemy).
     * Mutuje przekazane stany w miejscu; zwraca „side effecty" (AOE, mnożniki,
     * summony, itd.) których nie da się wyrazić samą mutacją statusu.
     *
     * @param  list<array<string, mixed>>  $parsed
     * @param  array<string, mixed>  $casterStatus
     * @param  array<string, mixed>|null  $targetStatus
     * @param  list<array<string, mixed>>  $partyStatus
     * @param  list<array<string, mixed>>  $enemyStatus
     * @return array<string, mixed>
     */
    public static function applyEffects(
        RngInterface $rng,
        array $parsed,
        array &$casterStatus,
        ?array &$targetStatus,
        int|float $targetHpPct,
        array &$partyStatus,
        array &$enemyStatus,
    ): array {
        $r = self::blank();

        $isAoeCast = false;
        foreach ($parsed as $p) {
            if ($p['key'] === 'aoe') {
                $isAoeCast = true;
                break;
            }
        }

        foreach ($parsed as $e) {
            switch ($e['key']) {
                case 'aoe':
                    $r['aoe'] = true;
                    break;
                case 'def_pen':
                    $r['defPenPct'] = max($r['defPenPct'], $e['a'] ?? 0);
                    break;
                case 'dmg_amp_next':
                    $m = $e['a'] ?? 1;
                    $add = $e['b'] ?? 1;
                    $cap = max(1, $add * 2);
                    $found = false;
                    foreach ($casterStatus['dmgAmpNext'] as $di => $d) {
                        if ($d['mult'] == $m) {
                            $casterStatus['dmgAmpNext'][$di]['count'] = min($cap, $d['count'] + $add);
                            $found = true;
                            break;
                        }
                    }
                    if (! $found) {
                        $casterStatus['dmgAmpNext'][] = ['mult' => $m, 'count' => min($cap, $add)];
                    }
                    break;
                case 'crit_buff_next':
                    $casterStatus['critBuffNext'] = max($casterStatus['critBuffNext'], $e['a'] ?? 0);
                    break;
                case 'crit_buff':
                    $casterStatus['critBuffPct'] = max($casterStatus['critBuffPct'], $e['a'] ?? 0);
                    $casterStatus['critBuffMs'] = max($casterStatus['critBuffMs'], $e['b'] ?? 0);
                    break;
                case 'crit_next':
                    $m = $e['a'] ?? 1;
                    $add = $e['b'] ?? 1;
                    $cap = max(1, $add * 2);
                    $found = false;
                    foreach ($casterStatus['critNext'] as $di => $d) {
                        if ($d['mult'] == $m) {
                            $casterStatus['critNext'][$di]['count'] = min($cap, $d['count'] + $add);
                            $found = true;
                            break;
                        }
                    }
                    if (! $found) {
                        $casterStatus['critNext'][] = ['mult' => $m, 'count' => min($cap, $add)];
                    }
                    break;
                case 'multistrike':
                    $r['multistrike'] = max($r['multistrike'], (int) floor($e['a'] ?? 0));
                    break;
                case 'stun':
                    $dur = $e['a'] ?? 0;
                    if ($dur > 0) {
                        if ($isAoeCast) {
                            $n = count($enemyStatus);
                            for ($i = 0; $i < $n; $i++) {
                                $enemyStatus[$i]['stunMs'] = max($enemyStatus[$i]['stunMs'], $dur);
                                if (! in_array($i, $r['aoeStunIdxs'], true)) {
                                    $r['aoeStunIdxs'][] = $i;
                                }
                            }
                            if ($n > 0) {
                                $r['stunApplied'] = true;
                            }
                        } elseif ($targetStatus !== null) {
                            $targetStatus['stunMs'] = max($targetStatus['stunMs'], $dur);
                            $r['stunApplied'] = true;
                        }
                    }
                    break;
                case 'stun_chance':
                    $pct = $e['a'] ?? 0;
                    $dur = $e['b'] ?? 0;
                    if ($isAoeCast) {
                        $n = count($enemyStatus);
                        for ($i = 0; $i < $n; $i++) {
                            if ($rng->nextFloat() * 100 < $pct) {
                                $enemyStatus[$i]['stunMs'] = max($enemyStatus[$i]['stunMs'], $dur);
                                if (! in_array($i, $r['aoeStunIdxs'], true)) {
                                    $r['aoeStunIdxs'][] = $i;
                                }
                                $r['stunApplied'] = true;
                            }
                        }
                    } elseif ($targetStatus !== null && $rng->nextFloat() * 100 < $pct) {
                        $targetStatus['stunMs'] = max($targetStatus['stunMs'], $dur);
                        $r['stunApplied'] = true;
                    }
                    break;
                case 'paralyze':
                    $dur = $e['a'] ?? 0;
                    if ($dur > 0) {
                        if ($isAoeCast) {
                            $n = count($enemyStatus);
                            for ($i = 0; $i < $n; $i++) {
                                $enemyStatus[$i]['stunMs'] = max($enemyStatus[$i]['stunMs'], $dur);
                                if (! in_array($i, $r['aoeParalyzeIdxs'], true)) {
                                    $r['aoeParalyzeIdxs'][] = $i;
                                }
                            }
                            if ($n > 0) {
                                $r['paralyzeApplied'] = true;
                            }
                        } elseif ($targetStatus !== null) {
                            $targetStatus['stunMs'] = max($targetStatus['stunMs'], $dur);
                            $r['paralyzeApplied'] = true;
                        }
                    }
                    break;
                case 'dot':
                    $remainingMs = $e['a'] ?? 0;
                    $pctPerSec = $e['b'] ?? 0;
                    if ($isAoeCast) {
                        foreach ($enemyStatus as $ei => $en) {
                            $enemyStatus[$ei]['dots'][] = ['remainingMs' => $remainingMs, 'pctPerSec' => $pctPerSec];
                        }
                    } elseif ($targetStatus !== null) {
                        $targetStatus['dots'][] = ['remainingMs' => $remainingMs, 'pctPerSec' => $pctPerSec];
                    }
                    break;
                case 'instant_kill_chance':
                    $r['instantKillPct'] = max($r['instantKillPct'], $e['a'] ?? 0);
                    if ($rng->nextFloat() * 100 < ($e['a'] ?? 0)) {
                        $r['executeBurstPct'] = 12;
                    }
                    break;
                case 'execute_below':
                    if ($targetHpPct <= ($e['a'] ?? 0)) {
                        $r['instantKill'] = true;
                    }
                    $r['executeBelowPct'] = max($r['executeBelowPct'], $e['a'] ?? 0);
                    break;
                case 'mark_amp':
                    if ($targetStatus !== null) {
                        $targetStatus['markAmp'][] = [
                            'mult' => $e['a'] ?? 1,
                            'count' => $e['b'] ?? 1,
                            'remainingMs' => $e['c'] ?? 0,
                        ];
                    }
                    break;
                case 'mark_amp_all':
                    $mult = $e['a'] ?? 1;
                    $dur = $e['b'] ?? 0;
                    if ($isAoeCast) {
                        foreach ($enemyStatus as $ei => $en) {
                            $enemyStatus[$ei]['markAmpAll'] = ['mult' => $mult, 'remainingMs' => $dur];
                        }
                    } elseif ($targetStatus !== null) {
                        $targetStatus['markAmpAll'] = ['mult' => $mult, 'remainingMs' => $dur];
                    }
                    break;
                case 'mark_no_heal':
                case 'mark_heal_to_dmg':
                    if ($targetStatus !== null) {
                        $targetStatus['markNoHealMs'] = max($targetStatus['markNoHealMs'], $e['a'] ?? 0);
                    }
                    break;
                case 'heal_self_pct_dmg':
                    $r['healCasterPctOfDmg'] = max($r['healCasterPctOfDmg'], $e['a'] ?? 0);
                    break;
                case 'heal_self_max_pct':
                    $r['healCasterPctOfMaxHp'] = max($r['healCasterPctOfMaxHp'], $e['a'] ?? 0);
                    break;
                case 'immortal':
                    $casterStatus['immortalMs'] = max($casterStatus['immortalMs'], $e['a'] ?? 0);
                    break;
                case 'mana_shield':
                    $casterStatus['manaShieldMs'] = max($casterStatus['manaShieldMs'], $e['a'] ?? 0);
                    break;
                case 'dodge_next':
                    $casterStatus['dodgeNext'][] = ['count' => $e['a'] ?? 0, 'scope' => $e['s'] ?? 'all'];
                    break;
                case 'dodge_buff':
                    $casterStatus['dodgeBuffPct'] = max($casterStatus['dodgeBuffPct'], $e['a'] ?? 0);
                    $casterStatus['dodgeBuffMs'] = max($casterStatus['dodgeBuffMs'], $e['b'] ?? 0);
                    break;
                case 'attack_up':
                    $casterStatus['atkBuffPct'] = max($casterStatus['atkBuffPct'], $e['a'] ?? 0);
                    $casterStatus['atkBuffMs'] = max($casterStatus['atkBuffMs'], $e['b'] ?? 0);
                    break;
                case 'defense_up':
                    $casterStatus['defBuffPct'] = max($casterStatus['defBuffPct'], $e['a'] ?? 0);
                    $casterStatus['defBuffMs'] = max($casterStatus['defBuffMs'], $e['b'] ?? 0);
                    break;
                case 'party_attack_up':
                    foreach ($partyStatus as $pi => $pp) {
                        $partyStatus[$pi]['atkBuffPct'] = max($pp['atkBuffPct'], $e['a'] ?? 0);
                        $partyStatus[$pi]['atkBuffMs'] = max($pp['atkBuffMs'], $e['b'] ?? 0);
                    }
                    break;
                case 'party_defense_up':
                    foreach ($partyStatus as $pi => $pp) {
                        $partyStatus[$pi]['defBuffPct'] = max($pp['defBuffPct'], $e['a'] ?? 0);
                        $partyStatus[$pi]['defBuffMs'] = max($pp['defBuffMs'], $e['b'] ?? 0);
                    }
                    break;
                case 'party_as_up':
                    foreach ($partyStatus as $pi => $pp) {
                        $partyStatus[$pi]['asMult'] = max($pp['asMult'], $e['a'] ?? 1);
                        $partyStatus[$pi]['asMultMs'] = max($pp['asMultMs'], $e['b'] ?? 0);
                    }
                    break;
                case 'party_crit_up':
                    foreach ($partyStatus as $pi => $pp) {
                        $partyStatus[$pi]['partyCritPct'] = max($pp['partyCritPct'], $e['a'] ?? 0);
                        $partyStatus[$pi]['partyCritMs'] = max($pp['partyCritMs'], $e['b'] ?? 0);
                    }
                    break;
                case 'party_def_pen':
                    foreach ($partyStatus as $pi => $pp) {
                        $partyStatus[$pi]['defPenPct'] = max($pp['defPenPct'], $e['a'] ?? 0);
                        $partyStatus[$pi]['defPenMs'] = max($pp['defPenMs'], $e['b'] ?? 0);
                    }
                    break;
                case 'party_immortal':
                    foreach ($partyStatus as $pi => $pp) {
                        $partyStatus[$pi]['immortalMs'] = max($pp['immortalMs'], $e['a'] ?? 0);
                    }
                    $r['partyImmortalMs'] = max($r['partyImmortalMs'], $e['a'] ?? 0);
                    break;
                case 'heal_lowest_ally_pct':
                    $r['healLowestAllyPct'] = max($r['healLowestAllyPct'], $e['a'] ?? 0);
                    break;
                case 'heal_party_dot':
                    $r['healPartyDotMs'] = max($r['healPartyDotMs'], $e['a'] ?? 0);
                    $r['healPartyDotPctPerSec'] = max($r['healPartyDotPctPerSec'], $e['b'] ?? 0);
                    break;
                case 'heal_party_pct':
                    $r['healPartyPctInstant'] = max($r['healPartyPctInstant'], $e['a'] ?? 0);
                    break;
                case 'block_next_party':
                    $r['addBlockNextPartyHits'] += $e['a'] ?? 0;
                    break;
                case 'revive_party':
                    $r['revivePartyProtectMs'] = $e['a'] ?? 0;
                    $r['revivePartyGraceMs'] = $e['b'] ?? 0;
                    $r['reviveDeadAllies'] = true;
                    foreach ($partyStatus as $pi => $pp) {
                        $partyStatus[$pi]['cannotDieMs'] = max($pp['cannotDieMs'], $e['a'] ?? 0);
                    }
                    break;
                case 'next_ally_heal':
                    $pct = $e['a'] ?? 0;
                    $add = $e['b'] ?? 0;
                    $cap = max(1, $add * 2);
                    $found = false;
                    foreach ($casterStatus['nextAllyHeal'] as $di => $d) {
                        if ($d['pct'] == $pct) {
                            $casterStatus['nextAllyHeal'][$di]['count'] = min($cap, $d['count'] + $add);
                            $found = true;
                            break;
                        }
                    }
                    if (! $found) {
                        $casterStatus['nextAllyHeal'][] = ['pct' => $pct, 'count' => min($cap, $add)];
                    }
                    break;
                case 'party_lifesteal_next':
                    $pct = $e['a'] ?? 0;
                    $add = $e['b'] ?? 0;
                    $cap = max(1, $add);
                    foreach ($partyStatus as $pi => $pp) {
                        $found = false;
                        foreach ($pp['lifestealNext'] as $li => $d) {
                            if ($d['pct'] == $pct) {
                                $partyStatus[$pi]['lifestealNext'][$li]['count'] = min($cap, $d['count'] + $add);
                                $found = true;
                                break;
                            }
                        }
                        if (! $found) {
                            $partyStatus[$pi]['lifestealNext'][] = ['pct' => $pct, 'count' => min($cap, $add)];
                        }
                    }
                    break;
                case 'party_instant_kill_chance_next':
                    foreach ($partyStatus as $pi => $pp) {
                        $partyStatus[$pi]['nextAllyInstantKillPct'][] = ['pct' => $e['a'] ?? 0, 'count' => $e['b'] ?? 0];
                    }
                    break;
                case 'aggro_steal':
                    $r['aggroSteal'] = true;
                    break;
                case 'enemy_atk_down':
                    foreach ($enemyStatus as $ei => $en) {
                        $enemyStatus[$ei]['enemyAtkDownPct'] = max($en['enemyAtkDownPct'], $e['a'] ?? 0);
                        $enemyStatus[$ei]['enemyAtkDownMs'] = max($en['enemyAtkDownMs'], $e['b'] ?? 0);
                    }
                    break;
                case 'enemy_no_heal':
                    foreach ($enemyStatus as $ei => $en) {
                        $enemyStatus[$ei]['enemyNoHealMs'] = max($en['enemyNoHealMs'], $e['a'] ?? 0);
                    }
                    break;
                case 'summon':
                    $type = strtolower($e['s'] ?? '');
                    $count = $e['b'] ?? $e['a'] ?? 0;
                    if (($type === 'skeleton' || $type === 'ghost' || $type === 'demon' || $type === 'lich') && $count > 0) {
                        $r['summons'][] = ['type' => $type, 'count' => $count];
                    }
                    break;
                case 'dark_ritual':
                    if ($targetStatus !== null) {
                        $dur = $e['a'] ?? 0;
                        $pct = $e['b'] ?? 0;
                        if ($dur > 0 && $pct > 0) {
                            $targetStatus['darkRitualPending'][] = ['triggerInMs' => $dur, 'pctOfMaxHp' => $pct];
                        }
                    }
                    break;
                case 'death_apocalypse':
                    $r['deathApocalypse'] = true;
                    $r['deathApocalypseSelfHpFloor'] = 0.20;
                    $r['deathApocalypseTargetMaxHpPct'] = 12;
                    break;
                default:
                    break;
            }
        }

        return $r;
    }

    /**
     * Rozwiązuje pojedyncze trafienie podstawowe uwzględniając kolejki ampów
     * atakującego + marki/uniki/immortal celu. Mutuje oba stany w miejscu.
     *
     * @param  array<string, mixed>  $attackerStatus
     * @param  array<string, mixed>  $targetStatus
     * @return array<string, mixed>
     */
    public static function resolveBasicHit(
        RngInterface $rng,
        array &$attackerStatus,
        ?string $attackerClass,
        int|float $attackerBaseDmg,
        array &$targetStatus,
    ): array {
        $out = [
            'damage' => $attackerBaseDmg,
            'dodged' => false,
            'wasCrit' => false,
            'critMult' => 1,
            'casterHeal' => 0,
            'instantKill' => false,
            'executeBurstPct' => 0,
            'healLowestAllyPct' => 0,
        ];

        // Unik — kolejka `dodgeNext` (kolejno konsumowana), potem % dodge buff.
        if (count($targetStatus['dodgeNext']) > 0) {
            $isMagic = in_array($attackerClass ?? '', self::MAGIC_CLASSES, true);
            $dodgesThis = $targetStatus['dodgeNext'][0]['scope'] === 'all' || ! $isMagic;
            if ($dodgesThis && $targetStatus['dodgeNext'][0]['count'] > 0) {
                $targetStatus['dodgeNext'][0]['count'] -= 1;
                if ($targetStatus['dodgeNext'][0]['count'] <= 0) {
                    array_shift($targetStatus['dodgeNext']);
                }
                $out['dodged'] = true;
                $out['damage'] = 0;

                return $out;
            }
        }
        if ($targetStatus['dodgeBuffMs'] > 0 && $targetStatus['dodgeBuffPct'] > 0) {
            if ($rng->nextFloat() * 100 < $targetStatus['dodgeBuffPct']) {
                $out['dodged'] = true;
                $out['damage'] = 0;

                return $out;
            }
        }

        // Krytyk — gwarantowana kolejka `crit_next`, w przeciwnym razie rzut buffem.
        if (count($attackerStatus['critNext']) > 0) {
            if ($attackerStatus['critNext'][0]['count'] > 0) {
                $mult = $attackerStatus['critNext'][0]['mult'];
                $attackerStatus['critNext'][0]['count'] -= 1;
                if ($attackerStatus['critNext'][0]['count'] <= 0) {
                    array_shift($attackerStatus['critNext']);
                }
                $out['wasCrit'] = true;
                $out['critMult'] = max(1, $mult);
                $out['damage'] *= $out['critMult'];
            }
        } elseif ($attackerStatus['critBuffNext'] > 0) {
            if ($rng->nextFloat() * 100 < $attackerStatus['critBuffNext']) {
                $out['wasCrit'] = true;
                $out['critMult'] = 2;
                $out['damage'] *= 2;
            }
            $attackerStatus['critBuffNext'] = 0;
        }

        // Kolejka dmg_amp_next.
        if (count($attackerStatus['dmgAmpNext']) > 0) {
            $out['damage'] *= $attackerStatus['dmgAmpNext'][0]['mult'];
            $attackerStatus['dmgAmpNext'][0]['count'] -= 1;
            if ($attackerStatus['dmgAmpNext'][0]['count'] <= 0) {
                array_shift($attackerStatus['dmgAmpNext']);
            }
        }

        // ATK buff %.
        if ($attackerStatus['atkBuffMs'] > 0) {
            $out['damage'] *= 1 + $attackerStatus['atkBuffPct'] / 100;
        }

        // Mark amp (count-based).
        if (count($targetStatus['markAmp']) > 0) {
            $out['damage'] *= $targetStatus['markAmp'][0]['mult'];
            $targetStatus['markAmp'][0]['count'] -= 1;
            if ($targetStatus['markAmp'][0]['count'] <= 0) {
                array_shift($targetStatus['markAmp']);
            }
        }

        // Mark amp-all (duration-based).
        if ($targetStatus['markAmpAll'] !== null && $targetStatus['markAmpAll']['remainingMs'] > 0) {
            $out['damage'] *= $targetStatus['markAmpAll']['mult'];
        }

        // Kolejka lifesteal.
        if (count($attackerStatus['lifestealNext']) > 0) {
            $out['casterHeal'] = (int) floor($out['damage'] * ($attackerStatus['lifestealNext'][0]['pct'] / 100));
            $attackerStatus['lifestealNext'][0]['count'] -= 1;
            if ($attackerStatus['lifestealNext'][0]['count'] <= 0) {
                array_shift($attackerStatus['lifestealNext']);
            }
        }

        // Kolejka next-ally-heal.
        if (count($attackerStatus['nextAllyHeal']) > 0) {
            $out['healLowestAllyPct'] = max($out['healLowestAllyPct'], $attackerStatus['nextAllyHeal'][0]['pct']);
            $attackerStatus['nextAllyHeal'][0]['count'] -= 1;
            if ($attackerStatus['nextAllyHeal'][0]['count'] <= 0) {
                array_shift($attackerStatus['nextAllyHeal']);
            }
        }

        // Instant-kill chance z party buffa — na sukces skończony execute burst.
        if (count($attackerStatus['nextAllyInstantKillPct']) > 0) {
            if ($rng->nextFloat() * 100 < $attackerStatus['nextAllyInstantKillPct'][0]['pct']) {
                $out['executeBurstPct'] = 12;
            }
            $attackerStatus['nextAllyInstantKillPct'][0]['count'] -= 1;
            if ($attackerStatus['nextAllyInstantKillPct'][0]['count'] <= 0) {
                array_shift($attackerStatus['nextAllyInstantKillPct']);
            }
        }

        $out['damage'] = (int) floor($out['damage']);
        if ($out['damage'] < 0) {
            $out['damage'] = 0;
        }

        return $out;
    }

    /**
     * Aplikuje przychodzące obrażenia z uwzględnieniem immortal / cannotDie.
     *
     * @param  array<string, mixed>  $target
     * @return array{hpDelta:int|float, absorbed:bool}
     */
    public static function applyIncomingDamage(array $target, int|float $targetCurrentHp, int|float $rawDamage): array
    {
        if (($target['immortalMs'] ?? 0) > 0) {
            return ['hpDelta' => 0, 'absorbed' => true];
        }
        $hpDelta = -$rawDamage;
        if (($target['cannotDieMs'] ?? 0) > 0) {
            $newHp = $targetCurrentHp + $hpDelta;
            if ($newHp < 1) {
                $hpDelta = -($targetCurrentHp - 1);

                return ['hpDelta' => $hpDelta, 'absorbed' => false];
            }
        }

        return ['hpDelta' => $hpDelta, 'absorbed' => false];
    }

    /**
     * Mage Tarcza Many — drenuje przychodzące obrażenia najpierw z MP (100%),
     * HP bierze tylko nadmiar.
     *
     * @param  array<string, mixed>|null  $s
     * @return array{mpDmg:int|float, hpDmg:int|float, shieldActive:bool}
     */
    public static function applyManaShieldRedirect(?array $s, int|float $currentMp, int|float $rawDmg): array
    {
        if ($s === null || ($s['manaShieldMs'] ?? 0) <= 0 || $rawDmg <= 0) {
            return ['mpDmg' => 0, 'hpDmg' => $rawDmg, 'shieldActive' => false];
        }
        $mpDmg = min($rawDmg, max(0, $currentMp));
        $hpDmg = $rawDmg - $mpDmg;

        return ['mpDmg' => $mpDmg, 'hpDmg' => $hpDmg, 'shieldActive' => true];
    }

    /**
     * Leczy combatanta. Jeśli jest oznaczony mark_no_heal — leczenie staje się
     * obrażeniami. enemyNoHeal całkowicie blokuje leczenie.
     *
     * @param  array<string, mixed>  $target
     * @return array{hpDelta:int|float}
     */
    public static function applyIncomingHeal(array $target, int|float $rawHeal): array
    {
        if (($target['enemyNoHealMs'] ?? 0) > 0) {
            return ['hpDelta' => 0];
        }
        if (($target['markNoHealMs'] ?? 0) > 0) {
            return ['hpDelta' => -$rawHeal];
        }

        return ['hpDelta' => $rawHeal];
    }

    /**
     * Klasyfikuje string efektu: czy cast „ląduje" na wrogu (dowolny atom
     * z ENEMY_AFFINITY_HEADS).
     */
    public static function skillTargetsEnemy(?string $effect): bool
    {
        if ($effect === null || $effect === '') {
            return false;
        }
        foreach (explode(';', $effect) as $atom) {
            $head = explode(':', strtolower(trim($atom)))[0];
            if (in_array($head, self::ENEMY_AFFINITY_HEADS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Konsumuje jeden ładunek `mark_amp` (Klątwa Śmierci) z celu + pasywny
     * `markAmpAll` (Kraina Śmierci). Mutuje cel w miejscu.
     *
     * @param  array<string, mixed>|null  $target
     * @return array{mult:int|float, consumed:bool}
     */
    public static function consumeTargetMarkAmp(?array &$target): array
    {
        if ($target === null) {
            return ['mult' => 1, 'consumed' => false];
        }

        $mult = 1;
        $consumed = false;

        // Mark count-based (Klątwa Śmierci).
        if (count($target['markAmp']) > 0) {
            $top = $target['markAmp'][0];
            if ($top['count'] <= 0 || ($top['remainingMs'] ?? 0) <= 0) {
                // Nieaktualny wpis — usuń i spróbuj następny.
                array_shift($target['markAmp']);

                return self::consumeTargetMarkAmp($target);
            }
            $mult *= ($top['mult'] ?: 1);
            $consumed = true;
            $target['markAmp'][0]['count'] -= 1;
            if ($target['markAmp'][0]['count'] <= 0) {
                array_shift($target['markAmp']);
            }
        }

        // Mark duration-based (Kraina Śmierci) — pasywny, nigdy nie konsumowany.
        if ($target['markAmpAll'] !== null && $target['markAmpAll']['remainingMs'] > 0) {
            $mult *= ($target['markAmpAll']['mult'] ?: 1);
        }

        if ($mult == 1 && ! $consumed) {
            return ['mult' => 1, 'consumed' => false];
        }

        return ['mult' => $mult, 'consumed' => $consumed];
    }

    /**
     * Konsumuje kolejki „następnego trafienia podstawowego" castera dla jednego
     * uderzenia i zwraca modyfikatory. Mutuje `$s` w miejscu.
     *
     * @param  array<string, mixed>|null  $s
     * @return array<string, mixed>
     */
    public static function consumeCasterBasicHitMods(RngInterface $rng, ?array &$s): array
    {
        if ($s === null) {
            return [
                'extraCritChance' => 0,
                'forceCrit' => false,
                'dmgMult' => 1,
                'lifestealPct' => 0,
                'nextAllyHealPct' => 0,
                'consumed' => [
                    'dmgAmpNext' => false, 'critNext' => false, 'critBuffNext' => false,
                    'lifestealNext' => false, 'nextAllyHeal' => false,
                ],
            ];
        }

        $forceCrit = false;
        $extraCritChance = 0;
        $dmgMult = 1;
        $lifestealPct = 0;
        $nextAllyHealPct = 0;
        $consumed = [
            'dmgAmpNext' => false,
            'critNext' => false,
            'critBuffNext' => false,
            'lifestealNext' => false,
            'nextAllyHeal' => false,
        ];

        // crit_next:count:chance — `chance >= 1` = gwarantowany krytyk.
        if (count($s['critNext']) > 0) {
            if ($s['critNext'][0]['count'] > 0) {
                if ($s['critNext'][0]['mult'] >= 1 || $rng->nextFloat() < $s['critNext'][0]['mult']) {
                    $forceCrit = true;
                }
                $s['critNext'][0]['count'] -= 1;
                if ($s['critNext'][0]['count'] <= 0) {
                    array_shift($s['critNext']);
                }
                $consumed['critNext'] = true;
            }
        }
        // crit_buff_next:N — konsumowany w całości.
        if ($s['critBuffNext'] > 0) {
            $extraCritChance += $s['critBuffNext'] / 100;
            $consumed['critBuffNext'] = true;
            $s['critBuffNext'] = 0;
        }
        // crit_buff:N:durMs — okno czasowe, nie konsumowane (drenowane przez tickStatus).
        if ($s['critBuffMs'] > 0 && $s['critBuffPct'] > 0) {
            $extraCritChance += $s['critBuffPct'] / 100;
        }
        // dmg_amp_next:M:N — następne N ataków ×M.
        if (count($s['dmgAmpNext']) > 0) {
            if ($s['dmgAmpNext'][0]['count'] > 0) {
                $dmgMult *= ($s['dmgAmpNext'][0]['mult'] ?: 1);
                $s['dmgAmpNext'][0]['count'] -= 1;
                if ($s['dmgAmpNext'][0]['count'] <= 0) {
                    array_shift($s['dmgAmpNext']);
                }
                $consumed['dmgAmpNext'] = true;
            }
        }
        // attack_up_pct — okno buffa ATK%.
        if ($s['atkBuffMs'] > 0 && $s['atkBuffPct'] > 0) {
            $dmgMult *= 1 + $s['atkBuffPct'] / 100;
        }
        // party_lifesteal_next — Boski Filar.
        if (count($s['lifestealNext']) > 0) {
            if ($s['lifestealNext'][0]['count'] > 0) {
                $lifestealPct = max($lifestealPct, $s['lifestealNext'][0]['pct']);
                $s['lifestealNext'][0]['count'] -= 1;
                if ($s['lifestealNext'][0]['count'] <= 0) {
                    array_shift($s['lifestealNext']);
                }
                $consumed['lifestealNext'] = true;
            }
        }
        // next_ally_heal — Sąd Boży.
        if (count($s['nextAllyHeal']) > 0) {
            if ($s['nextAllyHeal'][0]['count'] > 0) {
                $nextAllyHealPct = max($nextAllyHealPct, $s['nextAllyHeal'][0]['pct']);
                $s['nextAllyHeal'][0]['count'] -= 1;
                if ($s['nextAllyHeal'][0]['count'] <= 0) {
                    array_shift($s['nextAllyHeal']);
                }
                $consumed['nextAllyHeal'] = true;
            }
        }

        return [
            'extraCritChance' => $extraCritChance,
            'forceCrit' => $forceCrit,
            'dmgMult' => $dmgMult,
            'lifestealPct' => $lifestealPct,
            'nextAllyHealPct' => $nextAllyHealPct,
            'consumed' => $consumed,
        ];
    }

    /**
     * Odpowiednik JS `parseFloat` + `Number.isFinite`: parsuje wiodący literał
     * liczbowy (opcjonalny znak, cyfry, kropka, wykładnik) z początku stringa.
     * Zwraca NAN gdy brak poprawnego prefiksu — wtedy caller traktuje arg jako
     * string (np. `skeleton`, `non_magic`).
     */
    private static function jsParseFloat(string $str): float
    {
        $s = ltrim($str);
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $s, $matches) === 1) {
            return (float) $matches[0];
        }

        return NAN;
    }
}
