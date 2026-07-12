<?php

declare(strict_types=1);

namespace App\Domain\Items;

final class PotionSystem
{
    public const TIER_MIN_LEVEL = [
        'sm' => 1,
        'md' => 20,
        'lg' => 50,
        'mega' => 100,
        'great' => 200,
        'super' => 350,
        'ultimate' => 500,
        'divine' => 700,
    ];

    public const PCT_POTION_MIN_LEVEL = 100;

    public const PCT_HP_POTION_IDS = ['hp_potion_great', 'hp_potion_super', 'hp_potion_ultimate', 'hp_potion_divine'];

    public const PCT_MP_POTION_IDS = ['mp_potion_great', 'mp_potion_super', 'mp_potion_ultimate', 'mp_potion_divine'];

    public const FLAT_HP_POTION_IDS = ['hp_potion_sm', 'hp_potion_md', 'hp_potion_lg'];

    public const FLAT_MP_POTION_IDS = ['mp_potion_sm', 'mp_potion_md', 'mp_potion_lg'];

    public const FLAT_POTION_COOLDOWN_MS = 1000;

    public const PCT_POTION_COOLDOWN_MS = 500;

    public const HP_MP_POTIONS = [
        ['id' => 'hp_potion_sm', 'effect' => 'heal_hp_50'],
        ['id' => 'hp_potion_md', 'effect' => 'heal_hp_150'],
        ['id' => 'hp_potion_lg', 'effect' => 'heal_hp_400'],
        ['id' => 'hp_potion_mega', 'effect' => 'heal_hp_1000'],
        ['id' => 'hp_potion_great', 'effect' => 'heal_hp_pct_20'],
        ['id' => 'hp_potion_super', 'effect' => 'heal_hp_pct_35'],
        ['id' => 'hp_potion_ultimate', 'effect' => 'heal_hp_pct_50'],
        ['id' => 'hp_potion_divine', 'effect' => 'heal_hp_pct_100'],
        ['id' => 'mp_potion_sm', 'effect' => 'heal_mp_30'],
        ['id' => 'mp_potion_md', 'effect' => 'heal_mp_100'],
        ['id' => 'mp_potion_lg', 'effect' => 'heal_mp_300'],
        ['id' => 'mp_potion_mega', 'effect' => 'heal_mp_1000'],
        ['id' => 'mp_potion_great', 'effect' => 'heal_mp_pct_20'],
        ['id' => 'mp_potion_super', 'effect' => 'heal_mp_pct_35'],
        ['id' => 'mp_potion_ultimate', 'effect' => 'heal_mp_pct_50'],
        ['id' => 'mp_potion_divine', 'effect' => 'heal_mp_pct_100'],
    ];

    public const ELIXIR_IDS = [
        'hp_potion_sm', 'hp_potion_md', 'hp_potion_lg', 'hp_potion_mega',
        'hp_potion_great', 'hp_potion_super', 'hp_potion_ultimate', 'hp_potion_divine',
        'mp_potion_sm', 'mp_potion_md', 'mp_potion_lg', 'mp_potion_mega',
        'mp_potion_great', 'mp_potion_super', 'mp_potion_ultimate', 'mp_potion_divine',
        'xp_boost', 'xp_boost_100', 'skill_xp_boost', 'skill_xp_boost_100',
        'attack_speed_elixir', 'cd_reduction_elixir',
        'atk_dmg_elixir_25', 'atk_dmg_elixir_50', 'atk_dmg_elixir_100',
        'spell_dmg_elixir_25', 'spell_dmg_elixir_50', 'spell_dmg_elixir_100',
        'hp_boost_elixir', 'mp_boost_elixir', 'atk_boost_elixir',
        'hp_pct_elixir_25', 'mp_pct_elixir_25',
        'dungeon_reset', 'boss_reset', 'death_protection', 'amulet_of_loss',
        'stat_reset', 'offline_training_boost', 'utamo_vita', 'premium_xp_boost',
    ];

    private const FAMILY_ORDER = ['hp' => 0, 'mp' => 1];

