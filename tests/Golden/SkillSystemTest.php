<?php

declare(strict_types=1);

use App\Domain\Skills\SkillSystem;
use App\Domain\Support\Rng\Mulberry32Rng;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('skillSystem.json');
});

it('matches exported constants and data tables', function () {
    $c = $this->golden['constants'];
    expect(SkillSystem::MLVL_FROM_ATTACKS_CLASSES)->toEqual($c['MLVL_FROM_ATTACKS_CLASSES']);
    expect(SkillSystem::MAX_OFFLINE_TRAINING_SECONDS)->toEqual($c['MAX_OFFLINE_TRAINING_SECONDS']);
    expect(SkillSystem::OFFLINE_TRAINING_SPEED_MULTIPLIER)->toEqual($c['OFFLINE_TRAINING_SPEED_MULTIPLIER']);
    expect(SkillSystem::CLASS_WEAPON_SKILLS)->toEqual($c['CLASS_WEAPON_SKILLS']);
    expect(SkillSystem::CLASS_WEAPON_SKILL)->toEqual($c['CLASS_WEAPON_SKILL']);
    expect(SkillSystem::ALL_WEAPON_SKILL_IDS)->toEqual($c['ALL_WEAPON_SKILL_IDS']);
    expect(SkillSystem::GENERAL_TRAINABLE_STATS)->toEqual($c['GENERAL_TRAINABLE_STATS']);
    expect(SkillSystem::ALL_TRAINABLE_STATS)->toEqual($c['ALL_TRAINABLE_STATS']);
    expect(SkillSystem::SPELL_CHEST_LEVELS)->toEqual($c['SPELL_CHEST_LEVELS']);
});

it('matches skillXpToNextLevel (ceil(100*lvl^1.8), guard <=0)', function () {
    foreach ($this->golden['skillXpToNextLevel'] as $case) {
        expect(SkillSystem::skillXpToNextLevel($case['level']))
            ->toEqual($case['value'], "skillXpToNextLevel({$case['level']})");
    }
});

it('matches skillXpPerHit and skillXpPerCast', function () {
    foreach ($this->golden['skillXpPerHit'] as $case) {
        expect(SkillSystem::skillXpPerHit($case['level']))->toEqual($case['value'], "skillXpPerHit({$case['level']})");
    }
    foreach ($this->golden['skillXpPerCast'] as $case) {
        expect(SkillSystem::skillXpPerCast($case['level']))->toEqual($case['value'], "skillXpPerCast({$case['level']})");
    }
});

it('matches mlvlXpPerAttack', function () {
    foreach ($this->golden['mlvlXpPerAttack'] as $case) {
        expect(SkillSystem::mlvlXpPerAttack($case['mlvl']))->toEqual($case['value'], "mlvlXpPerAttack({$case['mlvl']})");
    }
});

it('matches mlvlXpPerSkillUse (magic full rate, others 1/3)', function () {
    foreach ($this->golden['mlvlXpPerSkillUse'] as $case) {
        expect(SkillSystem::mlvlXpPerSkillUse($case['mlvl'], $case['class']))
            ->toEqual($case['value'], "mlvlXpPerSkillUse({$case['mlvl']},{$case['class']})");
    }
});

it('matches doesClassGainMlvlFromAttacks', function () {
    foreach ($this->golden['doesClassGainMlvlFromAttacks'] as $case) {
        expect(SkillSystem::doesClassGainMlvlFromAttacks($case['class']))
            ->toEqual($case['value'], "doesClassGainMlvlFromAttacks({$case['class']})");
    }
});

it('matches shielding helpers (xpPerBlock, defBonus, blockBonus)', function () {
    foreach ($this->golden['shieldingXpPerBlock'] as $case) {
        expect(SkillSystem::shieldingXpPerBlock($case['level']))->toEqual($case['value'], "shieldingXpPerBlock({$case['level']})");
    }
    foreach ($this->golden['getShieldingDefBonus'] as $case) {
        expect(SkillSystem::getShieldingDefBonus($case['level']))->toEqual($case['value'], "getShieldingDefBonus({$case['level']})");
    }
    foreach ($this->golden['getShieldingBlockBonus'] as $case) {
        expect(SkillSystem::getShieldingBlockBonus($case['level']))->toEqual($case['value'], "getShieldingBlockBonus({$case['level']})");
    }
});

it('matches offlineXpRate and offlineXpRateForStat', function () {
    foreach ($this->golden['offlineXpRate'] as $case) {
        expect(SkillSystem::offlineXpRate($case['level']))->toEqual($case['value'], "offlineXpRate({$case['level']})");
    }
    foreach ($this->golden['offlineXpRateForStat'] as $case) {
        expect(SkillSystem::offlineXpRateForStat($case['level'], $case['skillId']))
            ->toEqual($case['value'], "offlineXpRateForStat({$case['level']},{$case['skillId']})");
    }
});

