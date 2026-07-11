<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Arena\ArenaMath;
use App\Domain\Arena\ArenaShop;
use App\Domain\Combat\CombatMath;
use App\Domain\Content\ContentRepository;
use App\Domain\Loot\ItemGenerator;
use App\Domain\Support\Rng\RngInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Models\GameSave;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorytatywny mecz areny (PvP). Serwer:
 *  - bierze NAPASTNIKA z tokenu ({character} + owns.character), OBROŃCĘ z bazy
 *    po `opponentId` z body (dowolna postać, także innego usera),
 *  - SYMULUJE walkę WŁASNYM RNG i sam liczy `attackerWon` — pole `attackerWon`
 *    z body jest IGNOROWANE (anty-forge-win). Uproszczony model jak HuntResolver:
 *    tury napastnik→obrońca, mitygacja max(1, atk-def), obrażenia × ARENA_DAMAGE_MULTIPLIER,
 *  - `attackerIsHigher` = arena_league_points napastnika < obrońcy (z BAZY, nie z body →
 *    zamyka arbitrary-league),
 *  - nagrody z ArenaMath::getMatchReward(won, higher),
 *  - aktualizuje OBIE postaci atomowo: arena_kills/deaths (zwycięzca +kill, przegrany
 *    +death → zamyka grief), arena_league_points += leaguePoints; league bez zmian
 *    (getSeasonOutcome to rozliczenie SEZONU po rankingu — nie dotyczy pojedynczego meczu),
 *  - arenaPoints napastnika → blob (inventory.arenaPoints) przez CharacterStateService,
 *  - idempotencja po (napastnik + requestId) w Cache.
 */
final class ArenaController extends Controller
{
    /** Parytet z frontem: src/systems/arenaSystem.ts ARENA_DAMAGE_MULTIPLIER. */
    private const ARENA_DAMAGE_MULTIPLIER = 0.2;

    /** Bezpiecznik pętli symulacji (obrażenia >= 1/turę → zawsze się kończy). */
    private const MAX_ROUNDS = 100000;

