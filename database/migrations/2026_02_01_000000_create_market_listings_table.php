<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `market_listings` — aukcje gracz→gracz (kształt z realnej Supabase, kolumny
 * 1:1 z src/api/v1/marketApi.ts). Escrow: item schodzi z bloba game_saves i
 * ląduje TU (jeden autorytatywny wiersz), sprzedaż transferuje go kupującemu.
 *
 * IDEMPOTENTNA: na Supabase tabela istnieje → no-op (guard hasTable). Służy
 * testom (sqlite in-memory) oraz jako dokumentacja schematu. down() = no-op,
 * żeby migrate:rollback nie ruszył realnej tabeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_listings')) {
            return;
        }

        Schema::create('market_listings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('seller_id')->index();          // CHARACTER id sprzedawcy
            $table->string('seller_name');
            $table->string('kind')->default('item');     // item|potion|elixir|stone|arena_points|spell_chest
            $table->string('item_id');
            $table->string('item_name')->default('');
            $table->integer('item_level')->default(1);
            $table->string('rarity')->default('common');
            $table->string('slot')->default('');
            $table->bigInteger('price');                 // per-unit dla stacków, pełna dla item
            $table->integer('quantity')->default(1);
            $table->integer('quantity_initial')->default(1);
            $table->json('bonuses')->nullable();
            $table->integer('upgrade_level')->default(0);
            $table->timestampTz('listed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Świadomie no-op — chroni realną tabelę Supabase.
    }
};
