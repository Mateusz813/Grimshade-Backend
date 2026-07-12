<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use App\Repositories\CharacterRepository;
use App\Services\CharacterStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class CharacterController extends Controller
{
    private const MAX_CHARACTERS = 7;

    private const CLASSES = ['Knight', 'Mage', 'Cleric', 'Archer', 'Rogue', 'Necromancer', 'Bard'];

    private const EQUIPMENT_SLOTS = [
        'helmet', 'armor', 'pants', 'gloves', 'shoulders', 'boots',
        'mainHand', 'offHand', 'ring1', 'ring2', 'earrings', 'necklace',
    ];

    private const CLASS_BASE_STATS = [
        'Knight' => ['hp' => 120, 'max_hp' => 120, 'mp' => 30, 'max_mp' => 30, 'attack' => 10, 'defense' => 5, 'attack_speed' => 1.5, 'crit_chance' => 0.03, 'crit_damage' => 2.0, 'magic_level' => 0],
        'Mage' => ['hp' => 80, 'max_hp' => 80, 'mp' => 200, 'max_mp' => 200, 'attack' => 6, 'defense' => 2, 'attack_speed' => 2.0, 'crit_chance' => 0.05, 'crit_damage' => 2.0, 'magic_level' => 5],
        'Cleric' => ['hp' => 100, 'max_hp' => 100, 'mp' => 150, 'max_mp' => 150, 'attack' => 7, 'defense' => 4, 'attack_speed' => 2.0, 'crit_chance' => 0.03, 'crit_damage' => 2.0, 'magic_level' => 5],
        'Archer' => ['hp' => 100, 'max_hp' => 100, 'mp' => 80, 'max_mp' => 80, 'attack' => 10, 'defense' => 3, 'attack_speed' => 2.5, 'crit_chance' => 0.10, 'crit_damage' => 2.0, 'magic_level' => 0],
        'Rogue' => ['hp' => 90, 'max_hp' => 90, 'mp' => 60, 'max_mp' => 60, 'attack' => 9, 'defense' => 3, 'attack_speed' => 2.5, 'crit_chance' => 0.15, 'crit_damage' => 2.5, 'magic_level' => 0],
        'Necromancer' => ['hp' => 85, 'max_hp' => 85, 'mp' => 180, 'max_mp' => 180, 'attack' => 6, 'defense' => 2, 'attack_speed' => 1.8, 'crit_chance' => 0.05, 'crit_damage' => 2.0, 'magic_level' => 5],
        'Bard' => ['hp' => 95, 'max_hp' => 95, 'mp' => 120, 'max_mp' => 120, 'attack' => 8, 'defense' => 3, 'attack_speed' => 2.0, 'crit_chance' => 0.07, 'crit_damage' => 2.0, 'magic_level' => 3],
    ];

    private const STARTER_WEAPONS = [
        'Knight' => ['id' => 'sword_of_beginnings', 'dmg_min' => 4, 'dmg_max' => 8],
        'Mage' => ['id' => 'apprentice_staff', 'dmg_min' => 3, 'dmg_max' => 6],
        'Cleric' => ['id' => 'wooden_mace', 'dmg_min' => 3, 'dmg_max' => 7],
        'Archer' => ['id' => 'short_bow', 'dmg_min' => 4, 'dmg_max' => 8],
        'Rogue' => ['id' => 'rusty_dagger', 'dmg_min' => 3, 'dmg_max' => 7],
        'Necromancer' => ['id' => 'bone_staff', 'dmg_min' => 3, 'dmg_max' => 6],
        'Bard' => ['id' => 'lute', 'dmg_min' => 3, 'dmg_max' => 6],
    ];

    public function index(Request $request, CharacterRepository $characters): AnonymousResourceCollection
    {
        $user = $request->attributes->get('supabase_user');

        return CharacterResource::collection(
            $characters->forUser($user->id),
        );
    }

    public function store(Request $request, CharacterStateService $state): JsonResponse
    {
        $user = $request->attributes->get('supabase_user');

        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);

        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'min:3', 'max:18', 'regex:/^[a-zA-Z0-9]+(?: [a-zA-Z0-9]+)?$/'],
            'class' => ['required', 'string', Rule::in(self::CLASSES)],
        ]);

        $cacheKey = "characters.store.{$user->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey), Response::HTTP_CREATED);
        }

        if (Character::query()->where('user_id', $user->id)->count() >= self::MAX_CHARACTERS) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Osiągnięto limit 7 postaci.');
        }

        $class = (string) $data['class'];
        $base = self::CLASS_BASE_STATS[$class];

        $payload = DB::transaction(function () use ($user, $data, $class, $base, $state): array {
            $character = Character::create([
                'user_id' => $user->id,
                'name' => (string) $data['name'],
                'class' => $class,
                'level' => 1,
                'xp' => 0,
                'hp' => (int) $base['hp'],
                'max_hp' => (int) $base['max_hp'],
                'mp' => (int) $base['mp'],
                'max_mp' => (int) $base['max_mp'],
                'attack' => (int) $base['attack'],
                'defense' => (int) $base['defense'],
                'attack_speed' => (float) $base['attack_speed'],
                'crit_chance' => (float) $base['crit_chance'],
                'crit_damage' => (float) $base['crit_damage'],
                'magic_level' => (int) $base['magic_level'],
                'hp_regen' => 0,
                'mp_regen' => 0,
                'gold' => 0,
                'stat_points' => 0,
                'highest_level' => 1,
                'equipment' => [],
            ]);

            $this->seedStarterSave($state, $character, $class);

            return (new CharacterResource($character))->resolve();
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload, Response::HTTP_CREATED);
    }

    public function destroy(Request $request): Response
    {
        $character = $request->attributes->get('character');
        $id = $character->id;

        DB::transaction(function () use ($id): void {
            DB::table('party_members')->where('character_id', $id)->delete();
            DB::table('guild_members')->where('character_id', $id)->delete();
            DB::table('guild_join_requests')->where('character_id', $id)->delete();
            DB::table('guild_boss_attempts')->where('character_id', $id)->delete();
            DB::table('guild_boss_contributions')->where('character_id', $id)->delete();

            DB::table('market_listings')->where('seller_id', $id)->delete();
            DB::table('market_sale_notifications')->where('seller_id', $id)->delete();

            DB::table('game_saves')->where('character_id', $id)->delete();
            DB::table('characters')->where('id', $id)->delete();
        });

        return response()->noContent();
    }

    private function seedStarterSave(CharacterStateService $state, Character $character, string $class): void
    {
        $weapon = self::STARTER_WEAPONS[$class];

        $starterItem = [
            'uuid' => $weapon['id'].'_'.Str::uuid()->toString(),
            'itemId' => $weapon['id'],
            'rarity' => 'common',
            'bonuses' => [
                'attack' => (int) $weapon['dmg_min'],
                'dmg_min' => (int) $weapon['dmg_min'],
                'dmg_max' => (int) $weapon['dmg_max'],
            ],
            'itemLevel' => 1,
            'upgradeLevel' => 0,
        ];

        $equipment = array_fill_keys(self::EQUIPMENT_SLOTS, null);
        $equipment['mainHand'] = $starterItem;

        $save = $state->lockedFor($character);
        $blob = $save->state;
        $blob['inventory'] = [
            'gold' => 0,
            'bag' => [],
            'equipment' => $equipment,
            'deposit' => [],
            'consumables' => ['hp_potion_sm' => 30, 'mp_potion_sm' => 30],
            'stones' => [],
            'arenaPoints' => 0,
        ];
        $save->state = $blob;
        $state->persist($save);
    }
}