    public function match(Request $request, CharacterStateService $state, RngInterface $rng): JsonResponse
    {
        /** @var Character $attacker */
        $attacker = $request->attributes->get('character');

        $data = $request->validate([
            'opponentId' => ['required', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
            // `attackerWon` może przyjść z klienta, ale serwer go NIE czyta.
        ]);

        $opponentId = (string) $data['opponentId'];
        if ($opponentId === $attacker->id) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nie można walczyć z samym sobą.');
        }

        // Istnienie obrońcy — bot / nieznane id → 404 (weryfikacja ponownie pod lockiem).
        if (Character::query()->whereKey($opponentId)->doesntExist()) {
            abort(Response::HTTP_NOT_FOUND, 'Przeciwnik nie istnieje.');
        }

        $cacheKey = "arena.match.{$attacker->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($attacker, $opponentId, $state, $rng): array {
            // Lock OBU postaci w stałej kolejności (po id) — brak deadlocka.
            $ids = [$attacker->id, $opponentId];
            sort($ids);
            $locked = Character::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $atk = $locked->get($attacker->id);
            $def = $locked->get($opponentId);
            if ($atk === null || $def === null) {
                abort(Response::HTTP_NOT_FOUND, 'Przeciwnik nie istnieje.');
            }

            $save = $state->lockedFor($atk);

            // 1) Wynik walki — WYŁĄCZNIE serwerowa symulacja.
            $attackerWon = $this->simulateAttackerWins($atk, $def, $rng);

            // 2) attackerIsHigher — z arena_league_points w BAZIE (nie z body).
            $attackerIsHigher = (int) $atk->arena_league_points < (int) $def->arena_league_points;

            // 3) Nagrody.
            $reward = ArenaMath::getMatchReward($attackerWon, $attackerIsHigher);

            // 4) Liczniki kill/death — zwycięzca +kill, przegrany +death.
            if ($attackerWon) {
                $atk->arena_kills = (int) $atk->arena_kills + 1;
                $def->arena_deaths = (int) $def->arena_deaths + 1;
            } else {
                $atk->arena_deaths = (int) $atk->arena_deaths + 1;
                $def->arena_kills = (int) $def->arena_kills + 1;
            }

            // 5) Punkty ligowe += leaguePoints (obie strony).
            $atk->arena_league_points = (int) $atk->arena_league_points + (int) $reward['attacker']['leaguePoints'];
            $def->arena_league_points = (int) $def->arena_league_points + (int) $reward['defender']['leaguePoints'];

            $atk->save();
            $def->save();

            // 6) arenaPoints NAPASTNIKA → blob (kredyt inwentarza dostaje tylko atakujący).
            $ap = (int) $reward['attacker']['arenaPoints'];
            if ($ap > 0) {
                $state->addArenaPoints($save, $ap);
            }
            $state->persist($save);

            return [
                'attackerWon' => $attackerWon,
                'attackerIsHigher' => $attackerIsHigher,
                'reward' => $reward,
                'character' => (new CharacterResource($atk))->resolve(),
                'opponent' => [
                    'id' => $def->id,
                    'name' => $def->name,
                    'arena_kills' => (int) $def->arena_kills,
                    'arena_deaths' => (int) $def->arena_deaths,
                    'arena_league' => $def->arena_league,
                    'arena_league_points' => (int) $def->arena_league_points,
                ],
                'arenaPoints' => (int) ($save->state['inventory']['arenaPoints'] ?? 0),
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * GET /arena/shop — katalog areny (AP) + aktualne arenaPoints postaci.
     * Katalog liczy ArenaShop z shop.json (eliksiry) — 1:1 z getArenaShopCatalog.
     */
    public function shop(Request $request, CharacterStateService $state, ContentRepository $content): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $save = $state->lockedFor($character);
        $elixirs = $content->get('shop')['elixirs'] ?? [];

        return response()->json([
            'catalog' => ArenaShop::catalog($elixirs),
            'arenaPoints' => (int) ($save->state['inventory']['arenaPoints'] ?? 0),
        ]);
    }

    /**
     * POST /arena/shop/buy {itemId, requestId} — kup za AP. Cena/gating/typ
     * broni liczy SERWER (ArenaShop + ItemGenerator). Parytet: buyArenaItem.
     */
    public function buy(Request $request, CharacterStateService $state, ContentRepository $content, RngInterface $rng): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'itemId' => ['required', 'string', 'max:64'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "arena.shop.buy.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $elixirs = $content->get('shop')['elixirs'] ?? [];
        $item = ArenaShop::findItem($data['itemId'], $elixirs);
        if ($item === null) {
            abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego towaru w sklepie areny.');
        }

        $level = (int) $character->level;
        $price = ArenaShop::apPrice($item, $level);

        // Poteki: bramka poziomu REALNEJ poteki (payloadId) PRZED wydaniem AP.
        if ($item['kind'] === 'potion') {
            $minLevel = ArenaShop::getPotionMinLevel((string) $item['payloadId']);
            if ($level < $minLevel) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Wymagany poziom {$minLevel}.");
            }
        }

        $templates = $content->get('itemTemplates');

        $payload = DB::transaction(function () use ($state, $character, $item, $price, $level, $templates, $rng): array {
            $save = $state->lockedFor($character);

            $have = (int) ($save->state['inventory']['arenaPoints'] ?? 0);
            if ($have < $price) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Za mało arena points: masz {$have}, potrzeba {$price}.");
            }
            $state->addArenaPoints($save, -$price);

            $granted = $this->grant($state, $save, $item, $level, (string) $character->class, $templates, $rng);

            $state->persist($save);

            return [
                'itemId' => $item['id'],
                'granted' => $granted,
                'arenaPoints' => (int) ($save->state['inventory']['arenaPoints'] ?? 0),
                'inventory' => $save->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * Wydaje kupiony towar do bloba i zwraca deskryptor tego, co przyznano.
     * Broń mityczna: typ z klasy postaci (fallback = pierwszy szablon).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $templates
     * @return array<string, mixed>
     */
    private function grant(
        CharacterStateService $state,
        GameSave $save,
        array $item,
        int $level,
        string $characterClass,
        array $templates,
        RngInterface $rng,
    ): array {
        $kind = (string) $item['kind'];

        if ($kind === 'stone') {
            $state->addStones($save, (string) $item['payloadId'], 1);

            return ['kind' => 'stone', 'stoneType' => (string) $item['payloadId'], 'count' => 1];
        }

        if ($kind === 'potion' || $kind === 'elixir') {
            $state->addConsumable($save, (string) $item['payloadId'], 1);

            return ['kind' => $kind, 'consumableId' => (string) $item['payloadId'], 'count' => 1];
        }

        // mythic_weapon / mythic_offhand — generacja przez ItemGenerator (mythic).
        // Typ broni rozstrzyga klasa postaci (fallback = pierwszy szablon), jak w TS.
        $generator = new ItemGenerator($templates, $rng);
        $lvl = max(1, min(ArenaShop::MYTHIC_LEVEL_CAP, $level));

        if ($kind === 'mythic_weapon') {
            $fallback = (string) ($templates['weapons'][0]['type'] ?? 'sword');
            $type = ArenaShop::weaponTypeForClass($characterClass, $fallback);
            $generated = $generator->generateWeapon($type, $lvl, 'mythic');
        } else {
            $fallback = (string) ($templates['offhands'][0]['type'] ?? 'shield');
            $type = ArenaShop::offhandTypeForClass($characterClass, $fallback);
            $generated = $generator->generateOffhand($type, $lvl, 'mythic');
        }

        if ($generated === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nie udało się wygenerować przedmiotu.');
        }
        $state->addBagItem($save, $generated);

        return ['kind' => $kind, 'item' => $generated];
    }

    /**
     * GET /arena/season — wycinek sezonu z bloba + podgląd nagrody (gdy znany
     * finalRank z pendingRewards). Parytet: arenaStore.pendingRewards.
     */
    public function season(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $save = $state->lockedFor($character);
        $arena = $save->state['arena'] ?? [];

        $league = (string) ($arena['league'] ?? $character->arena_league ?? 'bronze');
        $seasonPoints = (int) ($arena['seasonPoints'] ?? 0);
        $pending = $arena['pendingRewards'] ?? null;
        $finalRank = $pending !== null ? (int) $pending['finalRank'] : null;

        $preview = null;
        if ($finalRank !== null) {
            $bucket = ArenaMath::findRewardBucket($finalRank);
            if ($bucket !== null) {
                $preview = ArenaMath::applyLeagueMultiplier($bucket, (string) $pending['league']);
            }
        }

        return response()->json([
            'league' => $league,
            'seasonPoints' => $seasonPoints,
            'finalRank' => $finalRank,
            'pendingRewards' => $pending,
            'rewardPreview' => $preview,
        ]);
    }

    /**
     * POST /arena/season/claim {requestId} — odbierz nagrody sezonu.
     * Parytet: arenaStore.claimSeasonRewards. Skalowanie ligą, przyznanie
     * gold/AP/kamieni/potek, awans/spadek (getSeasonOutcome) + reset wycinka.
     * Idempotentne (requestId) i czyści pendingRewards (brak double-claim).
     */
    public function claimSeason(Request $request, CharacterStateService $state): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "arena.season.claim.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($state, $character): array {
            $save = $state->lockedFor($character);
            $arena = $save->state['arena'] ?? [];
            $pending = $arena['pendingRewards'] ?? null;
            if ($pending === null) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Brak nagród sezonu do odebrania.');
            }

            $finalRank = (int) $pending['finalRank'];
            $league = (string) $pending['league'];

            // Przyznanie nagród — niski finisher może nie mieć kubełka (bucket=null),
            // ale i tak resetujemy sezon (nie może utknąć).
            $granted = [
                'gold' => 0, 'arenaPoints' => 0,
                'commonStones' => 0, 'rareStones' => 0, 'epicStones' => 0,
                'legendaryStones' => 0, 'mythicStones' => 0,
                'pctHpPotion' => 0, 'pctMpPotion' => 0,
            ];

            $bucket = ArenaMath::findRewardBucket($finalRank);
            if ($bucket !== null) {
                $scaled = ArenaMath::applyLeagueMultiplier($bucket, $league);

                if ((int) $scaled['gold'] > 0) {
                    $state->addGold($save, (int) $scaled['gold']);
                }
                if ((int) $scaled['arenaPoints'] > 0) {
                    $state->addArenaPoints($save, (int) $scaled['arenaPoints']);
                }
                foreach ([
                    'common_stone' => 'commonStones',
                    'rare_stone' => 'rareStones',
                    'epic_stone' => 'epicStones',
                    'legendary_stone' => 'legendaryStones',
                    'mythic_stone' => 'mythicStones',
                ] as $stoneType => $key) {
                    if ((int) $scaled[$key] > 0) {
                        $state->addStones($save, $stoneType, (int) $scaled[$key]);
                    }
                }
                if ((int) $scaled['pctHpPotion'] > 0) {
                    $state->addConsumable($save, 'hp_potion_divine', (int) $scaled['pctHpPotion']);
                }
                if ((int) $scaled['pctMpPotion'] > 0) {
                    $state->addConsumable($save, 'mp_potion_divine', (int) $scaled['pctMpPotion']);
                }

                $granted = [
                    'gold' => (int) $scaled['gold'],
                    'arenaPoints' => (int) $scaled['arenaPoints'],
                    'commonStones' => (int) $scaled['commonStones'],
                    'rareStones' => (int) $scaled['rareStones'],
                    'epicStones' => (int) $scaled['epicStones'],
                    'legendaryStones' => (int) $scaled['legendaryStones'],
                    'mythicStones' => (int) $scaled['mythicStones'],
                    'pctHpPotion' => (int) $scaled['pctHpPotion'],
                    'pctMpPotion' => (int) $scaled['pctMpPotion'],
                ];
            }

            // Awans/spadek na koniec sezonu.
            $outcome = ArenaMath::getSeasonOutcome($league, $finalRank);
            $newLeague = $outcome['toLeague'] ?? $league;

            // Reset wycinka sezonu w blobie (czyści pendingRewards — brak double-claim).
            $stateArr = $save->state;
            $stateArr['arena'] = [
                'league' => $newLeague,
                'seasonPoints' => 0,
                'pendingRewards' => null,
            ];
            $save->state = $stateArr;

            // Kolumna leaderboardowa arena_league na characters — osobny lock.
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);
            $fresh->arena_league = $newLeague;
            $fresh->save();

            $state->persist($save);

            return [
                'granted' => $granted,
                'outcome' => $outcome,
                'character' => (new CharacterResource($fresh))->resolve(),
                'inventory' => $save->state['inventory'],
            ];
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }

