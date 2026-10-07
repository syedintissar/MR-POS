<?php

namespace Tests\Feature\Backup;

use App\Models\Backup;
use App\Models\User;
use App\Services\SettingsService;

class BackupWebTest extends BackupTestCase
{
    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    public function test_backup_now_works_for_a_manager_and_is_attributed_to_them(): void
    {
        $manager = User::factory()->withRole('Manager')->create();

        $this->actingAs($manager)->post('/admin/backups')->assertRedirect('/admin/backups')->assertSessionHas('success');

        $b = Backup::query()->latest('id')->first();
        $this->assertSame('manual', $b->tier);
        $this->assertSame($manager->id, $b->triggered_by);
        $this->assertSame('success', $b->status);
        $this->actingAs($manager)->get('/admin/backups')->assertOk()->assertSee($b->started_at->format('d M Y'))->assertSee($manager->username);
    }

    public function test_backup_now_failure_is_shown_to_the_user(): void
    {
        config(['mrpos.backup.dump_bin' => 'C:\\does-not-exist\\mariadb-dump.exe']);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/backups')->assertRedirect('/admin/backups')->assertSessionHas('error', fn ($m) => str_contains($m, 'Backup FAILED'));
        $this->assertSame('failed', Backup::query()->latest('id')->value('status'));
    }

    public function test_cashiers_and_technicians_cannot_touch_backups_at_all(): void
    {
        $b = $this->service()->create(Backup::TIER_MANUAL);

        foreach (['Cashier', 'Technician'] as $role) {
            $u = User::factory()->withRole($role)->create();
            $this->actingAs($u)->get('/admin/backups')->assertForbidden();
            $this->actingAs($u)->post('/admin/backups')->assertForbidden();
            $this->actingAs($u)->withSession($this->confirmed())->get("/admin/backups/{$b->id}/download")->assertForbidden();
        }
    }

    public function test_a_manager_cannot_download_verify_delete_or_change_settings(): void
    {
        $b = $this->service()->create(Backup::TIER_MANUAL);
        $manager = User::factory()->withRole('Manager')->create();
        $this->actingAs($manager)->withSession($this->confirmed());

        $this->get("/admin/backups/{$b->id}/download")->assertForbidden();
        $this->post("/admin/backups/{$b->id}/verify", ['level' => 'deep'])->assertForbidden();
        $this->delete("/admin/backups/{$b->id}")->assertForbidden();
        $this->post("/admin/backups/{$b->id}/protect")->assertForbidden();
        $this->put('/admin/backups/settings', [])->assertForbidden();
        $this->assertFileExists($this->zipPath($b));
    }

