<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Content\ContentRepository;
use App\Domain\Progression\DailyQuestSystem;
use App\Domain\Progression\LevelSystem;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorytatywne endpointy dziennych questów. Semantyka 1:1 z frontem
 * (dailyQuestStore.refreshIfNeeded / claimReward). Serwer:
 *  - tożsamość bierze z tokenu, postać/stan z BAZY (nie z body),
 *  - wybiera questy dnia DETERMINISTYCZNIE (DailyQuestSystem — klucz dnia +
 *    poziom postaci), więc dwaj klienci tego samego dnia dostaną ten sam zestaw,
 *  - przelicza nagrody skalą poziomu (scaleRewards) — klient NIE podaje kwot,
 *  - zapisuje autorytatywnie: GOLD (+ elixir) → blob game_saves.dailyQuests /
 *    inventory, XP/level/stat_points → characters, licznik quests_daily_done.
 *
 * Slice w blobie: state.dailyQuests = { lastRefreshDate, activeQuests[
 *   {questId, progress, completed, claimed} ], todayQuestDefs[] }.
 */
final class DailyQuestController extends Controller
{
    /**
     * Odśwież questy dnia, jeśli to nowy dzień. Klucz dnia z body `date`
     * (YYYY-MM-DD) albo z now() serwera. Idempotencja NATURALNA: gdy
     * lastRefreshDate == dzisiaj → no-op (nie regeneruje, nie kasuje progresu).
     */
    public function refresh(Request $request, ContentRepository $content, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'date' => ['sometimes', 'string', 'date_format:Y-m-d'],
        ]);

        $now = now();
        $todayKey = $data['date'] ?? DailyQuestSystem::todayKey(
            (int) $now->year,
            (int) $now->month,
            (int) $now->day,
        );

        $payload = DB::transaction(function () use ($character, $content, $state, $todayKey): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $slice = $blob['dailyQuests'] ?? [
                'lastRefreshDate' => null,
                'activeQuests' => [],
                'todayQuestDefs' => [],
            ];

            $refreshed = false;
            if (DailyQuestSystem::needsRefresh($slice['lastRefreshDate'] ?? null, $todayKey)) {
                $defs = DailyQuestSystem::selectDailyQuests(
                    $content->get('dailyQuests'),
                    (int) $fresh->level,
                    $todayKey,
                );

                $slice = [
                    'lastRefreshDate' => $todayKey,
                    'todayQuestDefs' => array_values($defs),
                    'activeQuests' => array_map(static fn (array $q): array => [
                        'questId' => $q['id'],
                        'progress' => 0,
                        'completed' => false,
                        'claimed' => false,
                    ], $defs),
                ];

                $blob['dailyQuests'] = $slice;
                $save->state = $blob;
                $state->persist($save);
                $refreshed = true;
            }

            return [
                'refreshed' => $refreshed,
                'lastRefreshDate' => $slice['lastRefreshDate'] ?? null,
                'activeQuests' => $slice['activeQuests'] ?? [],
                'todayQuestDefs' => $slice['todayQuestDefs'] ?? [],
            ];
        });

        return response()->json($payload);
    }

    /**
     * Odbierz nagrodę za UKOŃCZONY dzienny quest. Serwer waliduje ukończenie i
     * przelicza nagrodę (scaleRewards, skala poziomem). Gold + ewentualny elixir
     * → blob; XP → postać (processXpGain → level-upy). Bump quests_daily_done.
     * Idempotencja: flaga `claimed` w slice (drugi claim → 422, brak podwójnej
     * nagrody). {questId} czytamy przez route() — Laravel gubi wiązanie przy 2
     * parametrach trasy.
     */
    public function claim(Request $request, ContentRepository $content, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $questId = (string) $request->route('questId');

        $payload = DB::transaction(function () use ($character, $questId, $content, $state): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $slice = $blob['dailyQuests'] ?? null;
            $activeQuests = $slice['activeQuests'] ?? [];

            $idx = null;
            foreach ($activeQuests as $i => $aq) {
                if (($aq['questId'] ?? null) === $questId) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                abort(Response::HTTP_NOT_FOUND, 'Quest dzienny nie istnieje (odśwież questy).');
            }

            $aq = $activeQuests[$idx];
            if (! ($aq['completed'] ?? false)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Quest dzienny jeszcze nieukończony.');
            }
            if ($aq['claimed'] ?? false) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nagroda za ten quest już odebrana.');
            }

            // Definicja questa — z zapisanych todayQuestDefs, fallback do żywej treści.
            $def = collect($slice['todayQuestDefs'] ?? [])->firstWhere('id', $questId)
                ?? collect($content->get('dailyQuests'))->firstWhere('id', $questId);
            if ($def === null || ! isset($def['rewards'])) {
                abort(Response::HTTP_NOT_FOUND, 'Brak definicji questa dziennego.');
            }

            // Serwer PRZELICZA nagrody skalą poziomu — klient nie podaje kwot.
            $rewards = DailyQuestSystem::scaleRewards($def['rewards'], (int) $fresh->level);

            // XP → postać (może wywołać wiele level-upów naraz).
            $lvl = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, (int) $rewards['xp']);
            $fresh->level = $lvl['newLevel'];
            $fresh->xp = $lvl['remainingXp'];
            $fresh->stat_points += $lvl['statPointsGained'];
            $fresh->highest_level = max((int) $fresh->highest_level, $lvl['newLevel']);

            // Licznik rankingowy — jak front bumpStat('quests_daily_done').
            $fresh->quests_daily_done = (int) $fresh->quests_daily_done + 1;

            // Quest → claimed w slice (naturalna idempotencja).
            $activeQuests[$idx]['claimed'] = true;
            $blob['dailyQuests']['activeQuests'] = array_values($activeQuests);
            $save->state = $blob;

            // Gold + ewentualny elixir → blob PO ostatnim $save->state = $blob
            // (bug kolejności: inaczej mutacja inventory zostałaby nadpisana).
            $state->addGold($save, (int) $rewards['gold']);
            if (isset($rewards['elixir']) && $rewards['elixir'] !== '') {
                $state->addConsumable($save, (string) $rewards['elixir'], 1);
            }

            $state->persist($save);
            $fresh->save();

            return [
                'rewards' => $rewards,
                'levelsGained' => $lvl['levelsGained'],
                'newLevel' => $lvl['newLevel'],
                'gold' => $state->gold($save),
                'questsDailyDone' => (int) $fresh->quests_daily_done,
            ];
        });

        return response()->json($payload);
    }
}
