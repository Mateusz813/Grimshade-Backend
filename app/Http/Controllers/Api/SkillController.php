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

/**
 * Autorytatywne endpointy skilli. SERWER liczy koszt/wynik — klient podaje tylko
 * skillId (+ requestId dla ulepszenia). Semantyka 1:1 z frontem (skillStore.ts):
 *
 *  - upgrade: koszt (spell-chesty + gold) z SkillSystem::getSpellChestUpgradeCost
 *    schodzi ZAWSZE (sukces czy porażka), sukces = rollSkillUpgrade → upgradeLevel+1
 *    + licznik rankingowy skill_upgrades_done++. Brak środków = 422 (nic nie schodzi).
 *  - train/start: wybiera skill treningu offline i stempluje czas startu SERWERA
 *    (poprzedni trening jest najpierw zebrany — jak selectTrainingStat).
 *  - train/collect: XP = calculateOfflineSkillXp(elapsed, level, skillId), gdzie
 *    elapsed to czas SERWERA od trainingStartedAt (capowany 24h w SkillSystem).
 *
 * Blob skills: skillLevels / skillXp / skillUpgradeLevels żyją w state.skills.
 * Spell-chesty to consumables `spell_chest_<level>` (mutowane przez serwis).
 */
final class SkillController extends Controller
{
    public function upgrade(
        Request $request,
        ContentRepository $content,
        CharacterStateService $state,
        RngInterface $rng,
    ): JsonResponse {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $skillId = (string) $request->route('skillId');
        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        // Poziom odblokowania skilla (chestLevel) — z żywej treści, nie z body.
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

            // Odmowa gry (brak środków) → 422, ZERO mutacji.
            $haveGold = (int) ($blob['inventory']['gold'] ?? 0);
            $haveChests = (int) ($blob['inventory']['consumables'][$chestKey] ?? 0);
            if ($haveGold < (int) $cost['gold'] || ((int) $cost['chests'] > 0 && $haveChests < (int) $cost['chests'])) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za mało środków na ulepszenie skilla.');
            }

            // Roll sukcesu serwerowym RNG (kolejność jak TS: nextFloat()*100 < successRate).
            $success = SkillSystem::rollSkillUpgrade($rng, $targetLevel);

            // Mutacja slice skills PRZED serwisem (inaczej $save->state=$blob nadpisze koszt).
            if ($success) {
                $skills['skillUpgradeLevels'][$skillId] = $targetLevel;
            }
            $blob['skills'] = $skills;
            $save->state = $blob;

            // Koszt schodzi ZAWSZE (sukces czy porażka) — jak na froncie.
            if ((int) $cost['chests'] > 0) {
                $state->useConsumable($save, $chestKey, (int) $cost['chests']);
            }
            $state->spendGold($save, (int) $cost['gold']);

            // Licznik rankingowy — jak front bumpStat('skill_upgrades_done').
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

    /**
     * Odblokowanie active skilla. SERWER liczy koszt (getSpellChestUnlockCost:
     * 1 spell-chest poziomu unlockLevel + gold = floor(getSkillUnlockCost/5)) i
     * konsumuje go, po czym stawia flagę skills.unlockedSkills[skillId]=true.
     *
     * Parytet: Inventory.tsx confirmUnlock + skillStore unlockSkill. Bramka
     * poziomu (character.level >= def.unlockLevel) i brak środków → 422.
     */
    public function unlock(
        Request $request,
        ContentRepository $content,
        CharacterStateService $state,
    ): JsonResponse {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $skillId = (string) $request->route('skillId');
        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $unlockLevel = $this->skillUnlockLevel($content, $skillId);
        if ($unlockLevel === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego skilla.');
        }

        // Bramka poziomu — jak na froncie (character.level >= def.unlockLevel).
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

            // Już odblokowany → brak kosztu (jak unlockSkill early-return), zwróć stan.
            if (($skills['unlockedSkills'][$skillId] ?? false) === true) {
                return [
                    'skillId' => $skillId,
                    'gold' => $state->gold($save),
                    'skills' => $save->state['skills'] ?? [],
                ];
            }

            $cost = SkillSystem::getSpellChestUnlockCost($unlockLevel);
            $chestKey = 'spell_chest_'.$cost['chestLevel'];

            // Odmowa (brak środków) → 422, ZERO mutacji.
            $haveGold = (int) ($blob['inventory']['gold'] ?? 0);
            $haveChests = (int) ($blob['inventory']['consumables'][$chestKey] ?? 0);
            if ($haveGold < (int) $cost['gold'] || $haveChests < (int) $cost['chests']) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Za mało środków, by odblokować skill.');
            }

            // Flaga odblokowania PRZED serwisem (inaczej $save->state=$blob nadpisze koszt).
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

    /**
     * Przypisanie/wyczyszczenie slotu active-skilla (0-3). Parytet:
     * Inventory.tsx resolveSwap + skillStore setActiveSkillSlot — skill nie może
     * zajmować dwóch slotów naraz (poprzednie wystąpienie jest czyszczone).
     * Przypisać można tylko odblokowany skill; skillId=null czyści slot.
     */
    public function slot(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
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

            // Przypisać można tylko odblokowany skill (czyścić slot zawsze wolno).
            if ($skillId !== null && (($skills['unlockedSkills'][$skillId] ?? false) !== true)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Skill nie jest odblokowany.');
            }

            // Normalizacja do dokładnie 4 slotów (jak activeSkillSlots na froncie).
            $existing = array_values($skills['activeSkillSlots'] ?? []);
            $slots = [];
            for ($i = 0; $i < 4; $i++) {
                $slots[$i] = $existing[$i] ?? null;
            }

            // Mirror setActiveSkillSlot: usuń skill z innych slotów przed przypisaniem.
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
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'skillId' => ['required', 'string', 'max:64'],
        ]);
        $skillId = $data['skillId'];

        // Można trenować tylko staty dostępne dla klasy postaci.
        if (! in_array($skillId, SkillSystem::getTrainableStatsForClass((string) $character->class), true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Ten skill nie jest trenowalny dla tej klasy.');
        }

        $payload = DB::transaction(function () use ($character, $skillId, $state): array {
            $save = $state->lockedFor($character);
            $blob = $save->state;
            $skills = $blob['skills'] ?? [];

            // Najpierw zbierz XP z trwającego treningu (jak selectTrainingStat na froncie).
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
        /** @var Character $character */
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

    /**
     * Zbiera XP z bieżącego segmentu treningu: elapsed (czas SERWERA) → XP przez
     * SkillSystem, XP wsiąka w skillLevels/skillXp, timestamp startu resetuje się.
     * Mutuje $skills przez referencję. Zwraca podsumowanie (xpEarned=0 gdy brak treningu).
     *
     * @param  array<string, mixed>  $skills
     * @return array{skillId:string|null, xpEarned:int, newLevel:int, remainingXp:int, levelsGained:int}
     */
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

    /** Poziom odblokowania active skilla z treści (skills.json → activeSkills). Null = brak. */
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
