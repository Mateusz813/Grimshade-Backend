<?php

declare(strict_types=1);

use App\Domain\Content\ContentRepository;
use App\Domain\Transform\TransformBonuses;
use App\Domain\Transform\TransformSystem;
use Tests\Support\Golden;

beforeEach(function () {
    $this->golden = Golden::load('transformSystem.json');
    $content = new ContentRepository(dirname(__DIR__, 2).'/resources/game-content');
    $this->system = new TransformSystem($content->get('transforms'), $content->get('monsters'));
    $this->bonuses = new TransformBonuses($this->system);
});

it('matches constants', function () {
    $c = $this->golden['constants'];
    expect(count($this->system->getAllTransforms()))->toEqual($c['TRANSFORM_COUNT']);
    expect(TransformSystem::TRANSFORM_SLOT_TIERS)->toEqual($c['TRANSFORM_SLOT_TIERS']);
    expect(TransformSystem::TRANSFORM_BOSS_MULTIPLIER)->toEqual($c['TRANSFORM_BOSS_MULTIPLIER']);
    expect(TransformSystem::TRANSFORM_TIER_MULTIPLIERS)->toEqual($c['TRANSFORM_TIER_MULTIPLIERS']);
});

it('matches getTransformTierMultiplier', function () {
    foreach ($this->golden['getTransformTierMultiplier'] as $case) {
        expect(TransformSystem::getTransformTierMultiplier($case['id']))
            ->toEqual($case['value'], "getTransformTierMultiplier id {$case['id']}");
    }
});

it('matches getClassTransformBonuses (base + tier-scaled)', function () {
    foreach ($this->golden['getClassTransformBonuses'] as $case) {
        expect(TransformSystem::getClassTransformBonuses($case['cls'], $case['id']))
            ->toEqual($case['value'], "getClassTransformBonuses {$case['cls']} id ".json_encode($case['id']));
    }
});

it('matches applyTransformBossStats', function () {
    foreach ($this->golden['applyTransformBossStats'] as $case) {
        expect(TransformSystem::applyTransformBossStats($case['monster']))
            ->toEqual($case['value'], "applyTransformBossStats {$case['monster']['id']}");
    }
});

it('matches applyTransformTierStats (all tiers)', function () {
    foreach ($this->golden['applyTransformTierStats'] as $case) {
        expect(TransformSystem::applyTransformTierStats($case['monster'], $case['tier']))
            ->toEqual($case['value'], "applyTransformTierStats {$case['monster']['id']} {$case['tier']}");
    }
});

it('matches resolveActiveOpponentSlot', function () {
    foreach ($this->golden['resolveActiveOpponentSlot'] as $i => $case) {
        expect(TransformSystem::resolveActiveOpponentSlot($case['escorts']))
            ->toEqual($case['value'], "resolveActiveOpponentSlot case {$i}");
    }
});

it('matches getHighestCompletedTransform', function () {
    foreach ($this->golden['getHighestCompletedTransform'] as $case) {
        expect(TransformSystem::getHighestCompletedTransform($case['ids']))
            ->toEqual($case['value'], 'getHighestCompletedTransform '.json_encode($case['ids']));
    }
});

it('matches getTransformById (content)', function () {
    foreach ($this->golden['getTransformById'] as $case) {
        expect($this->system->getTransformById($case['id']))
            ->toEqual($case['value'], "getTransformById id {$case['id']}");
    }
});

it('matches getTransformMonsterCount', function () {
    foreach ($this->golden['getTransformMonsterCount'] as $case) {
        expect($this->system->getTransformMonsterCount($case['id']))
            ->toEqual($case['value'], "getTransformMonsterCount id {$case['id']}");
    }
});

it('matches generateTransformBossMonster (scaleMonsterStats + template + gold)', function () {
    foreach ($this->golden['generateTransformBossMonster'] as $case) {
        expect($this->system->generateTransformBossMonster($case['level']))
            ->toEqual($case['value'], "generateTransformBossMonster lvl {$case['level']}");
    }
});

it('matches getTransformBonuses (per class + no class)', function () {
    foreach ($this->golden['getTransformBonuses'] as $case) {
        expect($this->system->getTransformBonuses($case['id'], $case['cls']))
            ->toEqual($case['value'], "getTransformBonuses {$case['cls']} id {$case['id']}");
    }
    foreach ($this->golden['getTransformBonusesNoClass'] as $case) {
        expect($this->system->getTransformBonuses($case['id']))
            ->toEqual($case['value'], "getTransformBonuses noClass id {$case['id']}");
    }
});

