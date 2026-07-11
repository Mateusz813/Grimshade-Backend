<?php

declare(strict_types=1);

use App\Domain\Guild\GuildSystem;
use Tests\Support\Golden;

/**
 * PARYTET guildSystem: PHP GuildSystem musi zwrócić DOKŁADNIE to, co TS
 * guildSystem.ts (fixture wygenerowany w grimshade repo, skopiowany tu).
 * System czysty (zero RNG) — bit-parity. toEqual (nie toBe) — JSON nie
 * rozróżnia int/float, więc porównanie luźne.
 */
beforeEach(function () {
    $this->golden = Golden::load('guildSystem.json');
});

it('matches guild constants', function () {
    $c = $this->golden['constants'];
    expect(GuildSystem::GUILD_INITIAL_MEMBER_CAP)->toEqual($c['initialMemberCap']);
    expect(GuildSystem::GUILD_CREATE_COST_GOLD)->toEqual($c['createCostGold']);
    expect(GuildSystem::GUILD_BOSS_MAX_TIER)->toEqual($c['bossMaxTier']);
    expect(GuildSystem::GUILD_TREASURY_SLOTS)->toEqual($c['treasurySlots']);
    expect(GuildSystem::GUILD_BOSS_HEROIC_MAX_CHANCE)->toEqual($c['bossHeroicMaxChance']);
    expect(GuildSystem::GUILD_BOSS_BLOCK_PCT)->toEqual($c['bossBlockPct']);
});

it('has no upper guild level cap (GUILD_MAX_LEVEL is infinite)', function () {
    expect(is_infinite(GuildSystem::GUILD_MAX_LEVEL))->toEqual($this->golden['maxLevelIsInfinite']);
});

it('matches clampGuildBossTier', function () {
    foreach ($this->golden['clampGuildBossTier'] as $case) {
        expect(GuildSystem::clampGuildBossTier($case['tier']))
            ->toEqual($case['value'], "clampGuildBossTier({$case['tier']})");
    }
});

it('matches getGuildBossMaxHp', function () {
    foreach ($this->golden['getGuildBossMaxHp'] as $case) {
        expect(GuildSystem::getGuildBossMaxHp($case['tier']))
            ->toEqual($case['value'], "getGuildBossMaxHp({$case['tier']})");
    }
});

it('matches guildXpToNextLevel', function () {
    foreach ($this->golden['guildXpToNextLevel'] as $case) {
        expect(GuildSystem::guildXpToNextLevel($case['level']))
            ->toEqual($case['value'], "guildXpToNextLevel({$case['level']})");
    }
});

it('matches guildXpForLevel', function () {
    foreach ($this->golden['guildXpForLevel'] as $case) {
        expect(GuildSystem::guildXpForLevel($case['level']))
            ->toEqual($case['value'], "guildXpForLevel({$case['level']})");
    }
});

it('matches guildMemberCap', function () {
    foreach ($this->golden['guildMemberCap'] as $case) {
        expect(GuildSystem::guildMemberCap($case['level']))
            ->toEqual($case['value'], "guildMemberCap({$case['level']})");
    }
});

it('matches applyGuildXp (incl. multi-levelup + level-0 bump)', function () {
    foreach ($this->golden['applyGuildXp'] as $case) {
        expect(GuildSystem::applyGuildXp($case['level'], $case['xp'], $case['gain']))
            ->toEqual($case['result'], "applyGuildXp({$case['level']},{$case['xp']},{$case['gain']})");
    }
});

it('matches computeGuildBossDamage (incl. 5% HP cap branch)', function () {
    foreach ($this->golden['computeGuildBossDamage'] as $case) {
        expect(GuildSystem::computeGuildBossDamage($case['attack'], $case['level'], $case['tier']))
            ->toEqual($case['value'], "computeGuildBossDamage({$case['attack']},{$case['level']},{$case['tier']})");
    }
});

it('matches contributionMultiplier', function () {
    foreach ($this->golden['contributionMultiplier'] as $case) {
        expect(GuildSystem::contributionMultiplier($case['damage'], $case['bossMaxHp']))
            ->toEqual($case['value'], "contributionMultiplier({$case['damage']},{$case['bossMaxHp']})");
    }
});

it('matches getCurrentWeekStartIso (Monday-start, UTC)', function () {
    foreach ($this->golden['getCurrentWeekStartIso'] as $case) {
        expect(GuildSystem::getCurrentWeekStartIso($case['ms']))
            ->toEqual($case['value'], "getCurrentWeekStartIso({$case['ms']})");
    }
});

it('matches isGuildBossClaimDay (Sunday)', function () {
    foreach ($this->golden['isGuildBossClaimDay'] as $case) {
        expect(GuildSystem::isGuildBossClaimDay($case['ms']))
            ->toEqual($case['value'], "isGuildBossClaimDay({$case['ms']})");
    }
});

it('matches getTodayIso (UTC date)', function () {
    foreach ($this->golden['getTodayIso'] as $case) {
        expect(GuildSystem::getTodayIso($case['ms']))
            ->toEqual($case['value'], "getTodayIso({$case['ms']})");
    }
});
