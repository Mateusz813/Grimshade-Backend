<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_listings')) {
            return;
        }

        Schema::create('market_listings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('seller_id')->index();
            $table->string('seller_name');
            $table->string('kind')->default('item');
            $table->string('item_id');
            $table->string('item_name')->default('');
            $table->integer('item_level')->default(1);
            $table->string('rarity')->default('common');
            $table->string('slot')->default('');
            $table->bigInteger('price');
            $table->integer('quantity')->default(1);
            $table->integer('quantity_initial')->default(1);
            $table->json('bonuses')->nullable();
            $table->integer('upgrade_level')->default(0);
            $table->timestampTz('listed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
    }
};
