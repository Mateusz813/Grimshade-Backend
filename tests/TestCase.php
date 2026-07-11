<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * BEZWZGLĘDNA izolacja testów od realnej bazy.
     *
     * Wymuszamy sqlite in-memory ZANIM Laravel wczyta `.env` — immutable Dotenv
     * nie nadpisze już ustawionych zmiennych, więc `DB_CONNECTION=pgsql` z
     * `.env`/kontenera (realna Supabase) NIGDY nie dotrze do testów. Bez tego
     * `RefreshDatabase` mógłby odpalić `migrate:fresh` na PRODUKCJI. Krytyczne.
     */
    public function createApplication(): Application
    {
        foreach ([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_HOST' => '127.0.0.1',
            'DB_SSLMODE' => 'prefer',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
