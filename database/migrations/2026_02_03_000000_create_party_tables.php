<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `parties` + `party_members` — party co-op (kształt 1:1 z src/api/v1/partyApi.ts).
 *
 * IDEMPOTENTNA: na realnej Supabase tabele istnieją → no-op (guard hasTable).
 * Służy testom (sqlite in-memory) oraz jako dokumentacja schematu. down() = no-op,
 * żeby migrate:rollback nie ruszył realnych tabel.
 *
 * Invariant: postać jest w co najwyżej JEDNYM party (UNIQUE character_id) — front
 * (partyApi.createParty) polega na tym ograniczeniu, czyszcząc stare wiersze przed
 * insertem. Serwer wymusza go dodatkowo w PartyController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parties')) {
            Schema::create('parties', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('leader_id')->index();       // CHARACTER id lidera
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('password')->nullable();   // plain-text (gated coordination, NIE auth)
                $table->integer('max_members')->default(4);
                $table->boolean('is_public')->default(true);
                $table->integer('min_join_level')->default(1);
                $table->timestampsTz();                   // created_at + updated_at
            });
        }

        if (! Schema::hasTable('party_members')) {
            Schema::create('party_members', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('party_id')->index();
                $table->uuid('character_id')->unique();   // postać w co najwyżej jednym party
                $table->string('character_name')->default('');
                $table->string('character_class')->default('');
                $table->integer('character_level')->default(1);
                $table->string('role')->nullable();       // 'leader' | 'member' (nigdy nieczytane przez front)
                $table->timestampTz('joined_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Świadomie no-op — chroni realne tabele Supabase.
    }
};
