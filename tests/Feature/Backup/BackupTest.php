<?php

namespace Tests\Feature\Backup;

use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Services\Backup\BackupHealth;
use App\Services\Backup\BackupVerifier;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

class BackupTest extends BackupTestCase
{
    public function test_a_backup_creates_an_archive_checksum_and_history_row(): void
    {
        $this->admin();

        $b = $this->service()->create(Backup::TIER_MANUAL);

        $zip = $this->zipPath($b);
        $this->assertFileExists($zip);
        $this->assertFileExists($zip.'.sha256');
        $this->assertSame(Backup::STATUS_SUCCESS, $b->status);
        $this->assertSame(hash_file('sha256', $zip), $b->sha256);
        $this->assertSame(filesize($zip), $b->size_bytes);
        $this->assertSame('passed', $b->verify_status, 'Every backup is verified straight after it is made.');
        $this->assertSame('quick', $b->verify_level);
        $this->assertStringStartsWith('manual/mrpos_', $b->disk_path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup_created']);
    }

    public function test_the_archive_holds_the_whole_database_the_manifest_and_uploaded_files(): void
    {
        $this->admin('marker.user');
        $this->upload('products/p1.jpg', 'IMAGE-BYTES');
        $this->upload('branding/logo-x.png', 'LOGO-BYTES');

        $b = $this->service()->create(Backup::TIER_MANUAL);
        $entries = $this->readZip($this->zipPath($b));

        $this->assertEqualsCanonicalizing(['manifest.json', 'database.sql', 'files/products/p1.jpg', 'files/branding/logo-x.png'], array_keys($entries));
        $this->assertStringContainsString('marker.user', $entries['database.sql'], 'Users are in the dump.');
        $this->assertStringContainsString('CREATE TABLE `audit_logs`', $entries['database.sql']);
        $this->assertStringContainsString('audit_logs_no_update', $entries['database.sql'], 'Triggers are in the dump.');
        $this->assertStringContainsString('Dump completed', $entries['database.sql']);
        $this->assertSame('IMAGE-BYTES', $entries['files/products/p1.jpg']);
        $this->assertStringNotContainsString('DEFINER=', $entries['database.sql'], 'Definers are stripped so any account can restore.');

        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame('MR-POS', $manifest['app']);
        $this->assertArrayHasKey('users', $manifest['row_counts_after']);
        $this->assertSame(1, $manifest['row_counts_after']['users']);
        $this->assertSame(hash('sha256', $entries['database.sql']), $manifest['database_sql_sha256']);
        $this->assertContains('0001_01_01_000000_create_users_table', $manifest['migrations']);
        $this->assertSame(hash('sha256', 'IMAGE-BYTES'), $manifest['files']['products/p1.jpg']);
    }

    public function test_a_backup_still_works_when_the_server_process_has_no_systemroot(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('SystemRoot only matters on Windows.');
        }

        // Regression: PHP's built-in server and some services start PHP without SystemRoot, and the database
        // tools then fail with "Can't create TCP/IP socket (10106)".
        $saved = ['SystemRoot' => getenv('SystemRoot'), 'windir' => getenv('windir')];
        $serverSaved = [$_SERVER['SystemRoot'] ?? null, $_SERVER['windir'] ?? null, $_ENV['SystemRoot'] ?? null, $_ENV['windir'] ?? null];
        putenv('SystemRoot');
        putenv('windir');
        unset($_SERVER['SystemRoot'], $_SERVER['windir'], $_ENV['SystemRoot'], $_ENV['windir']);

        try {
            $this->assertFalse(getenv('SystemRoot'));
            $b = $this->service()->create(Backup::TIER_MANUAL);
            $this->assertSame(Backup::STATUS_SUCCESS, $b->status);
            $this->assertSame('passed', $b->verify_status);
        } finally {
            foreach ($saved as $k => $v) {
                putenv($v === false ? $k : "{$k}={$v}");
            }
            [$_SERVER['SystemRoot'], $_SERVER['windir'], $_ENV['SystemRoot'], $_ENV['windir']] = $serverSaved;
        }
    }

