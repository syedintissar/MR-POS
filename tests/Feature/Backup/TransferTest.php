<?php

namespace Tests\Feature\Backup;

use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\BackupTransfer;
use App\Services\Backup\BackupHealth;
use App\Services\Backup\TransferService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\File;

class TransferTest extends BackupTestCase
{
    private function destination(string $path, array $attrs = []): BackupDestination
    {
        return BackupDestination::create(array_merge(['name' => 'USB drive', 'driver' => 'local_path', 'config' => ['path' => $path], 'is_active' => true], $attrs));
    }

    public function test_a_new_backup_is_copied_to_every_active_destination_and_the_copy_is_verified(): void
    {
        $usb = $this->sandbox.DIRECTORY_SEPARATOR.'usb';
        $pc2 = $this->sandbox.DIRECTORY_SEPARATOR.'pc2';
        File::ensureDirectoryExists($usb);
        File::ensureDirectoryExists($pc2);
        $this->destination($usb);
        $this->destination($pc2, ['name' => 'Second PC']);
        $this->destination($this->sandbox.DIRECTORY_SEPARATOR.'off', ['name' => 'Switched off', 'is_active' => false]);

        $b = $this->service()->create(Backup::TIER_MANUAL);

        $this->assertSame(2, $b->transfers()->count(), 'Inactive destinations are skipped.');
        $this->assertSame(2, $b->transfers()->where('status', 'success')->count());
        foreach ([$usb, $pc2] as $dir) {
            $copy = $dir.DIRECTORY_SEPARATOR.'manual'.DIRECTORY_SEPARATOR.$b->filename;
            $this->assertFileExists($copy);
            $this->assertSame($b->sha256, hash_file('sha256', $copy), 'The copy is byte-identical.');
            $this->assertFileExists($copy.'.sha256');
        }
        $this->assertNotNull(BackupDestination::first()->last_success_at);
    }

    public function test_an_unplugged_drive_leaves_the_transfer_pending_without_affecting_the_local_backup(): void
    {
        // The parent folder does not exist: exactly what an unplugged USB drive looks like.
        $this->destination($this->sandbox.DIRECTORY_SEPARATOR.'F-drive-missing'.DIRECTORY_SEPARATOR.'MRPOS-Backups');

        $b = $this->service()->create(Backup::TIER_MANUAL);

        $this->assertSame(Backup::STATUS_SUCCESS, $b->status, 'The local backup is unaffected.');
        $this->assertFileExists($this->zipPath($b));

        $t = $b->transfers()->first();
        $this->assertSame(BackupTransfer::PENDING, $t->status);
        $this->assertSame(1, $t->attempts);
        $this->assertStringContainsString('not reachable', $t->error_message);
        $this->assertTrue($t->next_attempt_at->isFuture(), 'A retry is scheduled.');
        $this->assertStringContainsString('not reachable', BackupDestination::first()->last_error);
        $this->assertFileDoesNotExist($this->sandbox.DIRECTORY_SEPARATOR.'F-drive-missing', 'The missing drive is not created out of thin air.');
    }

    public function test_the_transfer_succeeds_automatically_once_the_drive_is_back(): void
    {
        $drive = $this->sandbox.DIRECTORY_SEPARATOR.'F-drive';
        $this->destination($drive.DIRECTORY_SEPARATOR.'MRPOS-Backups');
        $b = $this->service()->create(Backup::TIER_MANUAL);
        $t = $b->transfers()->first();
        $this->assertSame(BackupTransfer::PENDING, $t->status);

        // Retrying before the scheduled time does nothing.
        $this->assertSame(['success' => 0, 'failed' => 0], app(TransferService::class)->runDue());
        $this->assertSame(1, $t->fresh()->attempts);

        // The USB drive is plugged in, and time passes.
        File::ensureDirectoryExists($drive);
        $this->travel(10)->minutes();
        $r = app(TransferService::class)->runDue();

        $this->assertSame(1, $r['success']);
        $t->refresh();
        $this->assertSame(BackupTransfer::SUCCESS, $t->status);
        $this->assertSame(2, $t->attempts);
        $this->assertNull($t->error_message);
        $this->assertFileExists($drive.'/MRPOS-Backups/manual/'.$b->filename);
        $this->assertNull(BackupDestination::first()->last_error, 'The destination error clears.');
    }

    public function test_retries_back_off_and_a_stuck_transfer_is_flagged_failed_after_a_day_but_keeps_retrying(): void
    {
        $this->destination($this->sandbox.DIRECTORY_SEPARATOR.'gone'.DIRECTORY_SEPARATOR.'x');
        $b = $this->service()->create(Backup::TIER_MANUAL);
        $t = $b->transfers()->first();

        $gaps = [];
        foreach (range(1, 4) as $i) {
            $this->travel(7)->hours(); // always past the next attempt time
            app(TransferService::class)->runDue();
            $t->refresh();
            $gaps[] = $t->attempts;
        }

        $this->assertSame([2, 3, 4, 5], $gaps);
        $this->assertSame(BackupTransfer::FAILED, $t->status, 'After 24 hours without success it is flagged FAILED.');
        $this->assertNotNull($t->next_attempt_at, 'But it keeps retrying.');
    }