it('matches getCumulativeTransformBonuses (per class + no class)', function () {
    foreach ($this->golden['getCumulativeTransformBonuses'] as $case) {
        expect($this->system->getCumulativeTransformBonuses($case['ids'], $case['cls']))
            ->toEqual($case['value'], "getCumulativeTransformBonuses {$case['cls']} ".json_encode($case['ids']));
    }
    foreach ($this->golden['getCumulativeTransformBonusesNoClass'] as $case) {
        expect($this->system->getCumulativeTransformBonuses($case['ids']))
            ->toEqual($case['value'], 'getCumulativeTransformBonuses noClass '.json_encode($case['ids']));
    }
});

it('matches isLevelSufficient', function () {
    foreach ($this->golden['isLevelSufficient'] as $case) {
        expect($this->system->isLevelSufficient($case['level'], $case['id']))
            ->toEqual($case['value'], "isLevelSufficient lvl {$case['level']} id {$case['id']}");
    }
});

it('matches getNextAvailableTransform', function () {
    foreach ($this->golden['getNextAvailableTransform'] as $case) {
        expect($this->system->getNextAvailableTransform($case['ids'], $case['level']))
            ->toEqual($case['value'], 'getNextAvailableTransform '.json_encode($case['ids'])." lvl {$case['level']}");
    }
});

it('matches getActiveAvatar', function () {
    foreach ($this->golden['getActiveAvatar'] as $case) {
        expect($this->system->getActiveAvatar($case['cls'], $case['ids']))
            ->toEqual($case['value'], "getActiveAvatar {$case['cls']} ".json_encode($case['ids']));
    }
});

it('matches calculateTransformRewardsDeterministic (consumables + permanentBonuses)', function () {
    foreach ($this->golden['calculateTransformRewardsDeterministic'] as $case) {
        expect($this->system->calculateTransformRewardsDeterministic($case['id'], $case['cls']))
            ->toEqual($case['value'], "calculateTransformRewardsDeterministic id {$case['id']} {$case['cls']}");
    }
});

it('matches getTransformDmgMultiplier (stateful → explicit state)', function () {
    foreach ($this->golden['getTransformDmgMultiplier'] as $case) {
        expect($this->bonuses->getTransformDmgMultiplier($case['ids'], $case['cls']))
            ->toEqual($case['value'], 'getTransformDmgMultiplier '.json_encode($case['cls']).' '.json_encode($case['ids']));
    }
});

it('matches transform flat bonuses (6 gettery)', function () {
    foreach ($this->golden['transformFlatBonuses'] as $case) {
        $ids = $case['ids'];
        $cls = $case['cls'];
        $actual = [
            'flatHp' => $this->bonuses->getTransformFlatHp($ids, $cls),
            'flatMp' => $this->bonuses->getTransformFlatMp($ids, $cls),
            'flatAttack' => $this->bonuses->getTransformFlatAttack($ids, $cls),
            'flatDefense' => $this->bonuses->getTransformFlatDefense($ids, $cls),
            'hpRegenFlat' => $this->bonuses->getTransformHpRegenFlat($ids, $cls),
            'mpRegenFlat' => $this->bonuses->getTransformMpRegenFlat($ids, $cls),
        ];
        expect($actual)->toEqual($case['value'], 'transformFlatBonuses '.json_encode($cls).' '.json_encode($ids));
    }
});

it('matches transform pct multipliers (4 gettery)', function () {
    foreach ($this->golden['transformPctMultipliers'] as $case) {
        $ids = $case['ids'];
        $cls = $case['cls'];
        $actual = [
            'hp' => $this->bonuses->getTransformHpPctMultiplier($ids, $cls),
            'mp' => $this->bonuses->getTransformMpPctMultiplier($ids, $cls),
            'def' => $this->bonuses->getTransformDefPctMultiplier($ids, $cls),
            'atk' => $this->bonuses->getTransformAtkPctMultiplier($ids, $cls),
        ];
        expect($actual)->toEqual($case['value'], 'transformPctMultipliers '.json_encode($cls).' '.json_encode($ids));
    }
});

it('matches getLiveTransformBreakdown', function () {
    foreach ($this->golden['getLiveTransformBreakdown'] as $case) {
        expect($this->bonuses->getLiveTransformBreakdown($case['ids'], $case['cls']))
            ->toEqual($case['value'], 'getLiveTransformBreakdown '.json_encode($case['cls']).' '.json_encode($case['ids']));
    }
});

it('matches getDisplayTransformBreakdown (baked flag passthrough)', function () {
    foreach ($this->golden['getDisplayTransformBreakdown'] as $case) {
        expect($this->bonuses->getDisplayTransformBreakdown($case['ids'], $case['cls'], $case['baked']))
            ->toEqual($case['value'], 'getDisplayTransformBreakdown '.json_encode($case['cls']).' baked '.json_encode($case['baked']));
    }
});
