<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->timestampTz('offline_entered_at')->nullable();
            $table->string('entry_source')->nullable();
            $table->uuid('last_online_user_id')->nullable();
        });
    }

    public function down(): void
    {
    }
};
