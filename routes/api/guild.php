<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GuildController;
use Illuminate\Support\Facades\Route;

Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('owns.character')->group(function (): void {
        Route::post('/characters/{character}/guilds', [GuildController::class, 'create']);

        Route::get('/characters/{character}/guilds/{guild}', [GuildController::class, 'show']);

        Route::post('/characters/{character}/guilds/{guild}/join', [GuildController::class, 'join']);

        Route::post('/characters/{character}/guilds/{guild}/accept/{charId}', [GuildController::class, 'accept']);

        Route::post('/characters/{character}/guilds/{guild}/leave', [GuildController::class, 'leave']);

        Route::post('/characters/{character}/guilds/{guild}/boss/damage', [GuildController::class, 'bossDamage']);

        Route::post('/characters/{character}/guilds/{guild}/treasury/deposit', [GuildController::class, 'treasuryDeposit']);
        Route::post('/characters/{character}/guilds/{guild}/treasury/withdraw', [GuildController::class, 'treasuryWithdraw']);

        Route::post('/characters/{character}/guilds/{guild}/kick/{charId}', [GuildController::class, 'kick']);

        Route::post('/characters/{character}/guilds/{guild}/reject/{charId}', [GuildController::class, 'reject']);

        Route::post('/characters/{character}/guilds/{guild}/disband', [GuildController::class, 'disbandGuild']);

        Route::post('/characters/{character}/guilds/{guild}/boss/claim-reward', [GuildController::class, 'bossClaimReward']);

        Route::get('/characters/{character}/guilds/{guild}/boss', [GuildController::class, 'bossView']);

        Route::get('/characters/{character}/guilds/{guild}/treasury', [GuildController::class, 'treasuryView']);
    });

    Route::get('/guilds', [GuildController::class, 'index']);
});
