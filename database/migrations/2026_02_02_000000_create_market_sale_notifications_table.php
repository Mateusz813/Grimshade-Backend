<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
    }
};
