<?php

namespace Tests\Concerns;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Migrates the test schema once per run and seeds roles/permissions/sequences once (outside any test
 * transaction, because seeding takes seconds). Every test then runs inside a transaction that is rolled back.
 *
 * Note: Laravel calls afterRefreshingDatabase() before EVERY test, so seeding must hook into the one-time
 * migration step instead.
 */
trait RefreshesSeededDatabase
{
    use RefreshDatabase {
        migrateDatabases as private baseMigrateDatabases;
    }

    protected function migrateDatabases()
    {
        $this->baseMigrateDatabases();

        $this->seed(DatabaseSeeder::class);
    }
}
