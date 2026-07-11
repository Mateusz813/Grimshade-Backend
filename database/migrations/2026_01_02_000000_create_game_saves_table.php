<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela `game_saves` — blob stanu gry (kształt z realnej bazy Supabase:
 * id, character_id, user_id, state, updated_at, offline_entered_at,
 * entry_source, last_online_user_id).
 *
 * IDEMPOTENTNA: na Supabase tabela istnieje → no-op. Służy testom (sqlite).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('game_saves')) {
            return;
        }

        Schema::create('game_saves', function (Blueprint $table): void {
            $table->id();
            $table->uuid('user_id')->index();
            $table->uuid('character_id')->unique();
            $table->json('state');
            $table->timestampTz('updated_at')->nullable();
            // Kolumny session_offline_migration.sql (scaffolding offline):
            $table->timestampTz('offline_entered_at')->nullable();
            $table->string('entry_source')->nullable();
            $table->uuid('last_online_user_id')->nullable();
        });
    }

    public function down(): void
    {
        // Świadomie no-op — chroni realną tabelę Supabase.
    }
};
