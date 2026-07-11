<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Death;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Globalny feed śmierci. Odczyt publiczny (dla zalogowanych). Zapis: tożsamość
 * postaci (name/class/level) SERWER bierze z bazy — klient podaje tylko źródło
 * śmierci (co go zabiło). Zamyka fałszowanie „zabił mnie boss lvl 1000".
 */
final class DeathController extends Controller
{
    /** @var list<string> */
    private const SOURCES = ['monster', 'dungeon', 'boss', 'transform', 'raid'];

    public function index(Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->query('limit', '100')));

        return response()->json(
            Death::query()->orderByDesc('died_at')->limit($limit)->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'source' => ['required', 'string', 'in:'.implode(',', self::SOURCES)],
            'source_name' => ['required', 'string', 'max:120'],
            'source_level' => ['required', 'integer', 'min:0'],
            'result' => ['sometimes', 'string', 'in:killed,fled'],
        ]);

        $death = Death::create([
            'character_id' => $character->id,
            // Tożsamość z SERWERA (nie z body):
            'character_name' => $character->name,
            'character_class' => $character->class,
            'character_level' => $character->level,
            'source' => $data['source'],
            'source_name' => $data['source_name'],
            'source_level' => $data['source_level'],
            // killed = realna śmierć (HP=0), fled = ucieczka (Ucieknij). Domyślnie killed.
            'result' => $data['result'] ?? 'killed',
            'died_at' => now(),
        ]);

        return response()->json($death, 201);
    }
}
