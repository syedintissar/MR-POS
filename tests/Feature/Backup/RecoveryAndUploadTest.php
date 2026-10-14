<?php

namespace Tests\Feature\Backup;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** Phase 9: bringing a backup file from elsewhere, and the printable Recovery Kit. */
class RecoveryAndUploadTest extends BackupTestCase
{
    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    public function test_a_good_backup_file_is_checked_kept_and_listed(): void
    {
        $admin = $this->admin();
        $source = $this->service()->create(Backup::TIER_MANUAL, $admin);
        $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'usb_copy_'.uniqid().'.zip';
        copy($this->zipPath($source), $copy);

        $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backups/upload', ['file' => new UploadedFile($copy, 'from_usb.zip', 'application/zip', null, true)])
            ->assertRedirect('/admin/backups')->assertSessionHas('success');

        $up = Backup::query()->where('tier', 'uploaded')->sole();
        $this->assertFileExists($this->zipPath($up));
        $this->assertSame(1, AuditLog::query()->where('event', 'backup_uploaded')->count());
        $this->actingAs($admin)->get('/admin/backups')->assertOk()->assertSee($up->filename);
    }

    public function test_a_damaged_or_wrong_file_is_refused_and_not_kept(): void
    {
        $admin = $this->admin();
        $bad = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bad_'.uniqid().'.zip';
        file_put_contents($bad, 'this is not a backup');

        $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backups/upload', ['file' => new UploadedFile($bad, 'bad.zip', 'application/zip', null, true)])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Backup::query()->where('tier', 'uploaded')->count());
        $this->assertSame([], glob($this->service()->tierPath('uploaded').DIRECTORY_SEPARATOR.'mrpos_uploaded_*') ?: []);
        $this->assertSame(1, AuditLog::query()->where('event', 'backup_upload_refused')->count());
    }

    public function test_uploading_needs_the_permission_and_the_password(): void
    {
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'x_'.uniqid().'.zip';
        file_put_contents($file, 'x');
        $upload = fn () => ['file' => new UploadedFile($file, 'x.zip', 'application/zip', null, true)];

        $this->actingAs($this->admin('boss2'))->post('/admin/backups/upload', $upload())->assertRedirect();   // sent to confirm the password first
        foreach (['Cashier', 'Technician'] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->withSession($this->confirmed())->post('/admin/backups/upload', $upload())->assertForbidden();
        }
        $this->assertSame(0, AuditLog::query()->whereIn('event', ['backup_uploaded', 'backup_upload_refused'])->count());
    }

    public function test_the_recovery_kit_shows_what_is_needed_and_only_to_an_administrator_with_the_password(): void
    {
        $admin = $this->admin();
        $this->service()->create(Backup::TIER_MANUAL, $admin);

        $this->actingAs($admin)->get('/admin/backups/recovery-kit')->assertRedirect();   // password first
        $this->actingAs($admin)->withSession($this->confirmed())->get('/admin/backups/recovery-kit')->assertOk()
            ->assertSee('Recovery Kit')->assertSee(config('app.key'), false)->assertSee(config('database.connections.mariadb.database'))->assertSee('pos:backup:restore')->assertSee('No second copy is set up');
        $this->assertSame(1, AuditLog::query()->where('event', 'recovery_kit_opened')->count());

        foreach (['Manager', 'Cashier', 'Technician'] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->withSession($this->confirmed())->get('/admin/backups/recovery-kit')->assertForbidden();
        }
    }
}