    public function test_uploaded_files_can_be_left_out_by_setting(): void
    {
        $this->upload('products/p1.jpg', 'IMAGE');
        app(SettingsService::class)->set('backup.include_files', false);

        $b = $this->service()->create(Backup::TIER_MANUAL);

        $this->assertEqualsCanonicalizing(['manifest.json', 'database.sql'], array_keys($this->readZip($this->zipPath($b))));
        $this->assertFalse($b->includes_files);
    }

    public function test_quick_verification_detects_a_single_damaged_byte(): void
    {
        $b = $this->service()->create(Backup::TIER_MANUAL);
        $zip = $this->zipPath($b);

        $bytes = file_get_contents($zip);
        $bytes[(int) (strlen($bytes) / 2)] = $bytes[(int) (strlen($bytes) / 2)] === 'A' ? 'B' : 'A';
        file_put_contents($zip, $bytes);

        $r = app(BackupVerifier::class)->quickAndRecord($b);

        $this->assertFalse($r->passed);
        $this->assertStringContainsString('checksum', implode(' ', $r->notes));
        $this->assertSame('failed', $b->fresh()->verify_status);
    }

    public function test_quick_verification_detects_a_hand_edited_database_dump(): void
    {
        $this->admin();
        $b = $this->service()->create(Backup::TIER_MANUAL);
        $zip = $this->zipPath($b);

        $this->rewriteZip($zip, fn ($e) => array_merge($e, ['database.sql' => str_replace('boss', 'evil', $e['database.sql'])]));

        $r = app(BackupVerifier::class)->quickFile($zip);
        $this->assertFalse($r->passed);
        $this->assertStringContainsString('recorded checksum', implode(' ', $r->notes));
    }

    public function test_a_truncated_dump_is_rejected_even_if_its_checksum_was_recomputed(): void
    {
        $b = $this->service()->create(Backup::TIER_MANUAL);
        $zip = $this->zipPath($b);

        $this->rewriteZip($zip, function ($e) {
            $sql = substr($e['database.sql'], 0, (int) (strlen($e['database.sql']) * 0.6)); // cut off the end
            $manifest = json_decode($e['manifest.json'], true);
            $manifest['database_sql_sha256'] = hash('sha256', $sql);
            $e['database.sql'] = $sql;
            $e['manifest.json'] = json_encode($manifest);

            return $e;
        });

        $r = app(BackupVerifier::class)->quickFile($zip);
        $this->assertFalse($r->passed);
        $this->assertStringContainsString('incomplete', implode(' ', $r->notes));
    }

    public function test_a_missing_uploaded_file_inside_the_archive_is_detected(): void
    {
        $this->upload('products/p1.jpg', 'IMAGE');
        $b = $this->service()->create(Backup::TIER_MANUAL);
        $zip = $this->zipPath($b);

        $this->rewriteZip($zip, function ($e) {
            unset($e['files/products/p1.jpg']);

            return $e;
        });

        $r = app(BackupVerifier::class)->quickFile($zip);
        $this->assertFalse($r->passed);
        $this->assertStringContainsString('p1.jpg', implode(' ', $r->notes));
    }

    public function test_deep_verification_proves_the_backup_can_be_restored_and_cleans_up_after_itself(): void
    {
        $this->admin();
        $b = $this->service()->create(Backup::TIER_MANUAL);

        $r = app(BackupVerifier::class)->deepAndRecord($b);

        $this->assertTrue($r->passed, implode('; ', $r->notes));
        $this->assertSame('deep', $b->fresh()->verify_level);
        $this->assertSame('passed', $b->fresh()->verify_status);
        $this->assertStringContainsString('tables restored into the scratch database', implode(' ', $r->notes));
        $this->assertStringContainsString('Row counts match', implode(' ', $r->notes));

        $scratch = config('mrpos.backup.verify_database');
        $this->assertSame(0, DB::table('information_schema.schemata')->where('schema_name', $scratch)->count(), 'The scratch database must be dropped afterwards.');
    }

    public function test_deep_verification_catches_a_backup_whose_row_counts_do_not_match(): void
    {
        $this->admin();
        $b = $this->service()->create(Backup::TIER_MANUAL);
        $zip = $this->zipPath($b);

        $this->rewriteZip($zip, function ($e) {
            $m = json_decode($e['manifest.json'], true);
            $m['row_counts_before']['users'] = 50;
            $m['row_counts_after']['users'] = 50;
            $e['manifest.json'] = json_encode($m);

            return $e;
        });

        $r = app(BackupVerifier::class)->deepFile($zip);
        $this->assertFalse($r->passed);
        $this->assertStringContainsString('row counts differ', implode(' ', $r->notes));
    }

