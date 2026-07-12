<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Content\ContentRepository;
use App\Domain\Skills\SkillSystem;
use App\Domain\Support\Rng\RngInterface;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\CharacterStateService;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class SkillController extends Controller
{
    public function upgrade(
        Request $request,
        ContentRepository $content,
        CharacterStateService $state,
        RngInterface $rng,
    ): JsonResponse {
        $character = $request->attributes->get('character');
        $skillId = (string) $request->route('skillId');
        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $unlockLevel = $this->skillUnlockLevel($content, $skillId);
        if ($unlockLevel === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego skilla.');
        }

        $cacheKey = "skills.upgrade.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($character, $skillId, $unlockLevel, $state, $rng): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);
            $blob = $save->state;

            $skills = $blob['skills'] ?? [];
            $currentLevel = (int) ($skills['skillUpgradeLevels'][$skillId] ?? 0);
            $targetLevel = $currentLevel + 1;

            $cost = SkillSystem::getSpellChestUpgradeCost($targetLevel, $unlockLevel);
            $chestKey = 'spell_chest_'.$cost['chestLevel'];

            $haveGold = (int) ($blob['inventory']['gold'] ?? 0);
            $haveChests = (int) ($blob['inventory']['consumables'][$chestKey] ?? 0);
            if ($haveGold < (int) $cost['gold'] || ((int) $cost['chests'] > 0 && $haveChests < (int) $cost['chests'])) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za mało środków na ulepszenie skilla.');
            }

            $success = SkillSystem::rollSkillUpgrade($rng, $targetLevel);

            if ($success) {
                $skills['skillUpgradeLevels'][$skillId] = $targetLevel;
            }
            $blob['skills'] = $skills;
            $save->state = $blob;

            if ((int) $cost['chests'] > 0) {
                $state->useConsumable($save, $chestKey, (int) $cost['chests']);
            }
            $state->spendGold($save, (int) $cost['gold']);

            if ($success) {
                $fresh->skill_upgrades_done = (int) $fresh->skill_upgrades_done + 1;
                $fresh->save();
            }

            $state->persist($save);

            return [
                'success' => $success,
                'skillId' => $skillId,
                'newLevel' => $success ? $targetLevel : $currentLevel,
                'goldSpent' => (int) $cost['gold'],
                'chestsSpent' => (int) $cost['chests'],
                'cost' => $cost,
                'gold' => $state->gold($save),
                'consumables' => $save->state['inventory']['consumables'] ?? [],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    public function unlock(
        Request $request,
        ContentRepository $content,
        CharacterStateService $state,
    ): JsonResponse {
        $character = $request->attributes->get('character');
        $skillId = (string) $request->route('skillId');
        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $unlockLevel = $this->skillUnlockLevel($content, $skillId);
        if ($unlockLevel === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego skilla.');
        }

        if ((int) $character->level < $unlockLevel) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za niski poziom, by odblokować ten skill.');
        }

        $cacheKey = "skills.unlock.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($character, $skillId, $unlockLevel, $state): array {
            $save = $state->lockedFor($character);
            $blob = $save->state;
            $skills = $blob['skills'] ?? [];

            if (($skills['unlockedSkills'][$skillId] ?? false) === true) {
                return [
                    'skillId' => $skillId,
                    'gold' => $state->gold($save),
                    'skills' => $save->state['skills'] ?? [],
                ];
            }

            $cost = SkillSystem::getSpellChestUnlockCost($unlockLevel);
            $chestKey = 'spell_chest_'.$cost['chestLevel'];

            $haveGold = (int) ($blob['inventory']['gold'] ?? 0);
            $haveChests = (int) ($blob['inventory']['consumables'][$chestKey] ?? 0);
            if ($haveGold < (int) $cost['gold'] || $haveChests < (int) $cost['chests']) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za mało środków, by odblokować skill.');
            }

            $skills['unlockedSkills'][$skillId] = true;
            $blob['skills'] = $skills;
            $save->state = $blob;

            $state->useConsumable($save, $chestKey, (int) $cost['chests']);
            $state->spendGold($save, (int) $cost['gold']);

            $state->persist($save);

            return [
                'skillId' => $skillId,
                'gold' => $state->gold($save),
                'skills' => $save->state['skills'] ?? [],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    public function slot(Request $request, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'slot' => ['required', 'integer', 'min:0', 'max:3'],
            'skillId' => ['present', 'nullable', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);
        $slot = (int) $data['slot'];
        $skillId = $data['skillId'] !== null ? (string) $data['skillId'] : null;

        $cacheKey = "skills.slot.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($character, $slot, $skillId, $state): array {
            $save = $state->lockedFor($character);
            $blob = $save->state;
            $skills = $blob['skills'] ?? [];

            if ($skillId !== null && (($skills['unlockedSkills'][$skillId] ?? false) !== true)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Skill nie jest odblokowany.');
            }

            $existing = array_values($skills['activeSkillSlots'] ?? []);
            $slots = [];
            for ($i = 0; $i < 4; $i++) {
                $slots[$i] = $existing[$i] ?? null;
            }

            if ($skillId !== null) {
                for ($i = 0; $i < 4; $i++) {
                    if ($slots[$i] === $skillId && $i !== $slot) {
                        $slots[$i] = null;
                    }
                }
            }
            $slots[$slot] = $skillId;

            $skills['activeSkillSlots'] = $slots;
            $blob['skills'] = $skills;
            $save->state = $blob;
            $state->persist($save);

            return [
                'slot' => $slot,
                'skillId' => $skillId,
                'skills' => $save->state['skills'] ?? [],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    public function trainStart(Request $request, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'skillId' => ['required', 'string', 'max:64'],
        ]);
        $skillId = $data['skillId'];

        if (! in_array($skillId, SkillSystem::getTrainableStatsForClass((string) $character->class), true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Ten skill nie jest trenowalny dla tej klasy.');
        }

        $payload = DB::transaction(function () use ($character, $skillId, $state): array {
            $save = $state->lockedFor($character);
            $blob = $save->state;
            $skills = $blob['skills'] ?? [];

            $collected = $this->collectTraining($skills, new DateTimeImmutable);

            $skills['offlineTrainingSkillId'] = $skillId;
            $skills['trainingStartedAt'] = (new DateTimeImmutable)->format(DateTimeInterface::ATOM);

            $blob['skills'] = $skills;
            $save->state = $blob;
            $state->persist($save);

            return [
                'offlineTrainingSkillId' => $skillId,
                'trainingStartedAt' => $skills['trainingStartedAt'],
                'collected' => $collected,
            ];
        });

        return response()->json($payload);
    }

    public function trainCollect(Request $request, CharacterStateService $state): JsonResponse
    {
        $character = $request->attributes->get('character');

        $payload = DB::transaction(function () use ($character, $state): array {
            $save = $state->lockedFor($character);
            $blob = $save->state;
            $skills = $blob['skills'] ?? [];

            if (($skills['offlineTrainingSkillId'] ?? null) === null || ($skills['trainingStartedAt'] ?? null) === null) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Brak aktywnego treningu.');
            }

            $result = $this->collectTraining($skills, new DateTimeImmutable);

            $blob['skills'] = $skills;
            $save->state = $blob;
            $state->persist($save);

            return $result;
        });

        return response()->json($payload);
    }

    private function collectTraining(array &$skills, DateTimeInterface $now): array
    {
        $skillId = $skills['offlineTrainingSkillId'] ?? null;
        $startedAt = $skills['trainingStartedAt'] ?? null;

        if ($skillId === null || $startedAt === null) {
            return ['skillId' => null, 'xpEarned' => 0, 'newLevel' => 0, 'remainingXp' => 0, 'levelsGained' => 0];
        }

        $startTs = strtotime((string) $startedAt) ?: $now->getTimestamp();
        $elapsed = max(0, $now->getTimestamp() - $startTs);

        $currentLevel = (int) ($skills['skillLevels'][$skillId] ?? 0);
        $currentXp = (int) ($skills['skillXp'][$skillId] ?? 0);

        $xpEarned = SkillSystem::calculateOfflineSkillXp($elapsed, $currentLevel, (string) $skillId);
        $processed = SkillSystem::processSkillXp($currentLevel, $currentXp, $xpEarned);

        $skills['skillLevels'][$skillId] = $processed['newLevel'];
        $skills['skillXp'][$skillId] = $processed['remainingXp'];
        $skills['trainingStartedAt'] = $now->format(DateTimeInterface::ATOM);

        return [
            'skillId' => (string) $skillId,
            'xpEarned' => $xpEarned,
            'newLevel' => $processed['newLevel'],
            'remainingXp' => $processed['remainingXp'],
            'levelsGained' => $processed['levelsGained'],
        ];
    }

    private function skillUnlockLevel(ContentRepository $content, string $skillId): ?int
    {
        $activeSkills = $content->get('skills')['activeSkills'] ?? [];
        foreach ($activeSkills as $list) {
            foreach ($list as $skill) {
                if (($skill['id'] ?? null) === $skillId) {
                    return (int) ($skill['unlockLevel'] ?? 0);
                }
            }
        }

        return null;
    }
}