    private const RAW_POTION_CONVERSIONS = [
        ['tier' => 1, 'family' => 'hp', 'inputId' => 'hp_potion_sm', 'inputCount' => 5, 'outputId' => 'hp_potion_md'],
        ['tier' => 2, 'family' => 'hp', 'inputId' => 'hp_potion_md', 'inputCount' => 4, 'outputId' => 'hp_potion_lg'],
        ['tier' => 3, 'family' => 'hp', 'inputId' => 'hp_potion_lg', 'inputCount' => 334, 'outputId' => 'hp_potion_great'],
        ['tier' => 4, 'family' => 'hp', 'inputId' => 'hp_potion_great', 'inputCount' => 2, 'outputId' => 'hp_potion_super'],
        ['tier' => 5, 'family' => 'hp', 'inputId' => 'hp_potion_super', 'inputCount' => 2, 'outputId' => 'hp_potion_ultimate'],
        ['tier' => 6, 'family' => 'hp', 'inputId' => 'hp_potion_ultimate', 'inputCount' => 2, 'outputId' => 'hp_potion_divine'],
        ['tier' => 7, 'family' => 'hp', 'inputId' => 'hp_potion_lg', 'inputCount' => 25, 'outputId' => 'hp_potion_mega'],
        ['tier' => 1, 'family' => 'mp', 'inputId' => 'mp_potion_sm', 'inputCount' => 5, 'outputId' => 'mp_potion_md'],
        ['tier' => 2, 'family' => 'mp', 'inputId' => 'mp_potion_md', 'inputCount' => 4, 'outputId' => 'mp_potion_lg'],
        ['tier' => 3, 'family' => 'mp', 'inputId' => 'mp_potion_lg', 'inputCount' => 334, 'outputId' => 'mp_potion_great'],
        ['tier' => 4, 'family' => 'mp', 'inputId' => 'mp_potion_great', 'inputCount' => 2, 'outputId' => 'mp_potion_super'],
        ['tier' => 5, 'family' => 'mp', 'inputId' => 'mp_potion_super', 'inputCount' => 2, 'outputId' => 'mp_potion_ultimate'],
        ['tier' => 6, 'family' => 'mp', 'inputId' => 'mp_potion_ultimate', 'inputCount' => 2, 'outputId' => 'mp_potion_divine'],
        ['tier' => 7, 'family' => 'mp', 'inputId' => 'mp_potion_lg', 'inputCount' => 25, 'outputId' => 'mp_potion_mega'],
    ];

    public static function isHpMpPotionId(string $id): bool
    {
        return str_starts_with($id, 'hp_potion_') || str_starts_with($id, 'mp_potion_');
    }

    public static function getPotionMinLevel(string $id): int
    {
        if (! self::isHpMpPotionId($id)) {
            return 1;
        }
        $tier = substr($id, strrpos($id, '_') + 1);

        return self::TIER_MIN_LEVEL[$tier] ?? 1;
    }

    public static function canUsePotionAtLevel(string $id, int|float $level): bool
    {
        return $level >= self::getPotionMinLevel($id);
    }

    public static function isPctPotion(string $effect): bool
    {
        return str_contains($effect, '_pct_');
    }

    public static function isPctPotionId(string $potionId): bool
    {
        return in_array($potionId, self::PCT_HP_POTION_IDS, true)
            || in_array($potionId, self::PCT_MP_POTION_IDS, true);
    }

    public static function isFlatPotionId(string $potionId): bool
    {
        return in_array($potionId, self::FLAT_HP_POTION_IDS, true)
            || in_array($potionId, self::FLAT_MP_POTION_IDS, true);
    }

    public static function getPotionCooldownMs(string $potionId): int
    {
        return self::isPctPotionId($potionId) ? self::PCT_POTION_COOLDOWN_MS : self::FLAT_POTION_COOLDOWN_MS;
    }

    public static function getPotionLabel(string $effect): string
    {
        if (preg_match('/^heal_(hp|mp)_(\d+)$/', $effect, $m) === 1) {
            return '+'.$m[2].' '.strtoupper($m[1]);
        }
        if (preg_match('/^heal_(hp|mp)_pct_(\d+)$/', $effect, $m) === 1) {
            return '+'.$m[2].'% '.strtoupper($m[1]);
        }

        return $effect;
    }

