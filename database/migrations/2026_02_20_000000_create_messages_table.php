<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages — globalny czat miasta / system / party / guild / PM (kształt z realnej
 * Supabase, kolumny zgodne z src/api/v1/chatApi.ts: channel, character_name,
 * character_class, character_level, content, user_id, created_at).
 *
 * UWAGA: tabela NIE ma character_id — wiersz linkuje przez character_name +
 * user_id (patrz CLAUDE.md front: cleanup czatu leci po tych dwóch kolumnach).
 *
 * IDEMPOTENTNA: na Supabase tabela już istnieje → no-op; służy testom (sqlite).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages')) {
            return;
        }

        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('channel');
            $table->string('character_name');
            $table->string('character_class')->nullable();
            $table->integer('character_level')->nullable();
            $table->text('content');
            $table->timestampTz('created_at')->nullable();

            // Feed czyta „ostatnie N po kanale" — indeks pod tę ścieżkę.
            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        // no-op — chroni realną tabelę Supabase.
    }
};
