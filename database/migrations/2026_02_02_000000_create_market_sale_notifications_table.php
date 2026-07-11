<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `market_sale_notifications` — powiadomienie sprzedawcy o sprzedaży (kształt
 * z src/api/v1/marketApi.ts). Tworzone atomowo w transakcji kupna; gold_received
 * to kwota NETTO (po 5% podatku marketowym), którą sprzedawca faktycznie dostał.
 *
 * IDEMPOTENTNA: guard hasTable → no-op na Supabase. down() no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_sale_notifications')) {
            return;
        }

        Schema::create('market_sale_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('seller_id')->index();
            $table->string('item_id');
            $table->string('item_name')->default('');
            $table->string('rarity')->default('common');
            $table->integer('quantity_sold')->default(1);
            $table->bigInteger('gold_received')->default(0);
            $table->timestampTz('sold_at')->nullable();
            $table->boolean('seen')->default(false);
        });
    }

    public function down(): void
    {
        // Świadomie no-op — chroni realną tabelę Supabase.
    }
};
