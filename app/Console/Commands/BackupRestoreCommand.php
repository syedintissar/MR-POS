<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class BackupRestoreCommand extends Command
{
    protected $signature = 'pos:backup:restore
        {file? : full path to a backup .zip (for example from a USB drive)}
        {--id= : id of a backup listed by pos:backup:list}
        {--password= : backup password if the file is encrypted}
        {--admin= : administrator username (asked if omitted)}
        {--yes : skip the typed confirmation (administrator login is still required)}
        {--no-maintenance : do not put the web app into maintenance mode}';

    protected $description = 'DANGEROUS: replace ALL current data with a backup. Verifies first and takes a safety backup of the current data.';

    public function handle(RestoreService $restore, BackupService $backups): int
    {
        $source = null;

        if ($id = $this->option('id')) {
            $source = Backup::query()->find($id);
            if (! $source || $source->pruned_at) {
                $this->error('That backup is not available.');

                return self::FAILURE;
            }
            $zip = $backups->absolutePath($source);
        } elseif ($file = $this->argument('file')) {
            $zip = $file;
        } else {
            $this->error('Give the path to a backup .zip, or --id=<backup id> (see: php artisan pos:backup:list).');

            return self::FAILURE;
        }

        if (! is_file($zip)) {
            $this->error("File not found: {$zip}");

            return self::FAILURE;
        }

        $admin = $this->authenticate();
        if ($admin === false) {
            return self::FAILURE;
        }

        $password = $this->option('password') ?: null;

        try {
            $this->line('Verifying the backup (this imports it into a scratch database) ...');
            $check = $restore->preflight($zip, $source?->sha256, $password);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $m = $check->manifest;
        $this->newLine();
        $this->warn('ALL CURRENT DATA WILL BE REPLACED by the backup made on '.($m['created_at'] ?? '?').'.');
        $this->line('  - Everything entered after that moment will be lost (a safety backup of the current data is taken first).');
        $this->line('  - All users will be logged out.');
        $this->newLine();

        if (! $this->option('yes')) {
            $phrase = 'RESTORE '.substr((string) ($m['created_at'] ?? ''), 0, 10);
            $typed = text("Type exactly \"{$phrase}\" to continue");
            if (trim($typed) !== $phrase) {
                $this->error('Confirmation did not match. Nothing was changed.');

                return self::FAILURE;
            }
        }

        try {
            $row = $restore->restore($zip, $admin ?: null, $password, ! $this->option('no-maintenance'), $source);
            $this->info('Restore completed. Safety backup of the previous data: '.($row->safety_backup_filename ?? 'n/a'));
            $this->line('Everyone must log in again. Check the dashboard totals against your last daily closing.');

            return self::SUCCESS;
        } catch (BackupException $e) {
            $this->error('RESTORE FAILED: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Require an administrator with the restore permission. On a brand-new empty database (disaster recovery
     * on a new PC) there is nobody to log in as, so it is skipped with a clear notice.
     *
     * @return User|false|null the admin, null when no users exist yet, false on failure
     */
    private function authenticate(): User|false|null
    {
        try {
            $noUsers = ! Schema::hasTable('users') || ! User::query()->exists();
        } catch (Throwable) {
            $noUsers = true;
        }

        if ($noUsers) {
            $this->warn('No users exist in this database yet (new installation): administrator login is skipped.');

            return null;
        }

        $username = $this->option('admin') ?: text('Administrator username');
        $user = User::query()->where('username', $username)->first();
        $pw = password('Password');

        if (! $user || ! $user->is_active || ! Hash::check($pw, $user->password) || ! $user->can('backups.restore')) {
            $this->error('Administrator authentication failed (wrong login, or the account may not restore backups).');

            return false;
        }

        return $user;
    }
}
