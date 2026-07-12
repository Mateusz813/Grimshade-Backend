<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RankingController extends Controller
{
    private const DPS_CAP = 1_000_000_000_000;

    public function dpsRecord(Request $request): JsonResponse
    {
        $character = $request->attributes->get('character');
        $data = $request->validate([
            'dps' => ['required', 'integer', 'min:1', 'max:'.self::DPS_CAP],
            'inParty' => ['required', 'boolean'],
            'composition' => ['nullable', 'string', 'max:4096'],
            'requestId' => ['required', 'string', 'max:64'],
        ]);

        $cacheKey = "rankings.dpsRecord.{$character->id}.{$data['requestId']}";
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        $payload = DB::transaction(function () use ($character, $data): array {
            $fresh = Character::query()->lockForUpdate()->findOrFail($character->id);

            $dps = (int) $data['dps'];
            if ($data['inParty']) {
                if ($dps > (int) $fresh->best_dps5_party) {
                    $fresh->best_dps5_party = $dps;
                    $fresh->best_dps5_party_composition = $data['composition'] ?? null;
                }
            } else {
                if ($dps > (int) $fresh->best_dps5_solo) {
                    $fresh->best_dps5_solo = $dps;
                }
            }
            $fresh->save();

            return (new CharacterResource($fresh))->resolve();
        });

        Cache::put($cacheKey, $payload, now()->addHour());

        return response()->json($payload);
    }
}
