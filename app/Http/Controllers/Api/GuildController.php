<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Guild\GuildSystem;
use App\Domain\Progression\LevelSystem;
use App\Domain\Support\Rng\RngInterface;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildBossAttempt;
use App\Models\GuildBossContribution;
use App\Models\GuildBossState;
use App\Models\GuildJoinRequest;
use App\Models\GuildMember;
use App\Models\GuildTreasuryItem;
use App\Models\GuildTreasuryLog;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorytatywne endpointy gildii. SERWER liczy cały przepływ — klient wysyła
 * tylko intencje. Tożsamość aktualnej postaci pochodzi z {character}
 * (owns.character); postać/stan z BAZY, nigdy z body.
 *
 * Semantyka 1:1 z frontem (guildApi.ts / guildStore.ts / guildSystem.ts):
 *  - create: koszt GUILD_CREATE_COST_GOLD (1 000 000) schodzi z bloba game_saves
 *    (prawdziwy gold), zakładający = pierwszy członek + lider,
 *  - join: prośba ze snapshotem postaci z BAZY,
 *  - accept: WYŁĄCZNIE lider (403 inaczej), respektuje member_cap, czyści prośby,
 *  - leave: lider → sukcesja najstarszego członka lub rozwiązanie gdy pusto,
 *  - boss/damage: obrażenia = computeGuildBossDamage(atk, level, tier) SERWEROWO,
 *    HP bossa z getGuildBossMaxHp, XP gildii = zadane HP (applyGuildXp), kill → +1 tier,
 *  - treasury: item schodzi z bag ATOMOWO z insertem do skarbca (i odwrotnie).
 */
final class GuildController extends Controller
{
    /** Zakłada gildię: koszt z bloba + wiersz guild + zakładający jako lider. */
    public function create(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:32'],
            'tag' => ['required', 'string', 'max:8'],
            'logo' => ['nullable', 'string', 'max:190'],
            'color' => ['nullable', 'string', 'max:32'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "guild.create.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey), Response::HTTP_CREATED);
        }

        // Jedna postać = jedna gildia.
        if (GuildMember::query()->where('character_id', $character->id)->exists()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Jesteś już w gildii.');
        }

        $payload = DB::transaction(function () use ($state, $character, $data): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $save = $state->lockedFor($fresh);

            // Koszt założenia z PRAWDZIWEGO golda (blob) — rzuca 422 gdy za mało.
            $state->spendGold($save, GuildSystem::GUILD_CREATE_COST_GOLD);

            $guild = Guild::create([
                'name' => $data['name'],
                'tag' => strtoupper(substr($data['tag'], 0, 3)),
                'logo' => (string) ($data['logo'] ?? ''),
                'color' => (string) ($data['color'] ?? ''),
                'leader_id' => $fresh->id,
                'level' => 1,
                'xp' => 0,
                'boss_tier' => 1,
                'member_cap' => GuildSystem::GUILD_INITIAL_MEMBER_CAP,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->insertMember($guild, $fresh);
            $state->persist($save);

            return [
                'guild' => $this->guildSnapshot($guild),
                'gold' => $state->gold($save),
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload, Response::HTTP_CREATED);
    }

    /** Podgląd gildii: metadane + roster + prośby (leader-UI). */
    public function show(Request $request): JsonResponse
    {
        $guild = $this->guildFromRoute($request);

        return response()->json([
            'guild' => $this->guildSnapshot($guild),
            'members' => GuildMember::query()
                ->where('guild_id', $guild->id)
                ->orderBy('joined_at')
                ->get()
                ->map(fn (GuildMember $m): array => $this->memberSnapshot($m))
                ->all(),
            'requests' => GuildJoinRequest::query()
                ->where('guild_id', $guild->id)
                ->orderBy('requested_at')
                ->get()
                ->map(fn (GuildJoinRequest $r): array => $this->requestSnapshot($r))
                ->all(),
        ]);
    }

    /** Prośba o dołączenie — snapshot postaci z BAZY. Idempotentna. */
    public function join(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);

        // Jedna postać = jedna gildia.
        if (GuildMember::query()->where('character_id', $character->id)->exists()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Jesteś już w gildii.');
        }

        $existing = GuildJoinRequest::query()
            ->where('guild_id', $guild->id)
            ->where('character_id', $character->id)
            ->first();

        $req = $existing ?? GuildJoinRequest::create([
            'guild_id' => $guild->id,
            'character_id' => $character->id,
            'character_name' => $character->name,
            'character_class' => $character->class,
            'character_level' => (int) $character->level,
            'requested_at' => now(),
        ]);

        return response()->json(['ok' => true, 'request' => $this->requestSnapshot($req)]);
    }

    /** Lider akceptuje prośbę {charId} → dodaje członka, czyści prośby. 403 nie-lider. */
    public function accept(Request $request): JsonResponse
    {
        /** @var Character $leader */
        $leader = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);
        $charId = (string) $request->route('charId');

        if ($guild->leader_id !== $leader->id) {
            abort(Response::HTTP_FORBIDDEN, 'Tylko lider może akceptować prośby.');
        }

        $payload = DB::transaction(function () use ($guild, $charId): array {
            $locked = Guild::query()->lockForUpdate()->findOrFail($guild->id);

            $joinReq = GuildJoinRequest::query()
                ->where('guild_id', $locked->id)
                ->where('character_id', $charId)
                ->first();
            if ($joinReq === null) {
                abort(Response::HTTP_NOT_FOUND, 'Brak takiej prośby o dołączenie.');
            }

            // Idempotencja: jeśli już członek — tylko wyczyść prośby.
            $alreadyMember = GuildMember::query()
                ->where('guild_id', $locked->id)
                ->where('character_id', $charId)
                ->exists();

            if (! $alreadyMember) {
                $count = GuildMember::query()->where('guild_id', $locked->id)->count();
                if ($count >= (int) $locked->member_cap) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Gildia jest pełna.');
                }

                // Snapshot z ŻYWEJ postaci (fallback: dane z prośby).
                $joiner = Character::query()->find($charId);
                if ($joiner !== null) {
                    $this->insertMember($locked, $joiner);
                } else {
                    GuildMember::create([
                        'guild_id' => $locked->id,
                        'character_id' => $charId,
                        'character_name' => $joinReq->character_name,
                        'character_class' => $joinReq->character_class,
                        'character_level' => (int) $joinReq->character_level,
                        'character_transform_tier' => 0,
                        'joined_at' => now(),
                    ]);
                }
            }

            // Po dołączeniu znika z prośb WSZYSTKICH gildii (spec purgeRequests).
            GuildJoinRequest::query()->where('character_id', $charId)->delete();

            return [
                'ok' => true,
                'members' => GuildMember::query()
                    ->where('guild_id', $locked->id)
                    ->orderBy('joined_at')
                    ->get()
                    ->map(fn (GuildMember $m): array => $this->memberSnapshot($m))
                    ->all(),
            ];
        });

        return response()->json($payload);
    }

