<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('guilds')) {
            Schema::create('guilds', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('tag', 8);
                $table->string('logo')->default('');
                $table->string('color')->default('');
                $table->uuid('leader_id')->index();
                $table->integer('level')->default(1);
                $table->bigInteger('xp')->default(0);
                $table->integer('boss_tier')->default(1);
                $table->integer('member_cap')->default(20);
                $table->timestampTz('created_at')->nullable();
                $table->timestampTz('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('guild_members')) {
            Schema::create('guild_members', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('guild_id')->index();
                $table->uuid('character_id')->index();
                $table->string('character_name');
                $table->string('character_class');
                $table->integer('character_level')->default(1);
                $table->integer('character_transform_tier')->default(0);
                $table->timestampTz('joined_at')->nullable();
            });
        }

        if (! Schema::hasTable('guild_join_requests')) {
            Schema::create('guild_join_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('guild_id')->index();
                $table->uuid('character_id')->index();
                $table->string('character_name');
                $table->string('character_class');
                $table->integer('character_level')->default(1);
                $table->timestampTz('requested_at')->nullable();
            });
        }

        if (! Schema::hasTable('guild_boss_state')) {
            Schema::create('guild_boss_state', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('guild_id')->index();
                $table->string('week_start');
                $table->integer('boss_tier')->default(1);
                $table->bigInteger('boss_max_hp');
                $table->bigInteger('boss_current_hp');
                $table->boolean('boss_killed')->default(false);
                $table->uuid('current_attacker_id')->nullable();
                $table->timestampTz('created_at')->nullable();
                $table->timestampTz('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('guild_boss_attempts')) {
            Schema::create('guild_boss_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('guild_id')->index();
                $table->uuid('character_id')->index();
                $table->string('character_name');
                $table->string('attempt_date');
                $table->bigInteger('damage_dealt')->default(0);
                $table->timestampTz('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('guild_boss_contributions')) {
            Schema::create('guild_boss_contributions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('guild_id')->index();
                $table->uuid('character_id')->index();
                $table->string('week_start');
                $table->bigInteger('total_damage')->default(0);
                $table->boolean('rewards_claimed')->default(false);
                $table->text('rewards_json')->nullable();
                $table->timestampTz('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('guild_treasury_items')) {
            Schema::create('guild_treasury_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('guild_id')->index();
                $table->text('item_data');
                $table->uuid('deposited_by');
                $table->string('deposited_by_name');
                $table->timestampTz('deposited_at')->nullable();
            });
        }

        if (! Schema::hasTable('guild_treasury_logs')) {
            Schema::create('guild_treasury_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('guild_id')->index();
                $table->string('action');
                $table->uuid('character_id');
                $table->string('character_name');
                $table->string('item_name');
                $table->text('item_data')->nullable();
                $table->timestampTz('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
    }
};