    public function test_downloading_a_backup_needs_the_password_again_and_is_audited(): void
    {
        $admin = $this->admin();
        $b = $this->service()->create(Backup::TIER_MANUAL);

        // Without a recent confirmation: sent to the password screen, nothing is downloaded.
        $this->actingAs($admin)->get("/admin/backups/{$b->id}/download")->assertRedirect(route('password.confirm'));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'backup_downloaded']);

        $response = $this->withSession($this->confirmed())->get("/admin/backups/{$b->id}/download");
        $response->assertOk()->assertDownload($b->filename);
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup_downloaded', 'auditable_id' => $b->id]);
    }

    public function test_quick_and_deep_verification_from_the_ui_are_recorded_and_audited(): void
    {
        $admin = $this->admin();
        $b = $this->service()->create(Backup::TIER_MANUAL);

        $this->actingAs($admin)->post("/admin/backups/{$b->id}/verify", ['level' => 'deep'])->assertSessionHas('success');

        $b->refresh();
        $this->assertSame('deep', $b->verify_level);
        $this->assertSame('passed', $b->verify_status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup_verified']);

        $this->actingAs($admin)->post("/admin/backups/{$b->id}/verify", ['level' => 'bogus'])->assertSessionHasErrors('level');
    }

    public function test_a_damaged_backup_shows_as_failed_verification_in_the_ui(): void
    {
        $admin = $this->admin();
        $b = $this->service()->create(Backup::TIER_MANUAL);
        file_put_contents($this->zipPath($b), 'garbage');

        $this->actingAs($admin)->post("/admin/backups/{$b->id}/verify", ['level' => 'quick'])->assertSessionHas('error');
        $this->actingAs($admin)->get('/admin/backups')->assertSee('Failed');
        $this->actingAs($admin)->get('/')->assertSee('failed verification');
    }

    public function test_deleting_a_backup_is_guarded(): void
    {
        $admin = $this->admin();
        $old = $this->fakeBackup('manual', now()->subDays(3));
        $newest = $this->fakeBackup('manual', now()->subHour());
        $protected = $this->fakeBackup('manual', now()->subDays(5), ['is_protected' => true]);
        $this->actingAs($admin)->withSession($this->confirmed());

        $this->delete("/admin/backups/{$newest->id}")->assertSessionHas('error');
        $this->assertNull($newest->fresh()->pruned_at, 'The newest good backup cannot be deleted.');

        $this->delete("/admin/backups/{$protected->id}")->assertSessionHas('error');
        $this->assertNull($protected->fresh()->pruned_at, 'Protected backups cannot be deleted.');

        $path = $this->zipPath($old);
        $this->delete("/admin/backups/{$old->id}")->assertSessionHas('success');
        $this->assertNotNull($old->fresh()->pruned_at);
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup_deleted', 'auditable_id' => $old->id]);
    }

    public function test_protecting_a_backup_is_toggled_and_audited(): void
    {
        $admin = $this->admin();
        $b = $this->fakeBackup('manual', now());

        $this->actingAs($admin)->post("/admin/backups/{$b->id}/protect")->assertSessionHas('success');
        $this->assertTrue($b->fresh()->is_protected);
        $this->post("/admin/backups/{$b->id}/protect");
        $this->assertFalse($b->fresh()->is_protected);
        $this->assertSame(2, \DB::table('audit_logs')->where('event', 'backup_protection')->count());
    }

    public function test_backup_settings_are_validated_saved_and_audited(): void
    {
        $admin = $this->admin();
        $valid = [
            'backup_enabled' => 1, 'backup_daily_time' => '22:15', 'backup_weekly_day' => '5', 'backup_retention_daily' => 10,
            'backup_retention_weekly' => 5, 'backup_retention_monthly' => 12, 'backup_retention_manual' => 20, 'backup_retention_safety' => 5,
            'backup_include_files' => 1, 'backup_alert_after_hours' => 30, 'backup_encryption_enabled' => 0,
        ];
        $this->actingAs($admin)->withSession($this->confirmed());

        $this->put('/admin/backups/settings', $valid)->assertRedirect('/admin/backups')->assertSessionHas('success');

        $s = app(SettingsService::class);
        $this->assertSame('22:15', $s->string('backup.daily_time'));
        $this->assertSame(10, $s->int('backup.retention_daily'));
        $this->assertSame(12, $s->int('backup.retention_monthly'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings_changed']);

        $this->put('/admin/backups/settings', array_merge($valid, ['backup_daily_time' => '25:99', 'backup_retention_daily' => 0]))
            ->assertSessionHasErrors(['backup_daily_time', 'backup_retention_daily']);
    }

    public function test_encryption_cannot_be_switched_on_without_a_password(): void
    {
        $admin = $this->admin();
        $form = [
            'backup_enabled' => 1, 'backup_daily_time' => '21:00', 'backup_weekly_day' => '0', 'backup_retention_daily' => 7,
            'backup_retention_weekly' => 4, 'backup_retention_monthly' => 6, 'backup_retention_manual' => 20, 'backup_retention_safety' => 5,
            'backup_include_files' => 1, 'backup_alert_after_hours' => 26, 'backup_encryption_enabled' => 1,
        ];
        $this->actingAs($admin)->withSession($this->confirmed());

        $this->put('/admin/backups/settings', $form)->assertSessionHasErrors('backup_encryption_password');
        $this->assertFalse(app(SettingsService::class)->bool('backup.encryption_enabled'));

        $this->put('/admin/backups/settings', $form + ['backup_encryption_password' => 'Long-backup-password'])->assertSessionHasNoErrors();
        $this->assertTrue(app(SettingsService::class)->bool('backup.encryption_enabled'));
        $this->assertSame('Long-backup-password', app(SettingsService::class)->string('backup.encryption_password'));

        // The password is never echoed back into the page.
        $this->get('/admin/backups')->assertOk()->assertDontSee('Long-backup-password')->assertSee('A password is set');
    }

    public function test_the_dashboard_shows_backup_status_only_to_people_who_may_see_it(): void
    {
        $this->actingAs(User::factory()->withRole('Cashier')->create())->get('/')->assertOk()->assertDontSee('Last good backup')->assertDontSee('No backup has ever completed');

        $manager = User::factory()->withRole('Manager')->create();
        $this->actingAs($manager)->get('/')->assertOk()->assertSee('No backup has ever completed')->assertSee('Last good backup');
    }

    public function test_the_sidebar_flags_a_backup_problem_with_a_red_dot(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/')->assertSee('Backup problem');
    }
}