    public static function allHpPotions(): array
    {
        return array_values(array_filter(
            self::HP_MP_POTIONS,
            static fn (array $p): bool => str_starts_with($p['effect'], 'heal_hp'),
        ));
    }

    public static function allMpPotions(): array
    {
        return array_values(array_filter(
            self::HP_MP_POTIONS,
            static fn (array $p): bool => str_starts_with($p['effect'], 'heal_mp'),
        ));
    }

    public static function flatHpPotions(): array
    {
        return array_values(array_filter(
            self::allHpPotions(),
            static fn (array $p): bool => ! self::isPctPotion($p['effect']),
        ));
    }

    public static function flatMpPotions(): array
    {
        return array_values(array_filter(
            self::allMpPotions(),
            static fn (array $p): bool => ! self::isPctPotion($p['effect']),
        ));
    }

    public static function pctHpPotions(): array
    {
        return array_values(array_filter(
            self::allHpPotions(),
            static fn (array $p): bool => self::isPctPotion($p['effect']),
        ));
    }

    public static function pctMpPotions(): array
    {
        return array_values(array_filter(
            self::allMpPotions(),
            static fn (array $p): bool => self::isPctPotion($p['effect']),
        ));
    }

    public static function getBestPotion(array $potions, array $consumables, int|float $characterLevel = INF): ?string
    {
        $reversed = array_reverse(array_values($potions));

        foreach ($reversed as $p) {
            if (($consumables[$p['id']] ?? 0) > 0 && self::canUsePotionAtLevel($p['id'], $characterLevel)) {
                return $p['id'];
            }
        }
        foreach ($reversed as $p) {
            if (self::canUsePotionAtLevel($p['id'], $characterLevel)) {
                return $p['id'];
            }
        }

        return null;
    }

    public static function resolveAutoPotionElixir(
        ?string $preferredId,
        string $hpOrMp,
        string $slotKind,
        array $consumables,
        int|float $characterLevel = INF,
    ): ?string {
        if ($preferredId !== null && $preferredId !== '') {
            $exists = in_array($preferredId, self::ELIXIR_IDS, true);
            if ($exists
                && ($consumables[$preferredId] ?? 0) > 0
                && self::canUsePotionAtLevel($preferredId, $characterLevel)) {
                return $preferredId;
            }
        }

        $pool = $hpOrMp === 'hp'
            ? ($slotKind === 'pct' ? self::pctHpPotions() : self::flatHpPotions())
            : ($slotKind === 'pct' ? self::pctMpPotions() : self::flatMpPotions());

        foreach (array_reverse($pool) as $p) {
            if (($consumables[$p['id']] ?? 0) > 0 && self::canUsePotionAtLevel($p['id'], $characterLevel)) {
                return $p['id'];
            }
        }

        return null;
    }

    public static function potionConversions(): array
    {
        $list = array_map(
            static fn (array $c): array => [
                ...$c,
                'outputMinLevel' => self::getPotionMinLevel($c['outputId']),
            ],
            self::RAW_POTION_CONVERSIONS,
        );

        usort($list, static fn (array $a, array $b): int => [
            self::FAMILY_ORDER[$a['family']], $a['outputMinLevel'], $a['tier'],
        ] <=> [
            self::FAMILY_ORDER[$b['family']], $b['outputMinLevel'], $b['tier'],
        ]);

        return array_values($list);
    }

    public static function getMaxConversions(array $conv, int $ownedInput): int
    {
        return (int) floor($ownedInput / $conv['inputCount']);
    }

    public static function checkConversionAvailability(array $conv, int $ownedInput, int|float $characterLevel = INF): array
    {
        $maxBatches = self::getMaxConversions($conv, $ownedInput);
        $requiredLevel = self::getPotionMinLevel($conv['outputId']);
        $levelLocked = $characterLevel < $requiredLevel;

        return [
            'canConvert' => ! $levelLocked && $maxBatches > 0,
            'maxBatches' => $maxBatches,
            'levelLocked' => $levelLocked,
            'requiredLevel' => $requiredLevel,
        ];
    }
}
