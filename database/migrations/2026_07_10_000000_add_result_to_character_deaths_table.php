<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * character_deaths.result — killed|fled (feed śmierci: „potwór zabił" vs
 * „potwór przegnał"). Kształt z realnej Supabase, gdzie klient wstawiał tę
 * kolumnę bezpośrednio → w prod już ISTNIEJE. IDEMPOTENTNA: kolumna obecna →
 * no-op; służy testom (sqlite) żeby schemat miał tę kolumnę.
 */
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
        // no-op — chroni realną tabelę Supabase.
    }
};
