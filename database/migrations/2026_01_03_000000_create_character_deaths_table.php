<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('character_deaths')) {
            return;
        }

        Schema::create('character_deaths', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('character_id')->index();
            $table->string('character_name');
            $table->string('character_class');
            $table->integer('character_level');
            $table->string('source');
            $table->string('source_name');
            $table->integer('source_level');
            $table->timestampTz('died_at')->nullable();
        });
    }

    public function down(): void {}
};
