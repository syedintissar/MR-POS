<?php

namespace Tests\Concerns;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * For tests that run external programs (mariadb-dump / mariadb import) or restore a whole database.
 * Those programs use their own connection, so they can only see COMMITTED data: the usual
 * "wrap every test in a transaction" approach does not work. Instead the schema is built and seeded once
 * per run and the data tables are emptied before and after each test.
 */
trait UsesCommittedDatabase
{
    protected function setUpCommittedDatabase(): void
    {
        // Rebuild if this is the first test of the run, or an earlier test left the schema damaged or missing.
        if (! RefreshDatabaseState::$migrated || ! Schema::hasTable('document_sequences') || ! Schema::hasTable('backups')) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->seed(DatabaseSeeder::class);
            RefreshDatabaseState::$migrated = true;
        }

        $this->emptyDataTables();
    }

    protected function tearDownCommittedDatabase(): void
    {
        if (Schema::hasTable('document_sequences')) {
            $this->emptyDataTables();
        }
    }

    /** Everything except reference data (roles, permissions, document numbering) and the migrations table. */
    protected function emptyDataTables(): void
    {
        $keep = ['migrations', 'permissions', 'roles', 'role_has_permissions', 'document_sequences', 'units', 'categories', 'brands', 'payment_methods'];
        $db = config('database.connections.'.config('database.default').'.database');

        $tables = DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = "BASE TABLE"', [$db]);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $row) {
            if (! in_array($row->t, $keep, true)) {
                DB::statement('TRUNCATE TABLE `'.$row->t.'`'); // TRUNCATE does not fire the append-only triggers
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        DB::table('document_sequences')->update(['next_number' => 1, 'current_year' => null]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