it('matches calculateOfflineSkillXp (legacy + simulated level-ups + 24h cap)', function () {
    foreach ($this->golden['calculateOfflineSkillXp'] as $case) {
        expect(SkillSystem::calculateOfflineSkillXp($case['elapsedSeconds'], $case['skillLevel'], $case['skillId']))
            ->toEqual($case['value'], "calculateOfflineSkillXp({$case['elapsedSeconds']},{$case['skillLevel']})");
    }
});

it('matches processSkillXp (incl. multi-levelup)', function () {
    foreach ($this->golden['processSkillXp'] as $case) {
        expect(SkillSystem::processSkillXp($case['level'], $case['xp'], $case['gained']))
            ->toEqual($case['result'], "processSkillXp({$case['level']},{$case['xp']},{$case['gained']})");
    }
});

it('matches applySkillDeathPenalty', function () {
    foreach ($this->golden['applySkillDeathPenalty'] as $case) {
        expect(SkillSystem::applySkillDeathPenalty($case['xp'], $case['level']))
            ->toEqual($case['value'], "applySkillDeathPenalty({$case['xp']},{$case['level']})");
    }
});

it('matches getSkillDamageBonus', function () {
    foreach ($this->golden['getSkillDamageBonus'] as $case) {
        expect(SkillSystem::getSkillDamageBonus($case['level'], $case['damageBonus']))
            ->toEqual($case['value'], "getSkillDamageBonus({$case['level']},{$case['damageBonus']})");
    }
});

it('matches getClassWeaponSkills and getTrainableStatsForClass', function () {
    foreach ($this->golden['getClassWeaponSkills'] as $case) {
        expect(SkillSystem::getClassWeaponSkills($case['class']))->toEqual($case['value'], "getClassWeaponSkills({$case['class']})");
    }
    foreach ($this->golden['getTrainableStatsForClass'] as $case) {
        expect(SkillSystem::getTrainableStatsForClass($case['class']))
            ->toEqual($case['value'], "getTrainableStatsForClass({$case['class']})");
    }
});

it('matches getTrainingBonuses (per-class regen + fallback)', function () {
    foreach ($this->golden['getTrainingBonuses'] as $case) {
        expect(SkillSystem::getTrainingBonuses($case['levels'], $case['class']))
            ->toEqual($case['value'], 'getTrainingBonuses '.json_encode($case['class']));
    }
});

it('matches skillXpProgress', function () {
    foreach ($this->golden['skillXpProgress'] as $case) {
        expect(SkillSystem::skillXpProgress($case['xp'], $case['level']))
            ->toEqual($case['value'], "skillXpProgress({$case['xp']},{$case['level']})");
    }
});

it('matches getSkillUnlockCost', function () {
    foreach ($this->golden['getSkillUnlockCost'] as $case) {
        expect(SkillSystem::getSkillUnlockCost($case['level']))->toEqual($case['value'], "getSkillUnlockCost({$case['level']})");
    }
});

it('matches getSkillUpgradeCost (table + beyond+10 formula)', function () {
    foreach ($this->golden['getSkillUpgradeCost'] as $case) {
        expect(SkillSystem::getSkillUpgradeCost($case['targetLevel']))
            ->toEqual($case['result'], "getSkillUpgradeCost({$case['targetLevel']})");
    }
});

it('matches getSkillUpgradeBonus and getCombatSkillUpgradeMultiplier', function () {
    foreach ($this->golden['getSkillUpgradeBonus'] as $case) {
        expect(SkillSystem::getSkillUpgradeBonus($case['level']))->toEqual($case['value'], "getSkillUpgradeBonus({$case['level']})");
    }
    foreach ($this->golden['getCombatSkillUpgradeMultiplier'] as $case) {
        expect(SkillSystem::getCombatSkillUpgradeMultiplier($case['level']))
            ->toEqual($case['value'], "getCombatSkillUpgradeMultiplier({$case['level']})");
    }
});

it('matches getSpellChestUnlockCost', function () {
    foreach ($this->golden['getSpellChestUnlockCost'] as $case) {
        expect(SkillSystem::getSpellChestUnlockCost($case['level']))
            ->toEqual($case['result'], "getSpellChestUnlockCost({$case['level']})");
    }
});

it('matches getSpellChestUpgradeCost (table + beyond+10 formula)', function () {
    foreach ($this->golden['getSpellChestUpgradeCost'] as $case) {
        expect(SkillSystem::getSpellChestUpgradeCost($case['targetLevel'], $case['unlockLevel']))
            ->toEqual($case['result'], "getSpellChestUpgradeCost({$case['targetLevel']},{$case['unlockLevel']})");
    }
});

it('matches rollSkillUpgrade (seeded, 1× nextFloat)', function () {
    foreach ($this->golden['rollSkillUpgrade'] as $case) {
        $rng = new Mulberry32Rng($case['seed']);
        expect(SkillSystem::rollSkillUpgrade($rng, $case['targetLevel']))
            ->toEqual($case['value'], "rollSkillUpgrade seed {$case['seed']} target {$case['targetLevel']}");
    }
});