    /**
     * Uproszczona, deterministyczna (seeded RNG) symulacja pojedynku areny.
     * Tura napastnik→obrońca, obaj startują z max_hp, obrażenia × ARENA_DAMAGE_MULTIPLIER.
     * Zwraca czy WYGRAŁ NAPASTNIK. Obrażenia >= 1/uderzenie ⇒ pętla się kończy.
     */
    private function simulateAttackerWins(Character $attacker, Character $defender, RngInterface $rng): bool
    {
        $atkHp = (int) $attacker->max_hp > 0 ? (int) $attacker->max_hp : 1;
        $defHp = (int) $defender->max_hp > 0 ? (int) $defender->max_hp : 1;

        $rounds = 0;
        while ($rounds < self::MAX_ROUNDS) {
            $rounds++;

            // Napastnik → obrońca.
            $aCrit = $rng->nextFloat() < min((float) $attacker->crit_chance, 0.5);
            $aHit = CombatMath::calculateDamage([
                'baseAtk' => $attacker->attack,
                'weaponAtk' => 0,
                'skillBonus' => 0,
                'classModifier' => 1,
                'enemyDefense' => $defender->defense,
                'isCrit' => $aCrit,
                'isBlocked' => false,
                'isDodged' => false,
                'critDmg' => $attacker->crit_damage,
                'damageMultiplier' => self::ARENA_DAMAGE_MULTIPLIER,
            ]);
            $defHp -= $aHit['finalDamage'];
            if ($defHp <= 0) {
                return true;
            }

            // Obrońca → napastnik.
            $dCrit = $rng->nextFloat() < min((float) $defender->crit_chance, 0.5);
            $dHit = CombatMath::calculateDamage([
                'baseAtk' => $defender->attack,
                'weaponAtk' => 0,
                'skillBonus' => 0,
                'classModifier' => 1,
                'enemyDefense' => $attacker->defense,
                'isCrit' => $dCrit,
                'isBlocked' => false,
                'isDodged' => false,
                'critDmg' => $defender->crit_damage,
                'damageMultiplier' => self::ARENA_DAMAGE_MULTIPLIER,
            ]);
            $atkHp -= $dHit['finalDamage'];
            if ($atkHp <= 0) {
                return false;
            }
        }

        // Stalemate (praktycznie nieosiągalny) — rozstrzyga frakcja zostałego HP,
        // remis na korzyść napastnika (uderza pierwszy).
        return ($atkHp / max(1, (int) $attacker->max_hp)) >= ($defHp / max(1, (int) $defender->max_hp));
    }
}
