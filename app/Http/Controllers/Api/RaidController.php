<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Combat\CombatMath;
use App\Domain\Content\ContentRepository;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Loot\LootSystem;
use App\Domain\Progression\LevelSystem;
use App\Domain\Raid\RaidSystem;
use App\Domain\Support\Rng\RngInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class RaidController extends Controller
{
    private const MAX_ROUNDS = 500;

    private const RARITY_FALLBACK = ['heroic', 'mythic', 'legendary', 'epic', 'rare', 'common'];

    public function resolve(
        Request $request,
        ContentRepository $content,
        RngInterface $rng,
        CharacterStateService $state,
    ): JsonResponse {
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
        ]);
        $requestId = $data['requestId'];

        $cacheKey = "raid.resolve.{$character->id}.{$requestId}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $raidId = (string) $request->route('raidId');
        $raidSystem = new RaidSystem($content->get('dungeons'), $content->get('monsters'));
        $raid = $raidSystem->getRaidById($raidId);
        if ($raid === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego rajdu.');
        }

        if ((int) $character->level < (int) $raid['level']) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za niski poziom postaci na ten rajd.');
        }

        $maxAttempts = (int) ($raid['dailyAttempts'] ?? 5);

        $payload = DB::transaction(function () use ($character, $raid, $raidId, $raidSystem, $maxAttempts, $rng, $state, $content): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            $today = now()->toDateString();
            $entry = $save->state['raid']['attempts'][$raidId] ?? null;
            $usedToday = (is_array($entry) && ($entry['date'] ?? null) === $today)
                ? (int) ($entry['count'] ?? 0)
                : 0;
            if ($usedToday >= $maxAttempts) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Wyczerpany dzienny limit prób na ten rajd.');
            }

            $playerHp = (int) $fresh->hp > 0 ? (int) $fresh->hp : (int) $fresh->max_hp;
            $totalBosses = (int) $raid['waves'] * 4;
            $bossesDefeated = 0;
            $stopped = false;

            for ($waveIdx = 0; $waveIdx < (int) $raid['waves']; $waveIdx++) {
                foreach ($raidSystem->generateWaveBosses($raid, $waveIdx) as $boss) {
                    $fight = $this->fightBoss($rng, $fresh, $boss, $playerHp);
                    $playerHp = $fight['playerHp'];
                    if ($fight['killed']) {
                        $bossesDefeated++;

                        continue;
                    }
                    $stopped = true;
                    break;
                }
                if ($stopped) {
                    break;
                }
            }

            $cleared = $bossesDefeated >= $totalBosses;

            $rewards = ['xp' => 0, 'gold' => 0];
            $dropLines = [];
            $grantedItems = [];
            $grantedStones = [];
            $grantedChests = [];
            $grantedPotions = [];
            $levelsGained = 0;
            $newLevel = (int) $fresh->level;

            if ($bossesDefeated > 0) {
                $rewards = $raidSystem->computeMemberRewards($raid, $bossesDefeated);
                $dropLines = $raidSystem->rollMemberDrops($rng, $raid, $bossesDefeated);

                $generator = new ItemGenerator($content->get('itemTemplates'), $rng);
                foreach ($dropLines as $line) {
                    if (($line['kind'] ?? '') === 'item') {
                        $item = $this->materializeItem(
                            $generator,
                            (string) $fresh->class,
                            (int) $raid['level'],
                            (string) ($line['rarity'] ?? 'common'),
                            (bool) ($line['isBonus'] ?? false),
                        );
                        if ($item !== null) {
                            $grantedItems[] = $item;
                        }
                    } elseif (($line['kind'] ?? '') === 'upgrade_stone') {
                        $grantedStones[] = (string) ($line['itemId'] ?? '');
                    } elseif (($line['kind'] ?? '') === 'spell_chest') {
                        $grantedChests[] = (int) ($line['amount'] ?? 0);
                    }
                }

                for ($i = 0; $i < $bossesDefeated; $i++) {
                    foreach (LootSystem::rollPotionDrop($rng, (int) $raid['level']) as $potion) {
                        $grantedPotions[] = $potion;
                    }
                }

                $lvl = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, (int) $rewards['xp']);
                $fresh->level = $lvl['newLevel'];
                $fresh->xp = $lvl['remainingXp'];
                $fresh->stat_points += $lvl['statPointsGained'];
                $fresh->highest_level = max((int) $fresh->highest_level, $lvl['newLevel']);
                $levelsGained = $lvl['levelsGained'];
                $newLevel = $lvl['newLevel'];
            }

            $fresh->hp = max(0, $playerHp);

            $blob = $save->state;
            $blob['raid']['attempts'][$raidId] = ['date' => $today, 'count' => $usedToday + 1];
            $blob['raid']['lastResult'] = [
                'raidId' => $raidId,
                'cleared' => $cleared,
                'bossesDefeated' => $bossesDefeated,
                'totalBosses' => $totalBosses,
                'playerHp' => max(0, $playerHp),
                'xp' => (int) $rewards['xp'],
                'gold' => (int) $rewards['gold'],
                'drops' => $dropLines,
                'items' => $grantedItems,
                'spellChests' => $grantedChests,
                'potions' => $grantedPotions,
                'resolvedAt' => now()->toIso8601String(),
            ];
            if ($grantedChests !== []) {
                $blob['raid']['pendingSpellChests'] = [
                    ...($blob['raid']['pendingSpellChests'] ?? []),
                    ...$grantedChests,
                ];
            }
            $save->state = $blob;

            if ($bossesDefeated > 0) {
                $state->addGold($save, (int) $rewards['gold']);
                foreach ($grantedItems as $item) {
                    $state->addBagItem($save, $item);
                }
                foreach ($grantedStones as $stoneType) {
                    if ($stoneType !== '') {
                        $state->addStones($save, $stoneType, 1);
                    }
                }
                foreach ($grantedPotions as $potion) {
                    $state->addConsumable($save, (string) $potion['potionId'], (int) $potion['count']);
                }
            }
            $state->persist($save);
            $fresh->save();

            return [
                'result' => [
                    'cleared' => $cleared,
                    'bossesDefeated' => $bossesDefeated,
                    'totalBosses' => $totalBosses,
                    'playerHp' => max(0, $playerHp),
                    'xp' => (int) $rewards['xp'],
                    'gold' => (int) $rewards['gold'],
                    'drops' => $dropLines,
                    'items' => $grantedItems,
                    'spellChests' => $grantedChests,
                    'potions' => $grantedPotions,
                ],
                'raid' => [
                    'id' => $raid['id'],
                    'name_pl' => $raid['name_pl'],
                    'level' => (int) $raid['level'],
                    'waves' => (int) $raid['waves'],
                ],
                'character' => (new CharacterResource($fresh))->resolve(),
                'gold' => $state->gold($save),
                'levelsGained' => $levelsGained,
                'newLevel' => $newLevel,
                'attemptsUsed' => $usedToday + 1,
                'attemptsMax' => $maxAttempts,
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    private function fightBoss(RngInterface $rng, Character $char, array $boss, int $playerHp): array
    {
        $bossHp = (int) $boss['maxHp'];
        $range = CombatMath::getMonsterAttackRange(['attack' => (int) $boss['attack']]);
        $rounds = 0;

        while ($rounds < self::MAX_ROUNDS) {
            $rounds++;

            $isCrit = $rng->nextFloat() < min((float) $char->crit_chance, 0.5);
            $hit = CombatMath::calculateDamage([
                'baseAtk' => $char->attack,
                'weaponAtk' => 0,
                'skillBonus' => 0,
                'classModifier' => 1,
                'enemyDefense' => (int) $boss['defense'],
                'isCrit' => $isCrit,
                'isBlocked' => false,
                'isDodged' => false,
                'critDmg' => (float) $char->crit_damage,
            ]);
            $bossHp -= $hit['finalDamage'];
            if ($bossHp <= 0) {
                return ['killed' => true, 'playerHp' => $playerHp];
            }

            $monsterRoll = $range['min'] + (int) floor($rng->nextFloat() * ($range['max'] - $range['min'] + 1));
            $playerHp -= (int) max(1, $monsterRoll - (int) $char->defense);
            if ($playerHp <= 0) {
                return ['killed' => false, 'playerHp' => 0];
            }
        }

        return ['killed' => false, 'playerHp' => max(0, $playerHp)];
    }

    private function materializeItem(ItemGenerator $generator, string $class, int $level, string $rarity, bool $isBonus): ?array
    {
        if (! $isBonus) {
            return $generator->generateRandomItemForClass($class, $level, $rarity);
        }

        $start = array_search($rarity, self::RARITY_FALLBACK, true);
        $start = $start === false ? 0 : (int) $start;
        for ($i = $start; $i < count(self::RARITY_FALLBACK); $i++) {
            $item = $generator->generateRandomItemForClass($class, $level, self::RARITY_FALLBACK[$i]);
            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }
}
