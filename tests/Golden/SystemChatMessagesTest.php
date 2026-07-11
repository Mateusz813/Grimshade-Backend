<?php

declare(strict_types=1);

use App\Domain\Chat\SystemChatMessages;
use Tests\Support\Golden;

/**
 * PARYTET systemChatMessages: PHP SystemChatMessages musi zwrócić DOKŁADNIE to,
 * co TS systemChatMessages.ts (fixture wygenerowany w grimshade repo, skopiowany
 * tu). Protokół czatu systemowego — format/parse `[SYS]{...}` bit-w-bit, łącznie
 * z polskimi znakami, slashami, cudzysłowami i escapami.
 *
 * toEqual (nie toBe) — JSON nie rozróżnia int/float, więc porównanie luźne.
 */
beforeEach(function () {
    $this->golden = Golden::load('systemChatMessages.json');
});

it('matches isUpgradeMilestone for every level', function () {
    foreach ($this->golden['isUpgradeMilestone'] as $case) {
        expect(SystemChatMessages::isUpgradeMilestone($case['level']))
            ->toEqual($case['value'], "isUpgradeMilestone({$case['level']})");
    }
});

it('matches formatSystemMessage (wire string, bit-parity)', function () {
    foreach ($this->golden['formatSystemMessage'] as $case) {
        expect(SystemChatMessages::formatSystemMessage($case['payload']))
            ->toBe($case['value'], 'formatSystemMessage '.json_encode($case['payload']));
    }
});

it('matches parseSystemMessage (happy path + normalizacja + wszystkie ścieżki null)', function () {
    foreach ($this->golden['parseSystemMessage'] as $case) {
        expect(SystemChatMessages::parseSystemMessage($case['content']))
            ->toEqual($case['value'], 'parseSystemMessage '.json_encode($case['content']));
    }
});
