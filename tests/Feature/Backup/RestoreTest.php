<?php

namespace Tests\Feature\Backup;

use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\RestoreService;
use App\Services\Backup\ToolLocator;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RestoreTest extends BackupTestCase
{
    private function restorer(): RestoreService
    {
        return app(RestoreService::class);
    }

    /** Creates the "before" world, backs it up, then changes the world. Returns [backup, admin]. */
    private function backupThenChangeEverything(): array
    {
        $admin = $this->admin();
        User::factory()->withRole('Cashier')->create(['username' => 'keeper', 'name' => 'Keeper']);
        app(SettingsService::class)->set('shop.name', 'Shop BEFORE');
        $this->upload('products/p1.jpg', 'ORIGINAL-IMAGE');

        $backup = $this->service()->create(Backup::TIER_MANUAL, $admin);

        // ... then the "bad day": a user vanishes, another appears, a setting and a file change.
        User::where('username', 'keeper')->delete();
        User::factory()->withRole('Cashier')->create(['username' => 'newcomer']);
        app(SettingsService::class)->set('shop.name', 'Shop AFTER');
        $this->upload('products/p1.jpg', 'CHANGED-IMAGE');
        $this->upload('products/new.jpg', 'NEW-IMAGE');

        return [$backup, $admin];
    }

    public function test_restore_puts_the_data_and_files_back_and_keeps_a_safety_copy_of_the_current_state(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();

        $row = $this->restorer()->restore($this->zipPath($backup), $admin, null, maintenance: false, source: $backup);

        // Data is exactly as it was at backup time.
        $this->assertTrue(User::where('username', 'keeper')->exists());
        $this->assertFalse(User::where('username', 'newcomer')->exists());
        $this->assertSame('Shop BEFORE', app(SettingsService::class)->string('shop.name'));
        $this->assertTrue(User::where('username', 'keeper')->first()->hasRole('Cashier'), 'Role assignments come back too.');

        // Uploaded files are as they were; the files that were there are kept aside, not deleted.
        $live = config('mrpos.files_path');
        $this->assertSame('ORIGINAL-IMAGE', file_get_contents($live.'/products/p1.jpg'));
        $this->assertFileDoesNotExist($live.'/products/new.jpg');
        $aside = File::directories(dirname($live));
        $this->assertTrue(collect($aside)->contains(fn ($d) => str_contains($d, 'private.pre-restore-')), 'Previous files are moved aside.');

        // The restore is recorded, audited and logged.
        $this->assertSame('success', $row->status);
        $this->assertNotNull($row->safety_backup_filename);
        $this->assertDatabaseHas('backup_restores', ['status' => 'success', 'source_filename' => $backup->filename]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'restore_performed']);
        $this->assertStringContainsString('restore success', file_get_contents(storage_path('logs/restore.log')));

        // The safety backup exists on disk and is re-registered in the (older) restored backups table.
        $safety = Backup::query()->where('tier', 'safety')->first();
        $this->assertNotNull($safety);
        $this->assertFileExists($this->zipPath($safety));
        $this->assertSame($row->safety_backup_filename, $safety->filename);

        // The backup we restored from is still listed as a good backup, not as an interrupted run.
        $this->assertSame(0, Backup::query()->where('status', 'running')->count());
        $this->assertTrue(Backup::query()->where('tier', 'manual')->where('status', 'success')->exists());
    }

    public function test_the_safety_copy_really_contains_the_state_before_the_restore_so_a_restore_can_be_undone(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();
        $this->restorer()->restore($this->zipPath($backup), $admin, null, maintenance: false, source: $backup);

        $safety = Backup::query()->where('tier', 'safety')->firstOrFail();
        $entries = $this->readZip($this->zipPath($safety));
        $this->assertStringContainsString('newcomer', $entries['database.sql'], 'The safety backup holds the pre-restore data.');
        $this->assertStringNotContainsString("'keeper'", $entries['database.sql']);

        // Undo: restore the safety copy and we are back in the "after" world.
        $this->restorer()->restore($this->zipPath($safety), $admin, null, maintenance: false, source: $safety);

        $this->assertFalse(User::where('username', 'keeper')->exists());
        $this->assertTrue(User::where('username', 'newcomer')->exists());
        $this->assertSame('Shop AFTER', app(SettingsService::class)->string('shop.name'));
        $this->assertSame('CHANGED-IMAGE', file_get_contents(config('mrpos.files_path').'/products/p1.jpg'));
    }

    public function test_everyone_is_signed_out_by_a_restore(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);

        $this->restorer()->restore($this->zipPath($backup), $admin, null, maintenance: false, source: $backup);

        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_a_restore_that_fails_midway_puts_the_current_data_back_automatically(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();

        // Simulate the live import failing after the tables were already wiped (disk full, killed process, ...).
        $live = config('database.connections.'.config('database.default').'.database');
        $this->app->bind(DatabaseDumper::class, fn ($app) => new class($app->make(ToolLocator::class), $live) extends DatabaseDumper
        {
            public bool $failed = false;

            public function __construct(ToolLocator $tools, private string $liveDb)
            {
                parent::__construct($tools);
            }

            public function import(string $sqlFile, string $database, string $workDir): void
            {
                if ($database === $this->liveDb && ! $this->failed) {
                    $this->failed = true;
                    throw new BackupException('simulated failure in the middle of the import');
                }
                parent::import($sqlFile, $database, $workDir);
            }
        });
        $this->app->forgetInstance(BackupService::class);
        $this->app->forgetInstance(RestoreService::class);

        try {
            app(RestoreService::class)->restore($this->zipPath($backup), $admin, null, maintenance: false, source: $backup);
            $this->fail('Expected the restore to fail.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('put back automatically', $e->getMessage());
            $this->assertStringContainsString('simulated failure', $e->getMessage());
        }

        // The data is exactly as it was BEFORE the restore attempt (the "after" world), not wiped and not half-restored.
        $this->assertTrue(User::where('username', 'newcomer')->exists());
        $this->assertFalse(User::where('username', 'keeper')->exists());
        $this->assertSame('Shop AFTER', app(SettingsService::class)->string('shop.name'));
        $this->assertGreaterThan(80, DB::table('permissions')->count(), 'Reference data is intact.');

        $this->assertDatabaseHas('backup_restores', ['status' => 'rolled_back']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'restore_rolled_back']);
    }

    public function test_a_backup_made_by_a_newer_version_is_refused_before_anything_changes(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();
        $zip = $this->zipPath($backup);
        $this->rewriteZip($zip, function ($e) {
            $m = json_decode($e['manifest.json'], true);
            $m['migrations'][] = '2099_01_01_000000_a_future_change';
            $e['manifest.json'] = json_encode($m);

            return $e;
        });

        try {
            $this->restorer()->restore($zip, $admin, null, maintenance: false);
            $this->fail('Expected a refusal.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('newer version', $e->getMessage());
        }

        $this->assertTrue(User::where('username', 'newcomer')->exists(), 'Nothing was changed.');
        $this->assertSame(0, Backup::query()->where('tier', 'safety')->count(), 'No safety backup was needed because nothing was touched.');
    }

    public function test_a_corrupted_backup_is_refused_and_nothing_changes(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();
        $zip = $this->zipPath($backup);
        $bytes = file_get_contents($zip);
        $bytes[(int) (strlen($bytes) / 3)] = $bytes[(int) (strlen($bytes) / 3)] === 'A' ? 'B' : 'A';
        file_put_contents($zip, $bytes);

        try {
            $this->restorer()->restore($zip, $admin, null, maintenance: false, source: $backup);
            $this->fail('Expected a refusal.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('failed verification', $e->getMessage());
        }

        $this->assertTrue(User::where('username', 'newcomer')->exists());
        $this->assertSame('Shop AFTER', app(SettingsService::class)->string('shop.name'));
    }

    public function test_a_backup_with_an_unsafe_file_path_is_rejected_and_writes_nothing_outside_the_work_folder(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();
        $zip = $this->zipPath($backup);
        $this->rewriteZip($zip, fn ($e) => $e + ['files/../../evil.txt' => 'pwned']);

        $this->expectException(BackupException::class);
        try {
            $this->restorer()->restore($zip, $admin, null, maintenance: false);
        } finally {
            $this->assertFileDoesNotExist(dirname($this->service()->root()).'/evil.txt');
            $this->assertFileDoesNotExist($this->sandbox.'/evil.txt');
            $this->assertTrue(User::where('username', 'newcomer')->exists());
        }
    }

    public function test_if_the_safety_backup_cannot_be_made_the_restore_is_cancelled_untouched(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();

        // Make backups impossible from now on: the dump tool is gone. (preflight still works because it only imports.)
        config(['mrpos.backup.dump_bin' => 'C:\\does-not-exist\\mariadb-dump.exe']);

        try {
            $this->restorer()->restore($this->zipPath($backup), $admin, null, maintenance: false, source: $backup);
            $this->fail('Expected the restore to be cancelled.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('safety backup', $e->getMessage());
            $this->assertStringContainsString('cancelled before any change', $e->getMessage());
        }

        $this->assertTrue(User::where('username', 'newcomer')->exists(), 'Data untouched.');
        $this->assertSame('CHANGED-IMAGE', file_get_contents(config('mrpos.files_path').'/products/p1.jpg'));
    }

    public function test_an_encrypted_backup_restores_with_its_password(): void
    {
        $admin = $this->admin();
        app(SettingsService::class)->setMany(['backup.encryption_enabled' => true, 'backup.encryption_password' => 'S3cret-backup-pw']);
        User::factory()->create(['username' => 'before.user']);
        $backup = $this->service()->create(Backup::TIER_MANUAL, $admin);
        User::where('username', 'before.user')->delete();

        $this->restorer()->restore($this->zipPath($backup), $admin, null, maintenance: false, source: $backup);

        $this->assertTrue(User::where('username', 'before.user')->exists());
    }

    public function test_the_app_can_be_restored_onto_an_empty_database_as_on_a_new_pc(): void
    {
        $admin = $this->admin();
        User::factory()->withRole('Manager')->create(['username' => 'veteran']);
        $backup = $this->service()->create(Backup::TIER_MANUAL, $admin);
        $zip = $this->zipPath($backup);

        // Brand-new machine: an empty schema with no tables at all.
        app(DatabaseDumper::class)->wipeTables();
        $this->assertSame(0, DB::table('information_schema.tables')->where('table_schema', config('database.connections.'.config('database.default').'.database'))->count());

        $this->restorer()->restore($zip, null, null, maintenance: false);

        $this->assertTrue(User::where('username', 'veteran')->first()->hasRole('Manager'));
        $this->assertTrue(User::where('username', 'boss')->first()->hasRole('Admin'));
        $this->assertGreaterThan(80, DB::table('permissions')->count());
    }

    public function test_the_restore_command_requires_an_administrator_login(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();
        $cashier = User::factory()->withRole('Cashier')->create(['username' => 'cashier1', 'password' => 'cashier-pass-1']);

        // Wrong password
        $this->artisan('pos:backup:restore', ['--id' => $backup->id, '--admin' => 'boss', '--yes' => true, '--no-maintenance' => true])
            ->expectsQuestion('Password', 'not-the-password')
            ->expectsOutputToContain('authentication failed')
            ->assertExitCode(1);

        // Right password but not an administrator
        $this->artisan('pos:backup:restore', ['--id' => $backup->id, '--admin' => 'cashier1', '--yes' => true, '--no-maintenance' => true])
            ->expectsQuestion('Password', 'cashier-pass-1')
            ->expectsOutputToContain('authentication failed')
            ->assertExitCode(1);

        $this->assertTrue(User::where('username', 'newcomer')->exists(), 'Nothing was restored.');
        $this->assertSame(0, Backup::query()->where('tier', 'safety')->count());
    }

    public function test_the_restore_command_works_for_a_real_administrator(): void
    {
        [$backup, $admin] = $this->backupThenChangeEverything();

        $this->artisan('pos:backup:restore', ['--id' => $backup->id, '--admin' => 'boss', '--yes' => true, '--no-maintenance' => true])
            ->expectsQuestion('Password', 'admin-pass-123')
            ->expectsOutputToContain('Restore completed')
            ->assertExitCode(0);

        $this->assertTrue(User::where('username', 'keeper')->exists());
        $this->assertFalse(User::where('username', 'newcomer')->exists());
    }

    public function test_the_restore_command_asks_for_the_typed_confirmation_unless_told_not_to(): void
    {
        [$backup] = $this->backupThenChangeEverything();
        $phrase = 'RESTORE '.now()->format('Y-m-d');

        $this->artisan('pos:backup:restore', ['--id' => $backup->id, '--admin' => 'boss', '--no-maintenance' => true])
            ->expectsQuestion('Password', 'admin-pass-123')
            ->expectsQuestion('Type exactly "'.$phrase.'" to continue', 'yes please')
            ->expectsOutputToContain('Confirmation did not match')
            ->assertExitCode(1);

        $this->assertTrue(User::where('username', 'newcomer')->exists(), 'A wrong confirmation changes nothing.');
    }

    public function test_the_restore_command_refuses_a_missing_file(): void
    {
        $this->artisan('pos:backup:restore', ['file' => 'C:\\nowhere\\none.zip'])->expectsOutputToContain('File not found')->assertExitCode(1);
    }

    public function test_only_administrators_see_the_restore_instructions_and_nobody_can_restore_from_the_web(): void
    {
        $manager = User::factory()->withRole('Manager')->create();
        $this->actingAs($manager)->get('/admin/backups')->assertOk()->assertDontSee('pos:backup:restore');

        $admin = User::factory()->withRole('Admin')->create();
        $this->actingAs($admin)->get('/admin/backups')->assertOk()->assertSee('pos:backup:restore');

        // There is deliberately no web route that restores.
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutesByName())->keys()->contains(fn ($n) => str_contains($n, 'restore')));
    }
}
