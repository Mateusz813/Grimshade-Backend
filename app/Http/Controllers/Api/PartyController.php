<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Party\PartySystem;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Party;
use App\Models\PartyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorytatywne party co-op. Serwer:
 *  - bierze DZIAŁAJĄCĄ postać z tokenu ({character} + owns.character), NIGDY z body,
 *  - snapshot tożsamości członka (name/class/level) kopiuje z wiersza `characters`
 *    (nie ufa polom z body — front i tak wysyła tylko intencję),
 *  - wymusza inwariant: postać w co najwyżej JEDNYM party (+ UNIQUE character_id),
 *  - pojemność max 4 (PartySystem::MAX_PARTY_SIZE), hasło (plain-text) i gate poziomu
 *    liczy/waliduje serwer pod lockForUpdate na wierszu party (anty-race pojemności),
 *  - lider wychodzący → rozwiązanie party; ostatni członek → kasacja party,
 *  - handover: tylko lider → istniejący członek.
 *
 * ŚWIADOMIE POMINIĘTE (Faza 7): Realtime (Supabase channels) i party live-combat —
 * to warstwa czasu rzeczywistego, nie autorytet stanu.
 *
 * Odpowiedź: kształt IPartyWithMembers z partyApi.ts (snake_case; adaptToPartyInfo
 * i rowToMember konsumują dokładnie te pola). `password` NIGDY nie wychodzi — tylko
 * pochodne `has_password`.
 */