    public function test_a_destination_that_requires_encryption_never_receives_a_plain_backup(): void
    {
        $dir = $this->sandbox.DIRECTORY_SEPARATOR.'cloud-sim';
        File::ensureDirectoryExists($dir);
        $this->destination($dir, ['name' => 'Cloud', 'requires_encryption' => true]);

        $plain = $this->service()->create(Backup::TIER_MANUAL);

        $this->assertSame([], File::glob($dir.'/manual/*'), 'The plain backup was NOT copied.');
        $this->assertStringContainsString('requires encrypted backups', $plain->transfers()->first()->error_message);

        app(SettingsService::class)->setMany(['backup.encryption_enabled' => true, 'backup.encryption_password' => 'S3cret-backup-pw']);
        $encrypted = $this->service()->create(Backup::TIER_MANUAL);

        $this->assertFileExists($dir.'/manual/'.$encrypted->filename);
        $this->assertSame('success', $encrypted->transfers()->first()->status);
    }

    public function test_a_corrupt_copy_is_detected_and_not_accepted(): void
    {
        $dir = $this->sandbox.DIRECTORY_SEPARATOR.'usb';
        File::ensureDirectoryExists($dir);
        $dest = $this->destination($dir);
        $b = $this->fakeBackup(Backup::TIER_MANUAL, now());
        // Lie about the checksum so the verification of the copy must fail.
        $b->update(['sha256' => str_repeat('0', 64)]);
        $t = BackupTransfer::create(['backup_id' => $b->id, 'backup_destination_id' => $dest->id, 'status' => 'pending', 'next_attempt_at' => now()]);

        $this->assertFalse(app(TransferService::class)->attempt($t));

        $this->assertStringContainsString('checksum differs', $t->fresh()->error_message);
        $this->assertSame([], File::glob($dir.'/manual/*.zip'), 'No unverified copy is left behind.');
        $this->assertSame([], File::glob($dir.'/manual/*.partial'));
    }

    public function test_a_transfer_for_a_backup_pruned_in_the_meantime_is_marked_failed_not_retried_forever(): void
    {
        $dest = $this->destination($this->sandbox.DIRECTORY_SEPARATOR.'gone'.DIRECTORY_SEPARATOR.'x');
        $b = $this->fakeBackup(Backup::TIER_DAILY, now()->subDays(40));
        $b->update(['pruned_at' => now()]);
        $t = BackupTransfer::create(['backup_id' => $b->id, 'backup_destination_id' => $dest->id, 'status' => 'pending', 'next_attempt_at' => now()]);

        app(TransferService::class)->attempt($t);

        $t->refresh();
        $this->assertSame(BackupTransfer::FAILED, $t->status);
        $this->assertNull($t->next_attempt_at);
        $this->assertStringContainsString('removed by retention', $t->error_message);
    }

    public function test_health_reports_a_destination_that_cannot_be_reached_and_stuck_copies(): void
    {
        $this->destination($this->sandbox.DIRECTORY_SEPARATOR.'gone'.DIRECTORY_SEPARATOR.'x');
        $b = $this->service()->create(Backup::TIER_MANUAL);
        BackupTransfer::query()->update(['created_at' => now()->subHours(50)]);
        BackupHealth::touchHeartbeat();

        $text = collect(app(BackupHealth::class)->warnings())->pluck('message')->implode(' | ');

        $this->assertStringContainsString('is not reachable', $text);
        $this->assertStringContainsString('waiting for more than 48 hours', $text);
        $this->assertStringNotContainsString('only stored on this computer', $text, 'A second destination is configured.');
    }

    public function test_the_retry_command_and_button_process_due_transfers(): void
    {
        $drive = $this->sandbox.DIRECTORY_SEPARATOR.'F-drive';
        $this->destination($drive.DIRECTORY_SEPARATOR.'B');
        $b = $this->service()->create(Backup::TIER_MANUAL);
        File::ensureDirectoryExists($drive);
        $this->travel(30)->minutes();

        $this->artisan('pos:backup:retry-transfers')->expectsOutputToContain('1 copied')->assertExitCode(0);
        $this->assertSame('success', $b->transfers()->first()->status);
    }

    public function test_adding_and_testing_a_destination_through_the_web_ui(): void
    {
        $admin = $this->admin();
        $dir = $this->sandbox.DIRECTORY_SEPARATOR.'ui-usb';
        File::ensureDirectoryExists($dir);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/admin/backups/destinations', ['name' => 'USB from UI', 'path' => $dir])
            ->assertRedirect('/admin/backups')->assertSessionHas('success');

        $d = BackupDestination::first();
        $this->assertSame($dir, $d->config['path']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup_destination_added']);

        // Reachable folder: "Test" reports success.
        $this->post("/admin/backups/destinations/{$d->id}/test")->assertSessionHas('success');

        // Unplugged drive (its parent folder no longer exists): "Test" reports the problem.
        $d->update(['config' => ['path' => $this->sandbox.DIRECTORY_SEPARATOR.'no-such-drive'.DIRECTORY_SEPARATOR.'B']]);
        $this->post("/admin/backups/destinations/{$d->id}/test")->assertSessionHas('error');
        $this->assertStringContainsString('not reachable', $d->fresh()->last_error);

        // Adding a destination that is unreachable still saves it, but warns.
        $this->post('/admin/backups/destinations', ['name' => 'Unplugged', 'path' => $this->sandbox.DIRECTORY_SEPARATOR.'nope'.DIRECTORY_SEPARATOR.'B'])
            ->assertSessionHas('error');
        $this->assertSame(2, BackupDestination::count());
    }
}
