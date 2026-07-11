<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GuildController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guild (gildie) — prefix `api/v1` (dodaje bootstrap).
|--------------------------------------------------------------------------
| Autorytet: serwer liczy cały przepływ (koszt założenia z bloba, obrażenia
| bossa z GuildSystem, limit członków, przelew skarbca). Klient wysyła tylko
| intencje. Tożsamość AKTUALNEJ postaci bierze się z `{character}` (owns.character)
| — u usera z wieloma postaciami to jednoznacznie wskazuje, kto działa.
|
| Drugi parametr trasy `{guild}` / `{charId}` czytamy przez $request->route(...)
| — Laravel gubi wiązanie modelu przy 2+ parametrach.
*/

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        // Założenie gildii (aktualna postać = założyciel/lider; koszt z bloba).
        Route::post('/characters/{character}/guilds', [GuildController::class, 'create']);

        // Podgląd gildii (metadane + roster + prośby).
        Route::get('/characters/{character}/guilds/{guild}', [GuildController::class, 'show']);

        // Prośba o dołączenie (snapshot postaci z BAZY, nie z body).
        Route::post('/characters/{character}/guilds/{guild}/join', [GuildController::class, 'join']);

        // Lider akceptuje prośbę {charId} (403 gdy nie-lider; limit członków).
        Route::post('/characters/{character}/guilds/{guild}/accept/{charId}', [GuildController::class, 'accept']);

        // Opuszczenie gildii (lider → sukcesja lub rozwiązanie gdy pusto).
        Route::post('/characters/{character}/guilds/{guild}/leave', [GuildController::class, 'leave']);

        // Atak na bossa — SERWER liczy obrażenia/HP/tier/XP (GuildSystem).
        Route::post('/characters/{character}/guilds/{guild}/boss/damage', [GuildController::class, 'bossDamage']);

        // Skarbiec: wpłata (item z bag → skarbiec) / wypłata (skarbiec → bag).
        Route::post('/characters/{character}/guilds/{guild}/treasury/deposit', [GuildController::class, 'treasuryDeposit']);
        Route::post('/characters/{character}/guilds/{guild}/treasury/withdraw', [GuildController::class, 'treasuryWithdraw']);

        // Lider: wyrzucenie członka {charId} (403 nie-lider lub cel = lider).
        Route::post('/characters/{character}/guilds/{guild}/kick/{charId}', [GuildController::class, 'kick']);

        // Lider: odrzucenie prośby {charId} (403 nie-lider).
        Route::post('/characters/{character}/guilds/{guild}/reject/{charId}', [GuildController::class, 'reject']);

        // Lider: jawne rozwiązanie gildii (403 nie-lider) — pełna kaskada disband.
        Route::post('/characters/{character}/guilds/{guild}/disband', [GuildController::class, 'disbandGuild']);

        // Odbiór nagród za bossa — SERWER losuje i kredytuje (gold/kamienie/potiony/xp).
        Route::post('/characters/{character}/guilds/{guild}/boss/claim-reward', [GuildController::class, 'bossClaimReward']);

        // Widok bossa: stan tygodniowy + wkłady + próby (fetch-or-create).
        Route::get('/characters/{character}/guilds/{guild}/boss', [GuildController::class, 'bossView']);

        // Widok skarbca: przedmioty + logi (member-only).
        Route::get('/characters/{character}/guilds/{guild}/treasury', [GuildController::class, 'treasuryView']);
    });

    // Przeglądarka gildii — NIE wymaga postaci (tylko supabase.auth): lista + count + podsumowania.
    Route::get('/guilds', [GuildController::class, 'index']);
});
