<?php

namespace Tests\Feature\Backup;

use App\Models\Backup;
use App\Services\Backup\BackupSchedule;
use App\Services\Backup\RetentionService;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

class RetentionAndScheduleTest extends BackupTestCase
{
    private function retention(): RetentionService
    {
        return app(RetentionService::class);
    }

    public function test_only_the_newest_n_backups_per_tier_are_kept(): void
    {
        $s = app(SettingsService::class);
        $s->setMany(['backup.retention_daily' => 3, 'backup.retention_weekly' => 2, 'backup.retention_monthly' => 1, 'backup.retention_manual' => 2]);

        $daily = collect(range(1, 6))->map(fn ($d) => $this->fakeBackup('daily', now()->subDays($d)));
        $weekly = collect(range(1, 4))->map(fn ($w) => $this->fakeBackup('weekly', now()->subWeeks($w)));
        $monthly = collect(range(1, 3))->map(fn ($m) => $this->fakeBackup('monthly', now()->subMonths($m)));
        $manual = collect(range(1, 4))->map(fn ($m) => $this->fakeBackup('manual', now()->subHours($m)));

        $pruned = $this->retention()->apply();

        $this->assertSame(3 + 2 + 2 + 2, $pruned);
        foreach ([[$daily, 3], [$weekly, 2], [$monthly, 1], [$manual, 2]] as [$set, $keep]) {
            // $set is newest-first (subDays(1) is the newest)
            $set->each(fn (Backup $b, int $i) => $i < $keep
                ? $this->assertNull($b->fresh()->pruned_at, 'newest are kept')
                : $this->assertNotNull($b->fresh()->pruned_at, 'oldest are pruned'));
        }
    }

    public function test_pruned_files_are_deleted_but_the_history_row_stays(): void
    {
        app(SettingsService::class)->set('backup.retention_daily', 1);
        $old = $this->fakeBackup('daily', now()->subDays(5));
        $new = $this->fakeBackup('daily', now()->subDay());
        $oldPath = $this->zipPath($old);

        $this->retention()->apply();

        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileDoesNotExist($oldPath.'.sha256');
        $this->assertFileExists($this->zipPath($new));
        $this->assertNotNull($old->fresh(), 'The history row is kept.');
        $this->assertNotNull($old->fresh()->pruned_at);
    }

    public function test_protected_backups_are_never_pruned(): void
    {
        app(SettingsService::class)->set('backup.retention_daily', 1);
        $keep = $this->fakeBackup('daily', now()->subDays(30), ['is_protected' => true]);
        $this->fakeBackup('daily', now()->subDays(5));
        $this->fakeBackup('daily', now()->subDay());

        $this->retention()->apply();

        $this->assertNull($keep->fresh()->pruned_at);
        $this->assertFileExists($this->zipPath($keep));
    }

    public function test_the_newest_deep_verified_backup_survives_even_outside_the_retention_window(): void
    {
        app(SettingsService::class)->set('backup.retention_daily', 1);
        $proven = $this->fakeBackup('daily', now()->subDays(20), ['verify_level' => 'deep', 'verify_status' => 'passed']);
        $this->fakeBackup('daily', now()->subDays(10));
        $this->fakeBackup('daily', now()->subDay());

        $this->retention()->apply();

        $this->assertNull($proven->fresh()->pruned_at, 'The last backup proven to restore is kept.');
    }

    public function test_failed_backups_are_ignored_by_retention(): void
    {
        app(SettingsService::class)->set('backup.retention_daily', 1);
        $failed = Backup::create(['tier' => 'daily', 'status' => 'failed', 'started_at' => now()->subDays(3), 'error_message' => 'x']);
        $good = $this->fakeBackup('daily', now()->subDays(2));
        $newest = $this->fakeBackup('daily', now()->subDay());

        $this->retention()->apply();

        $this->assertNull($newest->fresh()->pruned_at);
        $this->assertNotNull($good->fresh()->pruned_at);
        $this->assertNull($failed->fresh()->pruned_at, 'A failed row never counts toward (or against) the limit.');
    }

    public function test_safety_backups_are_kept_for_at_least_thirty_days(): void
    {
        app(SettingsService::class)->set('backup.retention_safety', 1);
        $recent = $this->fakeBackup('safety', now()->subDays(10));
        $older = $this->fakeBackup('safety', now()->subDays(12));
        $ancient = $this->fakeBackup('safety', now()->subDays(45));
        $newest = $this->fakeBackup('safety', now()->subDays(1));

        $this->retention()->apply();

        $this->assertNull($newest->fresh()->pruned_at);
        $this->assertNull($recent->fresh()->pruned_at, 'Within 30 days: kept even though beyond the count.');
        $this->assertNull($older->fresh()->pruned_at);
        $this->assertNotNull($ancient->fresh()->pruned_at);
    }