final class PartyController extends Controller
{
    /** POST /characters/{character}/parties — utwórz party (leader = działająca postać). */
    public function store(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:140'],
            'password' => ['nullable', 'string', 'max:100'],
            'isPublic' => ['nullable', 'boolean'],
            'minJoinLevel' => ['nullable', 'integer', 'min:1'],
        ]);

        $payload = DB::transaction(function () use ($character, $data): array {
            // Serializuje operacje party per-postać (anty-double-create).
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);

            // Inwariant: co najwyżej jedno party na postać (UNIQUE character_id w DB).
            if (PartyMember::query()->where('character_id', $fresh->id)->exists()) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Jesteś już w party — najpierw je opuść.');
            }

            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $name = "{$fresh->name}'s party";
            }

            $password = isset($data['password']) && $data['password'] !== ''
                ? (string) $data['password']
                : null;

            $minJoinLevel = isset($data['minJoinLevel']) && (int) $data['minJoinLevel'] > 1
                ? (int) $data['minJoinLevel']
                : 1;

            $party = Party::create([
                'leader_id' => $fresh->id,
                'name' => mb_substr($name, 0, 60),
                'description' => isset($data['description']) ? mb_substr((string) $data['description'], 0, 140) : null,
                'password' => $password,
                'max_members' => PartySystem::MAX_PARTY_SIZE,
                'is_public' => $data['isPublic'] ?? true,
                'min_join_level' => $minJoinLevel,
            ]);

            $this->addMember($party, $fresh, 'leader');

            return $this->snapshot($party);
        });

        return response()->json($payload, Response::HTTP_CREATED);
    }

    /** GET /characters/{character}/parties/{party} — roster + meta (bez hasła). */
    public function show(Request $request): JsonResponse
    {
        $party = Party::query()->find((string) $request->route('party'));
        if ($party === null) {
            abort(Response::HTTP_NOT_FOUND, 'Party nie istnieje.');
        }

        return response()->json($this->snapshot($party));
    }

    /** POST /characters/{character}/parties/{party}/join — dołącz (pojemność/hasło/poziom). */
    public function join(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $partyId = (string) $request->route('party');

        $data = $request->validate([
            'password' => ['nullable', 'string', 'max:100'],
        ]);

        $payload = DB::transaction(function () use ($character, $partyId, $data): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);

            // Lock party → serializuje równoległe dołączenia (anty-race pojemności).
            $party = Party::query()->lockForUpdate()->find($partyId);
            if ($party === null) {
                abort(Response::HTTP_NOT_FOUND, 'Party nie istnieje.');
            }

            // Już w jakimś party? Ten sam → idempotentny zwrot; inny → odmowa.
            $existing = PartyMember::query()->where('character_id', $fresh->id)->first();
            if ($existing !== null) {
                if ($existing->party_id === $party->id) {
                    return $this->snapshot($party);
                }
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Jesteś już w innym party — najpierw je opuść.');
            }

            // Hasło (plain-text; puste hasło = brak).
            if ($party->password !== null && $party->password !== ''
                && $party->password !== ($data['password'] ?? '')) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nieprawidłowe hasło.');
            }

            // Gate poziomu (1 = brak ograniczenia).
            $minLevel = (int) $party->min_join_level;
            if ($minLevel > 1 && (int) $fresh->level < $minLevel) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, "To party wymaga poziomu {$minLevel}+.");
            }

            // Pojemność (max 4) — liczona pod lockiem party.
            $count = PartyMember::query()->where('party_id', $party->id)->count();
            if (! PartySystem::canJoinParty($count)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Party jest pełne.');
            }

            $this->addMember($party, $fresh, 'member');

            return $this->snapshot($party);
        });

        return response()->json($payload);
    }

    /** POST /characters/{character}/parties/{party}/leave — opuść party. */
    public function leave(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $partyId = (string) $request->route('party');

        $payload = DB::transaction(function () use ($character, $partyId): array {
            $party = Party::query()->lockForUpdate()->find($partyId);
            if ($party === null) {
                abort(Response::HTTP_NOT_FOUND, 'Party nie istnieje.');
            }

            // Lider wychodzi → rozwiązanie party (kasujemy członków + party).
            if ($party->leader_id === $character->id) {
                PartyMember::query()->where('party_id', $party->id)->delete();
                $party->delete();

                return ['ok' => true, 'dissolved' => true, 'party' => null];
            }

            $member = PartyMember::query()
                ->where('party_id', $party->id)
                ->where('character_id', $character->id)
                ->first();
            if ($member === null) {
                abort(Response::HTTP_NOT_FOUND, 'Nie jesteś w tym party.');
            }
            $member->delete();

            // Ostatni członek zszedł → skasuj puste party.
            $dissolved = PartyMember::query()->where('party_id', $party->id)->doesntExist();
            if ($dissolved) {
                $party->delete();
            }

            return [
                'ok' => true,
                'dissolved' => $dissolved,
                'party' => $dissolved ? null : $this->snapshot($party),
            ];
        });

        return response()->json($payload);
    }

    /** POST /characters/{character}/parties/{party}/handover — przekaż dowodzenie (lider). */
    public function handover(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $partyId = (string) $request->route('party');

        $data = $request->validate([
            'newLeaderId' => ['required', 'string', 'max:64'],
        ]);

        $payload = DB::transaction(function () use ($character, $partyId, $data): array {
            $party = Party::query()->lockForUpdate()->find($partyId);
            if ($party === null) {
                abort(Response::HTTP_NOT_FOUND, 'Party nie istnieje.');
            }
            if ($party->leader_id !== $character->id) {
                abort(Response::HTTP_FORBIDDEN, 'Tylko lider może przekazać dowodzenie.');
            }

            $newLeaderId = (string) $data['newLeaderId'];
            if ($newLeaderId === $party->leader_id) {
                return $this->snapshot($party); // no-op — już jest liderem
            }

            // Nowy lider musi być członkiem TEGO party.
            $target = PartyMember::query()
                ->where('party_id', $party->id)
                ->where('character_id', $newLeaderId)
                ->first();
            if ($target === null) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nowy lider musi być członkiem party.');
            }

            // Role: stary lider → member, nowy → leader; leader_id na party.
            PartyMember::query()
                ->where('party_id', $party->id)
                ->where('character_id', $party->leader_id)
                ->update(['role' => 'member']);
            $target->role = 'leader';
            $target->save();

            $party->leader_id = $newLeaderId;
            $party->save();

            return $this->snapshot($party);
        });

        return response()->json($payload);
    }

    /** POST /characters/{character}/parties/{party}/kick — wyrzuć członka (tylko lider). */
    public function kick(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $partyId = (string) $request->route('party');

        $data = $request->validate([
            'memberRowId' => ['required', 'string', 'max:64'],
        ]);

        $payload = DB::transaction(function () use ($character, $partyId, $data): array {
            $party = Party::query()->lockForUpdate()->find($partyId);
            if ($party === null) {
                abort(Response::HTTP_NOT_FOUND, 'Party nie istnieje.');
            }
            if ($party->leader_id !== $character->id) {
                abort(Response::HTTP_FORBIDDEN, 'Tylko lider może wyrzucać z party.');
            }

            // Kasujemy członka po id WIERSZA (party_members.id), nie po character_id.
            $member = PartyMember::query()
                ->where('party_id', $party->id)
                ->where('id', (string) $data['memberRowId'])
                ->first();
            if ($member === null) {
                abort(Response::HTTP_NOT_FOUND, 'Nie ma takiego członka w tym party.');
            }

            // Nie można wyrzucić lidera ani samego siebie (lider = self tutaj).
            if ($member->character_id === $party->leader_id || $member->character_id === $character->id) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nie można wyrzucić lidera party.');
            }

            $member->delete();

            return $this->snapshot($party);
        });

        return response()->json($payload);
    }

    /** PUT /characters/{character}/parties/{party} — edytuj meta party (tylko lider). */
    public function update(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');
        $partyId = (string) $request->route('party');

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:140'],
            'password' => ['sometimes', 'nullable', 'string', 'max:100'],
            'isPublic' => ['sometimes', 'nullable', 'boolean'],
            'minJoinLevel' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $payload = DB::transaction(function () use ($character, $partyId, $data): array {
            $party = Party::query()->lockForUpdate()->find($partyId);
            if ($party === null) {
                abort(Response::HTTP_NOT_FOUND, 'Party nie istnieje.');
            }
            if ($party->leader_id !== $character->id) {
                abort(Response::HTTP_FORBIDDEN, 'Tylko lider może edytować party.');
            }

            // Aktualizujemy TYLKO pola faktycznie podane (sanityzacja/clamp jak w store).
            if (array_key_exists('name', $data)) {
                $name = trim((string) ($data['name'] ?? ''));
                if ($name !== '') { // pusta nazwa → zostaw dotychczasową
                    $party->name = mb_substr($name, 0, 60);
                }
            }
            if (array_key_exists('description', $data)) {
                $party->description = $data['description'] !== null
                    ? mb_substr((string) $data['description'], 0, 140)
                    : null;
            }
            if (array_key_exists('password', $data)) {
                // Puste hasło → NULL (brak bramki).
                $party->password = isset($data['password']) && $data['password'] !== ''
                    ? (string) $data['password']
                    : null;
            }
            if (array_key_exists('isPublic', $data) && $data['isPublic'] !== null) {
                $party->is_public = (bool) $data['isPublic'];
            }
            if (array_key_exists('minJoinLevel', $data) && $data['minJoinLevel'] !== null) {
                $party->min_join_level = (int) $data['minJoinLevel'] > 1 ? (int) $data['minJoinLevel'] : 1;
            }

            $party->save();

            return $this->snapshot($party);
        });

        return response()->json($payload);
    }

    /**
     * GET /parties — publiczna przeglądarka party (tylko `supabase.auth`, bez postaci).
     * Zwraca party is_public (lub NULL-legacy) NIEpełne, created_at desc, limit ~50.
     * Przy okazji GC pustych/stale party (zastępuje kliencki cleanupEmptyParties).
     */
    public function index(Request $request): JsonResponse
    {
        // GC: kasuj party bez członków (osierocone/rozwiązane) — porządkuje feed.
        $emptyIds = Party::query()->whereDoesntHave('members')->pluck('id');
        if ($emptyIds->isNotEmpty()) {
            Party::query()->whereIn('id', $emptyIds)->delete();
        }

        $parties = Party::query()
            ->where(function ($q): void {
                // is_public TRUE lub NULL (stare wiersze sprzed kolumny) — widoczne.
                $q->where('is_public', true)->orWhereNull('is_public');
            })
            ->withCount('members')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            // Pojemność: pokazuj tylko party, do których da się dołączyć.
            ->filter(fn (Party $p): bool => (int) $p->members_count < (int) $p->max_members)
            ->map(fn (Party $p): array => $this->snapshot($p))
            ->values()
            ->all();

        return response()->json($parties);
    }

    /**
     * GET /characters/{character}/parties/active — party, w którym jest DZIAŁAJĄCA
     * postać, albo null (boot hydration). Kształt jak snapshot (bez hasła).
     */
    public function active(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $member = PartyMember::query()->where('character_id', $character->id)->first();
        $party = $member !== null ? Party::query()->find($member->party_id) : null;

        if ($party === null) {
            // Symfony mapuje null → {}, więc wymuszamy literalny `null` (kontrakt bootu).
            return response()->json()->setData(null);
        }

        return response()->json($this->snapshot($party));
    }

    // ---- Helpery -----------------------------------------------------------

    /** Dopisuje członka ze snapshotem tożsamości z wiersza `characters`. */
    private function addMember(Party $party, Character $character, string $role): PartyMember
    {
        return PartyMember::create([
            'party_id' => $party->id,
            'character_id' => $character->id,
            'character_name' => $character->name,
            'character_class' => $character->class,
            'character_level' => (int) $character->level,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    /**
     * Kształt 1:1 z IPartyWithMembers (partyApi.ts) — snake_case, bez hasła.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Party $party): array
    {
        $members = PartyMember::query()
            ->where('party_id', $party->id)
            ->orderBy('joined_at')
            ->get()
            ->map(fn (PartyMember $m): array => [
                'id' => $m->id,
                'party_id' => $m->party_id,
                'character_id' => $m->character_id,
                'character_name' => $m->character_name,
                'character_class' => $m->character_class,
                'character_level' => (int) $m->character_level,
                'joined_at' => optional($m->joined_at)->toIso8601String(),
            ])
            ->all();

        return [
            'id' => $party->id,
            'leader_id' => $party->leader_id,
            'name' => $party->name,
            'description' => $party->description ?? '',
            'max_members' => (int) $party->max_members,
            'is_public' => (bool) $party->is_public,
            'has_password' => $party->password !== null && $party->password !== '',
            'min_join_level' => (int) $party->min_join_level,
            'created_at' => optional($party->created_at)->toIso8601String(),
            'members' => $members,
        ];
    }
}