    public function test_deep_verification_refuses_to_use_the_live_database_as_scratch(): void
    {
        $b = $this->service()->create(Backup::TIER_MANUAL);
        config(['mrpos.backup.verify_database' => config('database.connections.'.config('database.default').'.database')]);

        $r = app(BackupVerifier::class)->deepFile($this->zipPath($b));

        $this->assertFalse($r->passed);
        $this->assertGreaterThan(0, DB::table('users')->count() + DB::table('roles')->count(), 'Live data must be untouched.');
    }

    public function test_a_failed_backup_is_recorded_audited_reported_and_leaves_no_file(): void
    {
        $admin = $this->admin();
        config(['mrpos.backup.dump_bin' => 'C:\\does-not-exist\\mariadb-dump.exe']);

        try {
            $this->service()->create(Backup::TIER_DAILY);
            $this->fail('Expected the backup to fail.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        $row = Backup::query()->latest('id')->first();
        $this->assertSame(Backup::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('not found', $row->error_message);
        $this->assertNotNull($row->finished_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup_failed']);
        $this->assertSame([], File::glob($this->service()->root().'/daily/*.zip'));
        $this->assertSame([], File::directories($this->service()->root().'/tmp'), 'Working folders are cleaned up.');

        // The administrator is told, loudly.
        $messages = collect(app(BackupHealth::class)->warnings());
        $this->assertTrue($messages->contains(fn ($w) => $w['level'] === 'critical' && str_contains($w['message'], 'FAILED')));
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('The last backup attempt FAILED');
        $this->actingAs($admin)->get('/admin/backups')->assertOk()->assertSee('FAILED');
    }

    public function test_a_failed_backup_never_deletes_older_good_ones(): void
    {
        app(SettingsService::class)->set('backup.retention_daily', 1);
        $old1 = $this->fakeBackup(Backup::TIER_DAILY, now()->subDays(3));
        $old2 = $this->fakeBackup(Backup::TIER_DAILY, now()->subDays(2));
        config(['mrpos.backup.dump_bin' => 'C:\\does-not-exist\\mariadb-dump.exe']);

        try {
            $this->service()->create(Backup::TIER_DAILY);
        } catch (BackupException) {
        }

        $this->assertNull($old1->fresh()->pruned_at);
        $this->assertNull($old2->fresh()->pruned_at);
        $this->assertFileExists($this->zipPath($old1));
    }

    public function test_the_command_exits_non_zero_when_the_backup_fails(): void
    {
        config(['mrpos.backup.dump_bin' => 'C:\\does-not-exist\\mariadb-dump.exe']);

        $this->artisan('pos:backup:run', ['--tier' => 'manual'])->expectsOutputToContain('BACKUP FAILED')->assertExitCode(1);
    }

    public function test_two_backups_cannot_run_at_the_same_time(): void
    {
        $lock = Cache::lock('backup-running', 60);
        $this->assertTrue($lock->get());

        try {
            $this->service()->create(Backup::TIER_MANUAL);
            $this->fail('Expected a refusal.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    public function test_encrypted_backups_cannot_be_opened_without_the_password(): void
    {
        $settings = app(SettingsService::class);
        $settings->setMany(['backup.encryption_enabled' => true, 'backup.encryption_password' => 'S3cret-backup-pw']);

        $b = $this->service()->create(Backup::TIER_MANUAL);
        $zip = $this->zipPath($b);

        $this->assertTrue($b->encrypted);
        $this->assertSame('passed', $b->verify_status, 'Verified with the stored password.');

        // Without the password the contents cannot be read at all.
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::RDONLY);
        $this->assertFalse($z->getFromName('manifest.json'));
        $z->close();
        $this->assertStringNotContainsString('CREATE TABLE', (string) file_get_contents($zip));

        $this->assertFalse(app(BackupVerifier::class)->quickFile($zip, null, 'wrong-password-123')->passed);
        $this->assertTrue(app(BackupVerifier::class)->quickFile($zip, null, 'S3cret-backup-pw')->passed);
        $this->assertTrue(app(BackupVerifier::class)->deepFile($zip, null, 'S3cret-backup-pw')->passed);
    }

    public function test_the_backup_password_is_stored_encrypted_in_settings(): void
    {
        app(SettingsService::class)->setMany(['backup.encryption_enabled' => true, 'backup.encryption_password' => 'S3cret-backup-pw']);

        $stored = (string) DB::table('settings')->where('key', 'backup.encryption_password')->value('value');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('S3cret', $stored);
        $this->assertSame('S3cret-backup-pw', app(SettingsService::class)->string('backup.encryption_password'));
    }

    public function test_encryption_switched_on_without_a_password_fails_clearly(): void
    {
        app(SettingsService::class)->set('backup.encryption_enabled', true);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('no backup password');
        $this->service()->create(Backup::TIER_MANUAL);
    }

    public function test_a_daily_backup_is_also_copied_to_the_weekly_and_monthly_tiers(): void
    {
        $daily = $this->service()->create(Backup::TIER_DAILY);

        $weekly = Backup::query()->where('tier', 'weekly')->first();
        $monthly = Backup::query()->where('tier', 'monthly')->first();
        $this->assertNotNull($weekly, 'First backup of the week seeds the weekly tier.');
        $this->assertNotNull($monthly, 'First backup of the month seeds the monthly tier.');
        $this->assertFileExists($this->zipPath($weekly));
        $this->assertSame($daily->sha256, $weekly->sha256);
        $this->assertSame($daily->sha256, hash_file('sha256', $this->zipPath($monthly)));

        // A second daily backup the same day does not create more weekly/monthly copies.
        $this->service()->create(Backup::TIER_DAILY);
        $this->assertSame(1, Backup::query()->where('tier', 'weekly')->count());
        $this->assertSame(1, Backup::query()->where('tier', 'monthly')->count());
    }

    public function test_health_warns_when_there_has_never_been_a_backup(): void
    {
        $w = collect(app(BackupHealth::class)->warnings());

        $this->assertTrue($w->contains(fn ($x) => $x['level'] === 'critical' && str_contains($x['message'], 'No backup has ever completed')));
    }

    public function test_health_warns_when_the_last_good_backup_is_too_old(): void
    {
        $this->fakeBackup(Backup::TIER_DAILY, now()->subHours(40));

        $w = collect(app(BackupHealth::class)->warnings());

        $this->assertTrue($w->contains(fn ($x) => $x['level'] === 'critical' && str_contains($x['message'], 'No successful backup in the last 26 hours')));
    }

    public function test_health_warns_about_failed_verification_missing_second_copy_and_a_stopped_scheduler(): void
    {
        $this->fakeBackup(Backup::TIER_DAILY, now()->subHour(), ['verify_status' => 'failed']);
        $text = collect(app(BackupHealth::class)->warnings())->pluck('message')->implode(' | ');

        $this->assertStringContainsString('failed verification', $text);
        $this->assertStringContainsString('only stored on this computer', $text);
        $this->assertStringContainsString('scheduled-task runner is not running', $text);
    }

    public function test_the_scheduler_heartbeat_clears_the_warning_and_goes_stale_after_ten_minutes(): void
    {
        $health = app(BackupHealth::class);

        BackupHealth::touchHeartbeat();
        $this->assertFalse($health->heartbeatStale());

        file_put_contents(config('mrpos.backup.heartbeat_file'), (string) (time() - 11 * 60));
        $this->assertTrue($health->heartbeatStale());
    }

    public function test_a_healthy_system_shows_no_warnings(): void
    {
        $dest = $this->sandbox.DIRECTORY_SEPARATOR.'usb';
        File::ensureDirectoryExists($dest);
        BackupDestination::create(['name' => 'USB', 'driver' => 'local_path', 'config' => ['path' => $dest], 'is_active' => true]);

        $b = $this->service()->create(Backup::TIER_DAILY);
        app(BackupVerifier::class)->deepAndRecord($b);
        BackupHealth::touchHeartbeat();

        $this->assertSame([], app(BackupHealth::class)->warnings());
    }
}