    public function test_the_only_good_backup_is_never_removed_even_with_a_tiny_limit(): void
    {
        app(SettingsService::class)->set('backup.retention_manual', 1);
        $only = $this->fakeBackup('manual', now()->subDays(90));

        $this->retention()->apply();

        $this->assertNull($only->fresh()->pruned_at);
    }

    public function test_abandoned_work_folders_are_cleaned_up(): void
    {
        $old = $this->service()->root().'/tmp/abandoned';
        $new = $this->service()->root().'/tmp/in-progress';
        mkdir($old, 0775, true);
        mkdir($new, 0775, true);
        touch($old, time() - 3 * 86400);

        $this->retention()->apply();

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($new);
    }

    // ---------------------------------------------------------------- schedule

    private function schedule(): BackupSchedule
    {
        return app(BackupSchedule::class);
    }

    public function test_a_daily_backup_is_due_after_the_scheduled_time_until_one_succeeds(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 20:59:00'));
        // Yesterday's 21:00 slot has had no backup yet: due (catch-up for a PC that was off last evening).
        $this->assertTrue($this->schedule()->dailyDue());

        $this->fakeBackup('daily', Carbon::parse('2026-10-06 21:05:00'));
        $this->assertFalse($this->schedule()->dailyDue(), 'Yesterday evening is covered, so wait for tonight.');

        $this->travelTo(Carbon::parse('2026-10-07 21:00:00'));
        $this->assertTrue($this->schedule()->dailyDue(), "Tonight's slot has started.");

        $this->fakeBackup('daily', Carbon::parse('2026-10-07 21:03:00'));
        $this->travelTo(Carbon::parse('2026-10-07 23:30:00'));
        $this->assertFalse($this->schedule()->dailyDue(), 'Done for today.');
    }

    public function test_a_pc_switched_on_next_morning_catches_up_the_missed_backup(): void
    {
        $this->fakeBackup('daily', Carbon::parse('2026-10-05 21:02:00'));

        // The PC was off on the evening of the 6th and is switched on at 08:15 on the 7th.
        $this->travelTo(Carbon::parse('2026-10-07 08:15:00'));

        $this->assertTrue($this->schedule()->dailyDue(), 'The missed 6 Oct backup runs now.');
    }

    public function test_a_failed_attempt_does_not_count_so_it_is_retried(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 21:30:00'));
        Backup::create(['tier' => 'daily', 'status' => 'failed', 'started_at' => Carbon::parse('2026-10-07 21:01:00'), 'error_message' => 'disk full']);

        $this->assertTrue($this->schedule()->dailyDue());
    }

    public function test_switching_automatic_backups_off_stops_the_schedule(): void
    {
        app(SettingsService::class)->set('backup.enabled', false);

        $this->assertFalse($this->schedule()->dailyDue());
    }

    public function test_a_custom_backup_time_is_respected(): void
    {
        app(SettingsService::class)->set('backup.daily_time', '13:30');
        $this->fakeBackup('daily', Carbon::parse('2026-10-06 13:35:00'));

        $this->travelTo(Carbon::parse('2026-10-07 13:29:00'));
        $this->assertFalse($this->schedule()->dailyDue());
        $this->travelTo(Carbon::parse('2026-10-07 13:30:00'));
        $this->assertTrue($this->schedule()->dailyDue());
    }

    public function test_a_weekly_restore_test_is_due_when_none_has_passed_for_a_week(): void
    {
        $daily = $this->fakeBackup('daily', now()->subHours(3), ['verify_level' => 'quick']);
        $this->assertNotNull($this->schedule()->deepVerifyDue());
        $this->assertSame($daily->id, $this->schedule()->deepVerifyDue()->id);

        $daily->update(['verify_level' => 'deep', 'verify_status' => 'passed', 'verified_at' => now()->subDays(2)]);
        $this->assertNull($this->schedule()->deepVerifyDue());

        $daily->update(['verified_at' => now()->subDays(8)]);
        $this->assertNotNull($this->schedule()->deepVerifyDue());
    }

    public function test_the_auto_command_makes_a_daily_backup_when_due_and_does_nothing_otherwise(): void
    {
        $this->artisan('pos:backup:run', ['--tier' => 'auto'])->expectsOutputToContain('Backup OK')->assertExitCode(0);
        $this->assertSame(1, Backup::query()->where('tier', 'daily')->where('status', 'success')->count());

        $this->artisan('pos:backup:run', ['--tier' => 'auto'])->expectsOutputToContain('No backup due')->assertExitCode(0);
        $this->assertSame(1, Backup::query()->where('tier', 'daily')->count(), 'No second backup the same day.');
    }

    public function test_the_scheduler_registers_the_backup_heartbeat_and_audit_jobs(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('pos:backup:run --tier=auto')
            ->expectsOutputToContain('pos:backup:retry-transfers')
            ->expectsOutputToContain('pos:audit:verify')
            ->assertExitCode(0);
    }
}
