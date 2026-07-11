<?php

declare(strict_types=1);

use App\Domain\Items\PotionSystem;
use Tests\Support\Golden;

/**
 * PARYTET potionSystem: PHP PotionSystem musi zwrócić DOKŁADNIE to, co TS
 * (potionSystem.ts + potionConversion.ts + potionGating.ts; fixture wygenerowany
 * w grimshade repo, skopiowany tu). System czysty → golden bit-parity.
 * toEqual (nie toBe) — JSON nie rozróżnia int/float.
 */
beforeEach(function () {
    $this->golden = Golden::load('potionSystem.json');
});

/** Nazwa puli z fixture -> pula PHP (ta sama derywacja co TS). */
function potionPool(string $name): array
{
    return match ($name) {
        'allHp' => PotionSystem::allHpPotions(),
        'allMp' => PotionSystem::allMpPotions(),
        'flatHp' => PotionSystem::flatHpPotions(),
        'flatMp' => PotionSystem::flatMpPotions(),
        'pctHp' => PotionSystem::pctHpPotions(),
        'pctMp' => PotionSystem::pctMpPotions(),
        'empty' => [],
    };
}

it('matches PCT_POTION_MIN_LEVEL', function () {
    expect(PotionSystem::PCT_POTION_MIN_LEVEL)->toEqual($this->golden['pctPotionMinLevel']);
});

it('matches getPotionMinLevel for every id', function () {
    foreach ($this->golden['getPotionMinLevel'] as $case) {
        expect(PotionSystem::getPotionMinLevel($case['id']))
            ->toEqual($case['value'], "getPotionMinLevel({$case['id']})");
    }
});

it('matches canUsePotionAtLevel', function () {
    foreach ($this->golden['canUsePotionAtLevel'] as $case) {
        expect(PotionSystem::canUsePotionAtLevel($case['id'], $case['level']))
            ->toEqual($case['value'], "canUsePotionAtLevel({$case['id']},{$case['level']})");
    }
});

it('matches isHpMpPotionId', function () {
    foreach ($this->golden['isHpMpPotionId'] as $case) {
        expect(PotionSystem::isHpMpPotionId($case['id']))
            ->toEqual($case['value'], "isHpMpPotionId({$case['id']})");
    }
});

it('matches isPctPotion for every effect', function () {
    foreach ($this->golden['isPctPotion'] as $case) {
        expect(PotionSystem::isPctPotion($case['effect']))
            ->toEqual($case['value'], "isPctPotion({$case['effect']})");
    }
});

it('matches isPctPotionId', function () {
    foreach ($this->golden['isPctPotionId'] as $case) {
        expect(PotionSystem::isPctPotionId($case['id']))
            ->toEqual($case['value'], "isPctPotionId({$case['id']})");
    }
});

it('matches isFlatPotionId', function () {
    foreach ($this->golden['isFlatPotionId'] as $case) {
        expect(PotionSystem::isFlatPotionId($case['id']))
            ->toEqual($case['value'], "isFlatPotionId({$case['id']})");
    }
});

it('matches getPotionCooldownMs', function () {
    foreach ($this->golden['getPotionCooldownMs'] as $case) {
        expect(PotionSystem::getPotionCooldownMs($case['id']))
            ->toEqual($case['value'], "getPotionCooldownMs({$case['id']})");
    }
});

it('matches getPotionLabel (healing values parsed from effect protocol)', function () {
    foreach ($this->golden['getPotionLabel'] as $case) {
        expect(PotionSystem::getPotionLabel($case['effect']))
            ->toEqual($case['value'], "getPotionLabel({$case['effect']})");
    }
});

it('matches derived potion pools (order is logic)', function () {
    $pools = [
        'allHp' => PotionSystem::allHpPotions(),
        'allMp' => PotionSystem::allMpPotions(),
        'flatHp' => PotionSystem::flatHpPotions(),
        'flatMp' => PotionSystem::flatMpPotions(),
        'pctHp' => PotionSystem::pctHpPotions(),
        'pctMp' => PotionSystem::pctMpPotions(),
        'empty' => [],
    ];
    foreach ($this->golden['pools'] as $name => $ids) {
        $actualIds = array_map(static fn (array $p): string => $p['id'], $pools[$name]);
        expect($actualIds)->toEqual($ids, "pool {$name}");
    }
});

it('matches getBestPotion (owned + level gate, fallback)', function () {
    foreach ($this->golden['getBestPotion'] as $case) {
        $pool = potionPool($case['pool']);
        $result = $case['level'] === null
            ? PotionSystem::getBestPotion($pool, $case['consumables'])
            : PotionSystem::getBestPotion($pool, $case['consumables'], $case['level']);
        expect($result)->toEqual($case['resultId'], "getBestPotion({$case['pool']})");
    }
});

it('matches resolveAutoPotionElixir (preferred, fallback, level gate)', function () {
    foreach ($this->golden['resolveAutoPotionElixir'] as $case) {
        $result = $case['level'] === null
            ? PotionSystem::resolveAutoPotionElixir(
                $case['preferredId'], $case['hpOrMp'], $case['slotKind'], $case['consumables'],
            )
            : PotionSystem::resolveAutoPotionElixir(
                $case['preferredId'], $case['hpOrMp'], $case['slotKind'], $case['consumables'], $case['level'],
            );
        expect($result)->toEqual(
            $case['resultId'],
            'resolveAutoPotionElixir '.json_encode($case['preferredId']),
        );
    }
});

it('matches POTION_CONVERSIONS (derived outputMinLevel + sort)', function () {
    $actual = array_map(
        static fn (array $c): array => [
            'tier' => $c['tier'],
            'family' => $c['family'],
            'inputId' => $c['inputId'],
            'inputCount' => $c['inputCount'],
            'outputId' => $c['outputId'],
            'outputMinLevel' => $c['outputMinLevel'],
        ],
        PotionSystem::potionConversions(),
    );
    expect($actual)->toEqual($this->golden['potionConversions']);
});

it('matches getMaxConversions', function () {
    foreach ($this->golden['getMaxConversions'] as $case) {
        $conv = ['inputCount' => $case['inputCount'], 'outputId' => $case['outputId']];
        expect(PotionSystem::getMaxConversions($conv, $case['ownedInput']))
            ->toEqual($case['value'], "getMaxConversions({$case['outputId']},{$case['ownedInput']})");
    }
});

it('matches checkConversionAvailability (level gate)', function () {
    foreach ($this->golden['checkConversionAvailability'] as $case) {
        $conv = ['inputCount' => $case['inputCount'], 'outputId' => $case['outputId']];
        $result = $case['level'] === null
            ? PotionSystem::checkConversionAvailability($conv, $case['ownedInput'])
            : PotionSystem::checkConversionAvailability($conv, $case['ownedInput'], $case['level']);
        expect($result)->toEqual($case['result'], "checkConversionAvailability({$case['outputId']},{$case['ownedInput']})");
    }
});
