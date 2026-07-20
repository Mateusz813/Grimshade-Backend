<?php

declare(strict_types=1);

namespace App\Domain\Skills;

final class SkillBuffs
{
    public const CHARGE_ATOMS = [
        'dodge_next', 'dmg_amp_next', 'crit_next', 'crit_buff_next',
        'block_next_party', 'next_ally_heal', 'party_lifesteal_next',
    ];

    private const PARTY_TIMED_ATOMS = [
        'party_attack_up', 'party_defense_up', 'party_def_pen',
        'party_as_up', 'party_crit_up', 'party_immortal', 'heal_party_dot',
    ];

    private ?array $skillIndex = null;

    public function __construct(private readonly array $skillsContent) {}

    public function getSkillDef(string $skillId): ?array
    {
        if ($this->skillIndex === null) {
            $this->skillIndex = self::buildIndex($this->skillsContent);
        }

        return $this->skillIndex[$skillId] ?? null;
    }

    private static function buildIndex(array $skillsContent): array
    {
        $out = [];
        $active = $skillsContent['activeSkills'] ?? [];
        foreach ($active as $classSkills) {
            foreach ($classSkills as $skill) {
                $out[$skill['id']] = $skill;
            }
        }

        return $out;
    }

    public static function chargeBuffEffectKey(string $atomHead): string
    {
        return "skill_charge_{$atomHead}";
    }

    public static function isChargeAtom(string $head): bool
    {
        return in_array($head, self::CHARGE_ATOMS, true);
    }

    public static function chargeStackCap(int $chargesToAdd): int
    {
        return max(1, $chargesToAdd * 2);
    }

    public static function applySkillBuff(string $skillId, ?string $effect): array
    {
        $ops = [];
        if ($effect === null || $effect === '') {
            return $ops;
        }

        $atoms = explode(';', $effect);
        foreach ($atoms as $i => $rawAtom) {
            $atom = trim($rawAtom);
            $head = self::atomHead($atom);

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

            $lparts = explode(':', strtolower($atom));
            $n1 = self::jsParseFloat($lparts[1] ?? '0');
            $n2 = self::jsParseFloat($lparts[2] ?? '0');
            $duration = self::timedDurationMs($head, $n1, $n2);
            if ($duration === null || $duration <= 0) {
                continue;
            }

            $effectKey = "skill_{$skillId}_{$i}";
            $ops[] = ['op' => 'removeBuffByEffect', 'effect' => $effectKey];

            $healPctPerSec = null;
            if ($head === 'heal_party_dot') {
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

    private static function atomHead(string $atom): string
    {
        return explode(':', strtolower($atom), 2)[0];
    }

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

    private static function jsParseInt(string $s): ?int
    {
        $s = ltrim($s);
        if (preg_match('/^[+-]?\d+/', $s, $m) === 1) {
            return (int) $m[0];
        }

        return null;
    }

    private static function jsParseIntOr(string $s, int $default): int
    {
        $n = self::jsParseInt($s);
        if ($n === null || $n === 0) {
            return $default;
        }

        return $n;
    }

    private static function jsParseFloat(string $s): float
    {
        $s = ltrim($s);
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $s, $m) === 1) {
            return (float) $m[0];
        }

        return NAN;
    }
}
