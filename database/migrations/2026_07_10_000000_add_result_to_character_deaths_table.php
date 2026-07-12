<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('character_deaths')) {
            return;
        }

        if (Schema::hasColumn('character_deaths', 'result')) {
            return;
        }

        Schema::table('character_deaths', function (Blueprint $table): void {
            $table->string('result')->default('killed');
        });
    }

    public function down(): void
    {
    }
};
