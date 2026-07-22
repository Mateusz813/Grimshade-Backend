<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Death;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class DeathController extends Controller
{
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
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'source' => ['required', 'string', 'in:'.implode(',', self::SOURCES)],
            'source_name' => ['required', 'string', 'max:120'],
            'source_level' => ['required', 'integer', 'min:0'],
            'result' => ['sometimes', 'string', 'in:killed,fled'],
        ]);

        $row = [
            'character_id' => $character->id,
            'character_name' => $character->name,
            'character_class' => $character->class,
            'character_level' => $character->level,
            'source' => $data['source'],
            'source_name' => $data['source_name'],
            'source_level' => $data['source_level'],
            'died_at' => now(),
        ];
        if (Schema::hasColumn('character_deaths', 'result')) {
            $row['result'] = $data['result'] ?? 'killed';
        }

        $death = Death::create($row);

        return response()->json($death, 201);
    }
}
