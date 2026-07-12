<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parties')) {
            Schema::create('parties', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('leader_id')->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('password')->nullable();
                $table->integer('max_members')->default(4);
                $table->boolean('is_public')->default(true);
                $table->integer('min_join_level')->default(1);
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('party_members')) {
            Schema::create('party_members', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('party_id')->index();
                $table->uuid('character_id')->unique();
                $table->string('character_name')->default('');
                $table->string('character_class')->default('');
                $table->integer('character_level')->default(1);
                $table->string('role')->nullable();
                $table->timestampTz('joined_at')->nullable();
            });
        }
    }

    public function down(): void
    {
    }
};