    /** Opuszczenie gildii — lider przekazuje ster najstarszemu lub rozwiązuje. */
    public function leave(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);

        $payload = DB::transaction(function () use ($guild, $character): array {
            $locked = Guild::query()->lockForUpdate()->findOrFail($guild->id);

            $member = GuildMember::query()
                ->where('guild_id', $locked->id)
                ->where('character_id', $character->id)
                ->first();
            if ($member === null) {
                abort(Response::HTTP_NOT_FOUND, 'Nie jesteś w tej gildii.');
            }

            $member->delete();

            if ($locked->leader_id !== $character->id) {
                return ['ok' => true, 'disbanded' => false];
            }

            // Lider odszedł: sukcesja najstarszego pozostałego członka.
            $successor = GuildMember::query()
                ->where('guild_id', $locked->id)
                ->orderBy('joined_at')
                ->first();

            if ($successor !== null) {
                $locked->leader_id = $successor->character_id;
                $locked->updated_at = now();
                $locked->save();

                return ['ok' => true, 'disbanded' => false];
            }

            // Brak następcy → rozwiązanie (czyścimy wszystkie powiązane wiersze).
            $this->disband($locked);

            return ['ok' => true, 'disbanded' => true];
        });

        return response()->json($payload);
    }

    /**
     * Atak na bossa gildii — SERWER liczy obrażenia/HP/tier/XP. Klient nie podaje
     * żadnych kwot. Kredytuje wkład + próbę + XP gildii (1 HP = 1 XP). Kill → +1 tier.
     */
    public function bossDamage(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);
        $this->assertMember($guild, $character);

        $requestId = (string) $request->input('requestId', '');
        $cacheKey = $requestId !== '' ? "guild.boss.{$character->id}.{$requestId}" : null;
        if ($cacheKey !== null && Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $epochMs = (int) (now()->timestamp * 1000);
        $weekStart = GuildSystem::getCurrentWeekStartIso($epochMs);
        $today = GuildSystem::getTodayIso($epochMs);

        $payload = DB::transaction(function () use ($guild, $character, $weekStart, $today): array {
            // Lock na gildii serializuje równoległe ataki (anty-podwójny-spawn bossa).
            $lockedGuild = Guild::query()->lockForUpdate()->findOrFail($guild->id);
            $tier = GuildSystem::clampGuildBossTier((int) $lockedGuild->boss_tier);

            $boss = $this->fetchOrCreateBoss($lockedGuild, $tier, $weekStart);
            if ($boss->boss_killed) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Boss w tym tygodniu jest już pokonany.');
            }

            // Obrażenia liczone SERWEROWO — atak/poziom z BAZY, nie z body.
            $raw = GuildSystem::computeGuildBossDamage(
                (int) $character->attack,
                (int) $character->level,
                $tier,
            );
            $actual = (int) min($raw, (int) $boss->boss_current_hp);

            $newHp = (int) $boss->boss_current_hp - $actual;
            $killed = $newHp <= 0;
            $boss->boss_current_hp = max(0, $newHp);
            $boss->boss_killed = $killed;
            if ($killed) {
                $boss->current_attacker_id = null;
            }
            $boss->updated_at = now();
            $boss->save();

            // Wkład tygodniowy (steruje mnożnikiem nagrody).
            $contribution = $this->addContribution($lockedGuild, $character, $weekStart, $actual);

            // Dzienna próba (kumulacja obrażeń w dniu).
            $this->logAttempt($lockedGuild, $character, $today, $actual);

            // XP gildii = zadane HP (1 HP = 1 XP) → applyGuildXp może wbić poziomy.
            $applied = GuildSystem::applyGuildXp((int) $lockedGuild->level, (int) $lockedGuild->xp, $actual);
            $lockedGuild->level = $applied['level'];
            $lockedGuild->xp = $applied['xp'];
            $lockedGuild->member_cap = GuildSystem::guildMemberCap($applied['level']);
            if ($killed) {
                // Zabity boss → następny tydzień o tier wyżej (clamp do 50).
                $lockedGuild->boss_tier = min(GuildSystem::GUILD_BOSS_MAX_TIER, $tier + 1);
            }
            $lockedGuild->updated_at = now();
            $lockedGuild->save();

            return [
                'ok' => true,
                'damageDealt' => $actual,
                'killed' => $killed,
                'leveledUp' => $applied['leveledUp'],
                'boss' => $this->bossSnapshot($boss),
                'guild' => $this->guildSnapshot($lockedGuild),
                'contributionTotal' => (int) $contribution->total_damage,
            ];
        });

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $payload, now()->addHour());
        }

        return response()->json($payload);
    }

    /** Wpłata do skarbca: item z bag → skarbiec + log. Atomowo, member-only. */
    public function treasuryDeposit(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);
        $this->assertMember($guild, $character);

        $data = $request->validate(['itemUuid' => ['required', 'string', 'max:128']]);

        $payload = DB::transaction(function () use ($state, $character, $guild, $data): array {
            $save = $state->lockedFor($character);
            $item = $state->findBagItem($save, $data['itemUuid']);
            if ($item === null) {
                abort(Response::HTTP_NOT_FOUND, 'Item nie istnieje w torbie.');
            }

            if (GuildTreasuryItem::query()->where('guild_id', $guild->id)->count() >= GuildSystem::GUILD_TREASURY_SLOTS) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Skarbiec jest pełny.');
            }

            // Escrow: item schodzi z bloba (rzuca gdy nie istnieje — anty-dupe).
            $state->removeBagItem($save, $data['itemUuid']);
            $itemJson = json_encode($item);
            $itemName = (string) ($item['name'] ?? $item['itemId'] ?? 'Przedmiot');

            $treasuryItem = GuildTreasuryItem::create([
                'guild_id' => $guild->id,
                'item_data' => $itemJson,
                'deposited_by' => $character->id,
                'deposited_by_name' => $character->name,
                'deposited_at' => now(),
            ]);

            GuildTreasuryLog::create([
                'guild_id' => $guild->id,
                'action' => 'deposit',
                'character_id' => $character->id,
                'character_name' => $character->name,
                'item_name' => $itemName,
                'item_data' => $itemJson,
                'created_at' => now(),
            ]);

            $state->persist($save);

            return [
                'ok' => true,
                'treasuryItemId' => $treasuryItem->id,
                'inventory' => $save->state['inventory'],
            ];
        });

        return response()->json($payload, Response::HTTP_CREATED);
    }

    /** Wypłata ze skarbca: item → bag odbiorcy + log. Idempotencja naturalna. */
    public function treasuryWithdraw(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);
        $this->assertMember($guild, $character);

        $data = $request->validate(['treasuryItemId' => ['required', 'string', 'max:128']]);

        $payload = DB::transaction(function () use ($state, $character, $guild, $data): array {
            $treasuryItem = GuildTreasuryItem::query()
                ->lockForUpdate()
                ->where('id', $data['treasuryItemId'])
                ->where('guild_id', $guild->id)
                ->first();
            if ($treasuryItem === null) {
                abort(Response::HTTP_NOT_FOUND, 'Przedmiotu nie ma już w skarbcu.');
            }

            $item = json_decode((string) $treasuryItem->item_data, true);
            if (! is_array($item)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Uszkodzony wpis skarbca.');
            }
            $itemName = (string) ($item['name'] ?? $item['itemId'] ?? 'Przedmiot');

            $save = $state->lockedFor($character);
            $state->addBagItem($save, $item);

            $treasuryItem->delete();

            GuildTreasuryLog::create([
                'guild_id' => $guild->id,
                'action' => 'withdraw',
                'character_id' => $character->id,
                'character_name' => $character->name,
                'item_name' => $itemName,
                'item_data' => $treasuryItem->item_data,
                'created_at' => now(),
            ]);

            $state->persist($save);

            return [
                'ok' => true,
                'inventory' => $save->state['inventory'],
            ];
        });

        return response()->json($payload);
    }

    /** Lider wyrzuca członka {charId} z gildii. 403 gdy nie-lider lub cel = lider. */
    public function kick(Request $request): JsonResponse
    {
        /** @var Character $leader */
        $leader = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);
        $charId = (string) $request->route('charId');

        if ($guild->leader_id !== $leader->id) {
            abort(Response::HTTP_FORBIDDEN, 'Tylko lider może wyrzucać członków.');
        }
        if ($charId === $guild->leader_id) {
            abort(Response::HTTP_FORBIDDEN, 'Lider nie może wyrzucić samego siebie.');
        }

        $payload = DB::transaction(function () use ($guild, $charId): array {
            $locked = Guild::query()->lockForUpdate()->findOrFail($guild->id);

            GuildMember::query()
                ->where('guild_id', $locked->id)
                ->where('character_id', $charId)
                ->delete();

            return [
                'ok' => true,
                'members' => GuildMember::query()
                    ->where('guild_id', $locked->id)
                    ->orderBy('joined_at')
                    ->get()
                    ->map(fn (GuildMember $m): array => $this->memberSnapshot($m))
                    ->all(),
            ];
        });

        return response()->json($payload);
    }

    /** Lider odrzuca prośbę {charId} — kasuje wiersz join-requesta w TEJ gildii. 403 nie-lider. */
    public function reject(Request $request): JsonResponse
    {
        /** @var Character $leader */
        $leader = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);
        $charId = (string) $request->route('charId');

        if ($guild->leader_id !== $leader->id) {
            abort(Response::HTTP_FORBIDDEN, 'Tylko lider może odrzucać prośby.');
        }

        $payload = DB::transaction(function () use ($guild, $charId): array {
            $locked = Guild::query()->lockForUpdate()->findOrFail($guild->id);

            GuildJoinRequest::query()
                ->where('guild_id', $locked->id)
                ->where('character_id', $charId)
                ->delete();

            return [
                'ok' => true,
                'requests' => GuildJoinRequest::query()
                    ->where('guild_id', $locked->id)
                    ->orderBy('requested_at')
                    ->get()
                    ->map(fn (GuildJoinRequest $r): array => $this->requestSnapshot($r))
                    ->all(),
            ];
        });

        return response()->json($payload);
    }

    /** Lider jawnie rozwiązuje gildię (bez opuszczania). 403 nie-lider. Cała kaskada disband(). */
    public function disbandGuild(Request $request): JsonResponse
    {
        /** @var Character $leader */
        $leader = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);

        if ($guild->leader_id !== $leader->id) {
            abort(Response::HTTP_FORBIDDEN, 'Tylko lider może rozwiązać gildię.');
        }

        DB::transaction(function () use ($guild): void {
            $locked = Guild::query()->lockForUpdate()->findOrFail($guild->id);
            $this->disband($locked);
        });

        return response()->json(['ok' => true, 'disbanded' => true]);
    }

    /**
     * Odbiór nagród za tygodniowego bossa — SERWER losuje i kredytuje wszystko
     * (gold/kamienie/potiony do bloba, XP do wiersza postaci). Zastępuje
     * mintowanie po stronie klienta. Wymaga: boss zabity + nagroda nieodebrana.
     * Idempotentne per requestId (cache) ORAZ przez flagę rewards_claimed.
     */
    public function bossClaimReward(Request $request, CharacterStateService $state, RngInterface $rng): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);
        $this->assertMember($guild, $character);

        $data = $request->validate(['requestId' => ['required', 'string', 'max:64']]);

        $cacheKey = "guild.boss.claim.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $weekStart = GuildSystem::getCurrentWeekStartIso((int) (now()->timestamp * 1000));

        $payload = DB::transaction(function () use ($state, $character, $guild, $weekStart, $rng): array {
            // Lock gildii serializuje claim równolegle z bossDamage (spójny tier/HP).
            $lockedGuild = Guild::query()->lockForUpdate()->findOrFail($guild->id);

            $contribution = GuildBossContribution::query()
                ->lockForUpdate()
                ->where('guild_id', $lockedGuild->id)
                ->where('character_id', $character->id)
                ->where('week_start', $weekStart)
                ->first();
            if ($contribution === null) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Brak wkładu w tym tygodniu.');
            }

            $boss = GuildBossState::query()
                ->where('guild_id', $lockedGuild->id)
                ->where('week_start', $weekStart)
                ->first();
            if ($boss === null || ! $boss->boss_killed) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Boss w tym tygodniu nie został pokonany.');
            }
            if ($contribution->rewards_claimed) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nagroda już odebrana.');
            }

            // Mnożnik z udziału w obrażeniach + tier z gildii → SERWEROWY roll.
            $tier = GuildSystem::clampGuildBossTier((int) $lockedGuild->boss_tier);
            $mult = GuildSystem::contributionMultiplier((int) $contribution->total_damage, (int) $boss->boss_max_hp);
            $rewards = GuildSystem::rollGuildBossRewards($tier, (int) $character->level, $mult, $rng);

            // Kredytowanie do bloba (gold/kamienie/potiony); item = placeholder (bez bag).
            $save = $state->lockedFor($character);
            $xpGain = 0;
            foreach ($rewards as $reward) {
                switch ($reward['kind']) {
                    case 'gold':
                        $state->addGold($save, (int) $reward['gold']);
                        break;
                    case 'xp':
                        $xpGain += (int) $reward['xp'];
                        break;
                    case 'stones':
                        $state->addStones($save, (string) $reward['stoneType'], (int) $reward['amount']);
                        break;
                    case 'potion':
                        foreach ($reward['consumables'] as $id => $count) {
                            $state->addConsumable($save, (string) $id, (int) $count);
                        }
                        break;
                    case 'item':
                    default:
                        break; // parytet: item to tylko label, brak realnego przedmiotu
                }
            }
            $state->persist($save);

            // XP → wiersz postaci (level-upy, punkty statystyk, highest_level) — jak inne kontrolery.
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            if ($xpGain > 0) {
                $lvl = LevelSystem::processXpGain((int) $fresh->level, (int) $fresh->xp, $xpGain);
                $fresh->level = $lvl['newLevel'];
                $fresh->xp = $lvl['remainingXp'];
                $fresh->stat_points = (int) $fresh->stat_points + $lvl['statPointsGained'];
                $fresh->highest_level = max((int) $fresh->highest_level, $lvl['newLevel']);
                $fresh->save();
            }

            // Kształt widoczny dla klienta + zapis rewards_json (IRolledReward[]: kind/label/icon).
            $display = array_map(fn (array $reward): array => [
                'kind' => $reward['kind'],
                'label' => $reward['label'],
                'icon' => $reward['icon'],
            ], $rewards);

            $contribution->rewards_claimed = true;
            $contribution->rewards_json = json_encode($display);
            $contribution->updated_at = now();
            $contribution->save();

            return [
                'ok' => true,
                'rewards' => $display,
                'inventory' => $save->state['inventory'],
                'gold' => $state->gold($save),
                'xp' => (int) $fresh->xp,
                'level' => (int) $fresh->level,
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * Widok bossa: stan tygodniowego bossa (fetch-or-create), mój wkład, wkłady
     * wszystkich, moje dzisiejsze próby oraz próby całego tygodnia. Member-only.
     */
    public function bossView(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);
        $this->assertMember($guild, $character);

        $epochMs = (int) (now()->timestamp * 1000);
        $weekStart = GuildSystem::getCurrentWeekStartIso($epochMs);
        $today = GuildSystem::getTodayIso($epochMs);

        // Fetch-or-create bossa pod lockiem gildii (anti-double-spawn, jak bossDamage).
        $boss = DB::transaction(function () use ($guild, $weekStart): GuildBossState {
            $locked = Guild::query()->lockForUpdate()->findOrFail($guild->id);
            $tier = GuildSystem::clampGuildBossTier((int) $locked->boss_tier);

            return $this->fetchOrCreateBoss($locked, $tier, $weekStart);
        });

        $myContribution = GuildBossContribution::query()
            ->where('guild_id', $guild->id)
            ->where('character_id', $character->id)
            ->where('week_start', $weekStart)
            ->first();

        return response()->json([
            'boss' => $this->bossSnapshot($boss),
            'contribution' => $myContribution !== null ? $this->contributionSnapshot($myContribution) : null,
            'contributions' => GuildBossContribution::query()
                ->where('guild_id', $guild->id)
                ->where('week_start', $weekStart)
                ->get()
                ->map(fn (GuildBossContribution $c): array => $this->contributionSnapshot($c))
                ->all(),
            'attemptsToday' => GuildBossAttempt::query()
                ->where('guild_id', $guild->id)
                ->where('character_id', $character->id)
                ->where('attempt_date', $today)
                ->get()
                ->map(fn (GuildBossAttempt $a): array => $this->attemptSnapshot($a))
                ->all(),
            'weeklyAttempts' => GuildBossAttempt::query()
                ->where('guild_id', $guild->id)
                ->where('attempt_date', '>=', $weekStart)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (GuildBossAttempt $a): array => $this->attemptSnapshot($a))
                ->all(),
        ]);
    }

    /** Widok skarbca: przedmioty + logi. Member-only. */
    public function treasuryView(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $guild = $this->guildFromRoute($request);
        $this->assertMember($guild, $character);

        return response()->json([
            'items' => GuildTreasuryItem::query()
                ->where('guild_id', $guild->id)
                ->orderByDesc('deposited_at')
                ->limit(GuildSystem::GUILD_TREASURY_SLOTS)
                ->get()
                ->map(fn (GuildTreasuryItem $i): array => $this->treasuryItemSnapshot($i))
                ->all(),
            'logs' => GuildTreasuryLog::query()
                ->where('guild_id', $guild->id)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn (GuildTreasuryLog $l): array => $this->treasuryLogSnapshot($l))
                ->all(),
        ]);
    }

    /**
     * Przeglądarka gildii: paginowana lista (offset/limit/search) + count +
     * per-gildia {memberCount, leaderName}. Bez postaci (tylko supabase.auth).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:64'],
        ]);
        $offset = (int) ($data['offset'] ?? 0);
        $limit = (int) ($data['limit'] ?? 10);
        $search = isset($data['search']) ? trim((string) $data['search']) : '';

        $base = Guild::query();
        if ($search !== '') {
            // Case-insensitive contains — LOWER(...) LIKE działa i na sqlite (testy), i na pgsql.
            $safe = (string) preg_replace('/[%_*]/', '', $search);
            $base->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($safe).'%']);
        }

        $total = (clone $base)->count();
        $guilds = (clone $base)
            ->orderByDesc('level')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get();

        // Per-gildia podsumowanie: liczba członków + nick lidera (jeden pull member-rows).
        $summaries = [];
        foreach ($guilds as $g) {
            $summaries[$g->id] = ['memberCount' => 0, 'leaderName' => null];
        }
        $ids = $guilds->pluck('id')->all();
        if ($ids !== []) {
            $members = GuildMember::query()
                ->whereIn('guild_id', $ids)
                ->get(['guild_id', 'character_id', 'character_name']);

            $nameByChar = [];
            foreach ($members as $m) {
                $nameByChar[$m->character_id] = $m->character_name;
                if (isset($summaries[$m->guild_id])) {
                    $summaries[$m->guild_id]['memberCount']++;
                }
            }
            foreach ($guilds as $g) {
                $summaries[$g->id]['leaderName'] = $nameByChar[$g->leader_id] ?? null;
            }
        }

        return response()->json([
            'guilds' => $guilds->map(fn (Guild $g): array => $this->guildSnapshot($g))->all(),
            'summaries' => (object) $summaries,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    // ---- Helpery ------------------------------------------------------------

    /** Gildia z parametru {guild} (Laravel gubi model-binding przy 2 parametrach). */
    private function guildFromRoute(Request $request): Guild
    {
        $guild = Guild::query()->find((string) $request->route('guild'));
        if ($guild === null) {
            abort(Response::HTTP_NOT_FOUND, 'Gildia nie istnieje.');
        }

        return $guild;
    }

    /** Wymusza członkostwo aktualnej postaci w gildii (inaczej 403). */
    private function assertMember(Guild $guild, Character $character): void
    {
        $isMember = GuildMember::query()
            ->where('guild_id', $guild->id)
            ->where('character_id', $character->id)
            ->exists();
        if (! $isMember) {
            abort(Response::HTTP_FORBIDDEN, 'Nie jesteś członkiem tej gildii.');
        }
    }

    /** Wstawia członka ze snapshotem żywej postaci. */
    private function insertMember(Guild $guild, Character $character): GuildMember
    {
        return GuildMember::create([
            'guild_id' => $guild->id,
            'character_id' => $character->id,
            'character_name' => $character->name,
            'character_class' => $character->class,
            'character_level' => (int) $character->level,
            'character_transform_tier' => 0,
            'joined_at' => now(),
        ]);
    }

    /** Rozwiązuje gildię: usuwa członków/prośby/bossy/skarbiec + sam wiersz gildii. */
    private function disband(Guild $guild): void
    {
        GuildMember::query()->where('guild_id', $guild->id)->delete();
        GuildJoinRequest::query()->where('guild_id', $guild->id)->delete();
        GuildBossState::query()->where('guild_id', $guild->id)->delete();
        GuildBossAttempt::query()->where('guild_id', $guild->id)->delete();
        GuildBossContribution::query()->where('guild_id', $guild->id)->delete();
        GuildTreasuryItem::query()->where('guild_id', $guild->id)->delete();
        GuildTreasuryLog::query()->where('guild_id', $guild->id)->delete();
        $guild->delete();
    }

    /** Bierze (lub leniwie tworzy) tygodniowy wiersz bossa dla gildii. */
    private function fetchOrCreateBoss(Guild $guild, int $tier, string $weekStart): GuildBossState
    {
        $boss = GuildBossState::query()
            ->where('guild_id', $guild->id)
            ->where('week_start', $weekStart)
            ->first();
        if ($boss !== null) {
            return $boss;
        }

        $maxHp = GuildSystem::getGuildBossMaxHp($tier);

        return GuildBossState::create([
            'guild_id' => $guild->id,
            'week_start' => $weekStart,
            'boss_tier' => $tier,
            'boss_max_hp' => $maxHp,
            'boss_current_hp' => $maxHp,
            'boss_killed' => false,
            'current_attacker_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Dokłada obrażenia do tygodniowego wkładu postaci (fetch-or-create). */
    private function addContribution(Guild $guild, Character $character, string $weekStart, int $damage): GuildBossContribution
    {
        $row = GuildBossContribution::query()
            ->where('guild_id', $guild->id)
            ->where('character_id', $character->id)
            ->where('week_start', $weekStart)
            ->first();

        if ($row === null) {
            return GuildBossContribution::create([
                'guild_id' => $guild->id,
                'character_id' => $character->id,
                'week_start' => $weekStart,
                'total_damage' => $damage,
                'rewards_claimed' => false,
                'updated_at' => now(),
            ]);
        }

        $row->total_damage = (int) $row->total_damage + $damage;
        $row->updated_at = now();
        $row->save();

        return $row;
    }

    /** Kumuluje dzienną próbę postaci (jeden wiersz per dzień). */
    private function logAttempt(Guild $guild, Character $character, string $today, int $damage): void
    {
        $row = GuildBossAttempt::query()
            ->where('guild_id', $guild->id)
            ->where('character_id', $character->id)
            ->where('attempt_date', $today)
            ->first();

        if ($row === null) {
            GuildBossAttempt::create([
                'guild_id' => $guild->id,
                'character_id' => $character->id,
                'character_name' => $character->name,
                'attempt_date' => $today,
                'damage_dealt' => $damage,
                'created_at' => now(),
            ]);

            return;
        }

        $row->damage_dealt = (int) $row->damage_dealt + $damage;
        $row->character_name = $character->name;
        $row->save();
    }

    /** @return array<string, mixed> Kształt 1:1 z IGuildRow. */
    private function guildSnapshot(Guild $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'tag' => $g->tag,
            'logo' => $g->logo,
            'color' => $g->color,
            'leader_id' => $g->leader_id,
            'level' => (int) $g->level,
            'xp' => (int) $g->xp,
            'boss_tier' => (int) $g->boss_tier,
            'member_cap' => (int) $g->member_cap,
            'created_at' => optional($g->created_at)->toIso8601String(),
            'updated_at' => optional($g->updated_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> Kształt 1:1 z IGuildMemberRow. */
    private function memberSnapshot(GuildMember $m): array
    {
        return [
            'id' => $m->id,
            'guild_id' => $m->guild_id,
            'character_id' => $m->character_id,
            'character_name' => $m->character_name,
            'character_class' => $m->character_class,
            'character_level' => (int) $m->character_level,
            'character_transform_tier' => (int) $m->character_transform_tier,
            'joined_at' => optional($m->joined_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> Kształt 1:1 z IGuildJoinRequestRow. */
    private function requestSnapshot(GuildJoinRequest $r): array
    {
        return [
            'id' => $r->id,
            'guild_id' => $r->guild_id,
            'character_id' => $r->character_id,
            'character_name' => $r->character_name,
            'character_class' => $r->character_class,
            'character_level' => (int) $r->character_level,
            'requested_at' => optional($r->requested_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> Kształt 1:1 z IGuildBossStateRow. */
    private function bossSnapshot(GuildBossState $b): array
    {
        return [
            'id' => $b->id,
            'guild_id' => $b->guild_id,
            'week_start' => $b->week_start,
            'boss_tier' => (int) $b->boss_tier,
            'boss_max_hp' => (int) $b->boss_max_hp,
            'boss_current_hp' => (int) $b->boss_current_hp,
            'boss_killed' => (bool) $b->boss_killed,
            'current_attacker_id' => $b->current_attacker_id,
            'created_at' => optional($b->created_at)->toIso8601String(),
            'updated_at' => optional($b->updated_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> Kształt 1:1 z IGuildBossContributionRow. */
    private function contributionSnapshot(GuildBossContribution $c): array
    {
        return [
            'id' => $c->id,
            'guild_id' => $c->guild_id,
            'character_id' => $c->character_id,
            'week_start' => $c->week_start,
            'total_damage' => (int) $c->total_damage,
            'rewards_claimed' => (bool) $c->rewards_claimed,
            'rewards_json' => $c->rewards_json,
            'updated_at' => optional($c->updated_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> Kształt 1:1 z IGuildBossAttemptRow. */
    private function attemptSnapshot(GuildBossAttempt $a): array
    {
        return [
            'id' => $a->id,
            'guild_id' => $a->guild_id,
            'character_id' => $a->character_id,
            'character_name' => $a->character_name,
            'attempt_date' => $a->attempt_date,
            'damage_dealt' => (int) $a->damage_dealt,
            'created_at' => optional($a->created_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> Kształt 1:1 z IGuildTreasuryItemRow. */
    private function treasuryItemSnapshot(GuildTreasuryItem $i): array
    {
        return [
            'id' => $i->id,
            'guild_id' => $i->guild_id,
            'item_data' => $i->item_data,
            'deposited_by' => $i->deposited_by,
            'deposited_by_name' => $i->deposited_by_name,
            'deposited_at' => optional($i->deposited_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> Kształt 1:1 z IGuildTreasuryLogRow. */
    private function treasuryLogSnapshot(GuildTreasuryLog $l): array
    {
        return [
            'id' => $l->id,
            'guild_id' => $l->guild_id,
            'action' => $l->action,
            'character_id' => $l->character_id,
            'character_name' => $l->character_name,
            'item_name' => $l->item_name,
            'item_data' => $l->item_data,
            'created_at' => optional($l->created_at)->toIso8601String(),
        ];
    }
}
