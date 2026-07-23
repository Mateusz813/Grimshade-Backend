<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Combat\CombatElixirs;
use App\Domain\Content\ContentRepository;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Progression\LevelSystem;
use App\Domain\Support\Rng\RngInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class QuestController extends Controller
{
    private const ELIXIR_ALIASES = [
        'hp_sm' => 'hp_potion_sm', 'hp_md' => 'hp_potion_md', 'hp_lg' => 'hp_potion_lg', 'hp_great' => 'hp_potion_great',
        'mp_sm' => 'mp_potion_sm', 'mp_md' => 'mp_potion_md', 'mp_lg' => 'mp_potion_lg', 'mp_great' => 'mp_potion_great',
        'xp_elixir' => 'xp_boost', 'skill_xp_elixir' => 'skill_xp_boost', 'cooldown_elixir' => 'cd_reduction_elixir',
    ];

    private const GIFT_RARITIES = ['rare', 'epic', 'legendary', 'mythic'];

    private const GIFT_WEIGHTS = [0.55, 0.30, 0.12, 0.03];

    public function claim(
        Request $request,
        ContentRepository $content,
        CharacterStateService $state,
        RngInterface $rng,
    ): JsonResponse {
        $character = $request->attributes->get('character');
        $questId = (string) $request->route('questId');

        $quest = collect($content->get('quests'))->firstWhere('id', $questId);
        if ($quest === null) {
            abort(Response::HTTP_NOT_FOUND, 'Quest nie istnieje.');
        }

        $payload = DB::transaction(function () use ($character, $questId, $quest, $content, $state, $rng): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $blob = $save->state;
            $activeQuests = $blob['quests']['activeQuests'] ?? [];
            $completedQuestIds = $blob['quests']['completedQuestIds'] ?? [];

            $idx = null;
            foreach ($activeQuests as $i => $aq) {
                if (($aq['questId'] ?? null) === $questId) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                abort(Response::HTTP_NOT_FOUND, 'Quest nieaktywny (lub już odebrany).');
            }

            foreach (($activeQuests[$idx]['goals'] ?? []) as $goal) {
                if ((int) ($goal['progress'] ?? 0) < (int) ($goal['count'] ?? 0)) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Quest jeszcze nieukończony.');
                }
            }

            $minLevel = (int) ($quest['minLevel'] ?? 1);

            $goldTotal = 0;
            $xpTotal = 0;
            $statPointsTotal = 0;
            $elixirGrants = [];
            $stoneGrants = [];
            $itemSpecs = [];
            $hasExplicitItem = false;

            foreach (($quest['rewards'] ?? []) as $reward) {
                $type = (string) ($reward['type'] ?? '');
                $amount = (int) ($reward['amount'] ?? 1);

                switch ($type) {
                    case 'gold':
                        $goldTotal += $amount;
                        break;
                    case 'xp':
                        $xpTotal += $amount;
                        break;
                    case 'stat_points':
                        $statPointsTotal += $amount;
                        break;
                    case 'elixir':
                        if (! empty($reward['elixirId'])) {
                            $rawId = (string) $reward['elixirId'];
                            $elixirGrants[] = ['id' => self::ELIXIR_ALIASES[$rawId] ?? $rawId, 'count' => $amount];
                        }
                        break;
                    case 'stones':
                    case 'stone':
                        $stoneKey = $reward['stoneId'] ?? $reward['stoneType'] ?? null;
                        if ($stoneKey !== null) {
                            $stoneGrants[] = ['type' => (string) $stoneKey, 'count' => $amount];
                        }
                        break;
                    case 'item':
                        $hasExplicitItem = true;
                        $rarity = (string) ($reward['rarity'] ?? 'rare');
                        for ($i = 0; $i < $amount; $i++) {
                            $itemSpecs[] = ['level' => $minLevel, 'rarity' => $rarity];
                        }
                        break;
                }
            }

            $nowMs = (int) round(microtime(true) * 1000);
            $xpMult = CombatElixirs::getXpBoostMultiplier(
                CombatElixirs::activeBuffEffects($blob, (string) $fresh->id, $nowMs),
            );
            $xpTotal = (int) floor($xpTotal * $xpMult);

            $xpResult = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, $xpTotal);
            $fresh->level = $xpResult['newLevel'];
            $fresh->xp = $xpResult['remainingXp'];
            $fresh->stat_points = (int) $fresh->stat_points + $xpResult['statPointsGained'] + $statPointsTotal;
            $fresh->highest_level = max((int) $fresh->highest_level, $xpResult['newLevel']);
            $fresh->quests_oneshot_done = (int) $fresh->quests_oneshot_done + 1;

            $generator = new ItemGenerator($content->get('itemTemplates'), $rng);
            $items = [];
            foreach ($itemSpecs as $spec) {
                $item = $generator->generateRandomItemForClass((string) $fresh->class, $spec['level'], $spec['rarity']);
                if ($item !== null) {
                    $items[] = $item;
                }
            }

            $giftItem = null;
            if (! $hasExplicitItem) {
                $roll = $rng->nextFloat();
                $cumulative = 0.0;
                $picked = self::GIFT_RARITIES[0];
                foreach (self::GIFT_RARITIES as $k => $rarity) {
                    $cumulative += self::GIFT_WEIGHTS[$k];
                    if ($roll < $cumulative) {
                        $picked = $rarity;
                        break;
                    }
                }
                $giftItem = $generator->generateRandomItemForClass((string) $fresh->class, max(1, $minLevel), $picked);
            }

            array_splice($activeQuests, $idx, 1);
            $blob['quests']['activeQuests'] = array_values($activeQuests);
            if (! in_array($questId, $completedQuestIds, true)) {
                $completedQuestIds[] = $questId;
            }
            $blob['quests']['completedQuestIds'] = array_values($completedQuestIds);

            $save->state = $blob;

            if ($goldTotal > 0) {
                $state->addGold($save, $goldTotal);
            }
            foreach ($elixirGrants as $elixir) {
                $state->addConsumable($save, $elixir['id'], $elixir['count']);
            }
            foreach ($stoneGrants as $stone) {
                $state->addStones($save, $stone['type'], $stone['count']);
            }
            foreach ($items as $item) {
                $state->addBagItem($save, $item);
            }
            if ($giftItem !== null) {
                $state->addBagItem($save, $giftItem);
            }

            $state->persist($save);
            $fresh->save();

            return [
                'rewards' => [
                    'gold' => $goldTotal,
                    'xp' => [
                        'gained' => $xpTotal,
                        'levelsGained' => $xpResult['levelsGained'],
                        'newLevel' => $xpResult['newLevel'],
                    ],
                    'statPoints' => $statPointsTotal,
                    'elixirs' => $elixirGrants,
                    'stones' => $stoneGrants,
                    'items' => array_map(static fn (array $i): string => (string) $i['uuid'], $items),
                    'giftItem' => $giftItem !== null ? (string) $giftItem['uuid'] : null,
                ],
                'gold' => $state->gold($save),
                'newLevel' => $xpResult['newLevel'],
                'statPoints' => (int) $fresh->stat_points,
                'questsOneshotDone' => (int) $fresh->quests_oneshot_done,
                'inventory' => $save->state['inventory'],
                'character' => (new CharacterResource($fresh))->resolve(),
                'state' => $save->state,
                'updated_at' => optional($save->updated_at)->toIso8601String(),
            ];
        });

        return response()->json($payload);
    }
}
