<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void {}
};
