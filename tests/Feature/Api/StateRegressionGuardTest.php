<?php

declare(strict_types=1);

use App\Domain\Character\EffectiveStats;
use App\Services\StateValidationException;
use App\Models\Character;
use App\Models\GameSave;
use App\Services\CharacterStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function regChar(int $level): Character
{
    return Character::factory()->forUser('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee')->create([
        'class' => 'Archer',
        'level' => $level,
        'highest_level' => $level,
        'xp' => 1000,
    ]);
}

function regState(int $level, int $highest, int $xp = 1000): array
{
    return [
        '_characterStats' => [
            'level' => $level,
            'highest_level' => $highest,
            'xp' => $xp,
            'hp' => 100, 'max_hp' => 100, 'mp' => 50, 'max_mp' => 50,
            'attack' => 45, 'defense' => 20, 'stat_points' => 0, 'magic_level' => 0,
        ],
        'inventory' => ['gold' => 0, 'bag' => [], 'equipment' => [], 'deposit' => []],
    ];
}

function commitState(Character $c, array $prev, array $next): array
{
    $svc = app(CharacterStateService::class);
    $save = GameSave::query()->create(['character_id' => $c->id, 'user_id' => $c->user_id, 'state' => $prev]);

    return $svc->commit($c, $save, $next, app(EffectiveStats::class), false);
}

it('rejects the exact incident: a stale client trying to roll 362 back to 346', function () {
    $c = regChar(362);

    expect(fn () => commitState($c, regState(362, 362), regState(346, 346)))
        ->toThrow(StateValidationException::class);
});

it('rejects any level rollback larger than one death is worth', function () {
    $c = regChar(1000);

    expect(fn () => commitState($c, regState(1000, 1000), regState(900, 1000)))
        ->toThrow(StateValidationException::class);
});

it('still accepts a legitimate death penalty drop', function () {
    $c = regChar(362);
    $sanitized = commitState($c, regState(362, 362), regState(359, 362));

    expect($sanitized['_characterStats']['level'])->toBe(359)
        ->and($sanitized['_characterStats']['highest_level'])->toBe(362);
});

it('never lets highest_level go backwards, repairing it in place', function () {
    $c = regChar(362);
    $sanitized = commitState($c, regState(362, 362), regState(362, 100));

    expect($sanitized['_characterStats']['highest_level'])->toBe(362);
});

it('raises highest_level to the submitted level when the character levels up', function () {
    $c = regChar(50);
    $sanitized = commitState($c, regState(50, 50), regState(62, 50));

    expect($sanitized['_characterStats']['level'])->toBe(62)
        ->and($sanitized['_characterStats']['highest_level'])->toBe(62);
});

it('accepts normal forward progress untouched', function () {
    $c = regChar(346);
    $sanitized = commitState($c, regState(346, 346, 1000), regState(358, 358, 5000));

    expect($sanitized['_characterStats']['level'])->toBe(358)
        ->and($sanitized['_characterStats']['highest_level'])->toBe(358)
        ->and($sanitized['_characterStats']['xp'])->toBe(5000);
});
