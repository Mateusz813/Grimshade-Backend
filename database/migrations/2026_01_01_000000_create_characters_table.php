<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('characters')) {
            return;
        }

        Schema::create('characters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('name');
            $table->string('class');

            $table->integer('level')->default(1);
            $table->bigInteger('xp')->default(0);
            $table->integer('hp')->default(0);
            $table->integer('max_hp')->default(0);
            $table->integer('mp')->default(0);
            $table->integer('max_mp')->default(0);
            $table->integer('attack')->default(0);
            $table->integer('defense')->default(0);
            $table->float('attack_speed')->default(0);
            $table->float('crit_chance')->default(0);
            $table->float('crit_damage')->default(0);
            $table->integer('magic_level')->default(0);
            $table->float('hp_regen')->default(0);
            $table->float('mp_regen')->default(0);
            $table->bigInteger('gold')->default(0);
            $table->integer('stat_points')->default(0);
            $table->integer('highest_level')->default(1);
            $table->json('equipment')->nullable();

            $table->integer('arena_kills')->default(0);
            $table->integer('arena_deaths')->default(0);
            $table->string('arena_league')->default('bronze');
            $table->integer('arena_league_points')->default(0);
            $table->integer('mastery_points')->default(0);
            $table->integer('quests_oneshot_done')->default(0);
            $table->integer('quests_daily_done')->default(0);
            $table->integer('market_items_sold')->default(0);
            $table->integer('market_items_bought')->default(0);
            $table->integer('item_upgrades_done')->default(0);
            $table->integer('skill_upgrades_done')->default(0);
            $table->integer('best_dps5_solo')->default(0);
            $table->integer('best_dps5_party')->default(0);
            $table->bigInteger('market_gold_earned')->default(0);
            $table->bigInteger('market_gold_spent')->default(0);
            $table->text('best_dps5_party_composition')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
    }
};
