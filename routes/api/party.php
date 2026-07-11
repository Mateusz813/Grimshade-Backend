<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PartyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Party (co-op) — moduł tras domeny party
|--------------------------------------------------------------------------
| Ładowane przez bootstrap (routes/api/*.php) z prefiksem `api/v1`. Bez
| ustawiania prefixu tutaj. Autoryzacja: `supabase.auth`; per-postać dokłada
| `owns.character` — tożsamość działającej postaci pochodzi z tokenu
| (owns.character wstawia ją do attributes.character), NIGDY z body.
|
| Core (Faza 7 = Realtime/live-combat świadomie POMINIĘTE): create (max 4),
| join, leave, handover (tylko lider), show. SERWER waliduje pojemność, hasło,
| gate poziomu i własność — klient wysyła jedynie intencje.
|
| {party} czytamy przez $request->route('party') — Laravel gubi wiązanie przy
| 2 parametrach trasy (znany problem, jak {listing} w market.php).
*/

Route::middleware('supabase.auth')->group(function (): void {
    // Publiczna przeglądarka party — BEZ postaci (tylko auth). Zwraca niepełne,
    // publiczne party (created_at desc, ~50) + serwerowy GC pustych party.
    Route::get('/parties', [PartyController::class, 'index']);

    Route::middleware('owns.character')->group(function (): void {
        // Utwórz party (leaderem zostaje działająca postać; max 4).
        Route::post('/characters/{character}/parties', [PartyController::class, 'store']);

        // Aktywne party działającej postaci (boot hydration; null gdy brak).
        // MUSI być PRZED trasą {party}, inaczej wildcard złapie „active" jako id.
        Route::get('/characters/{character}/parties/active', [PartyController::class, 'active']);

        // Podgląd party (roster + meta; hasło NIE wychodzi).
        Route::get('/characters/{character}/parties/{party}', [PartyController::class, 'show']);

        // Edytuj meta party (tylko lider): name/description/password/isPublic/minJoinLevel.
        Route::put('/characters/{character}/parties/{party}', [PartyController::class, 'update']);

        // Dołącz (walidacja: pojemność 4, hasło, min. poziom).
        Route::post('/characters/{character}/parties/{party}/join', [PartyController::class, 'join']);

        // Opuść (lider → rozwiązanie party; ostatni członek → kasacja party).
        Route::post('/characters/{character}/parties/{party}/leave', [PartyController::class, 'leave']);

        // Przekaż dowodzenie (tylko lider → istniejący członek).
        Route::post('/characters/{character}/parties/{party}/handover', [PartyController::class, 'handover']);

        // Wyrzuć członka po id wiersza (tylko lider; nie lidera/siebie).
        Route::post('/characters/{character}/parties/{party}/kick', [PartyController::class, 'kick']);
    });
});
