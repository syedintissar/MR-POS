<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Safe restore:
 *   verify (deep) → compatibility check → [maintenance mode] → safety backup of the CURRENT data →
 *   wipe + import → restore uploaded files → migrate → clear sessions/cache → catalogue + audit → [back up].
 *
 * If the import fails, the safety backup is imported again (automatic rollback).
 * Authorisation (Admin + password) is the caller's job; this class does the work and reports honestly.
 */
class RestoreService
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupVerifier $verifier,
        private readonly DatabaseDumper $dumper,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Run checks only (no changes): used by the confirmation screen and by restore() itself.
     *
     * @throws BackupException when the backup must not be restored
     */
    public function preflight(string $zipPath, ?string $expectedSha = null, ?string $password = null): VerificationResult
    {
        $result = $this->verifier->deepFile($zipPath, $expectedSha, $password);
        if (! $result->passed) {
            throw new BackupException('This backup failed verification and will not be restored: '.implode('; ', $result->notes));
        }

        $this->assertCompatible($result->manifest);

        return $result;
    }

    /** A backup made by a NEWER version of the application cannot be loaded by an older one. */
    private function assertCompatible(array $manifest): void
    {
        $known = collect(File::files(database_path('migrations')))
            ->map(fn ($f) => $f->getFilenameWithoutExtension())
            ->all();

        $unknown = array_diff($manifest['migrations'] ?? [], $known);
        if ($unknown) {
            throw new BackupException('This backup was made by a newer version of the application (unknown database changes: '
                .implode(', ', array_slice($unknown, 0, 3)).'). Upgrade the application first, then restore.');
        }
    }

    /**
     * @param  bool  $maintenance  put the app into maintenance mode while restoring (disable only in tests)
     *
     * @throws BackupException
     */
    public function restore(string $zipPath, ?User $by = null, ?string $password = null, bool $maintenance = true, ?Backup $source = null): BackupRestore
    {
        $lock = Cache::lock('restore-running', 7200);
        if (! $lock->get()) {
            throw new BackupException('A restore is already running.');
        }

        $startedAt = now();
        $sourceName = basename($zipPath);
        $safety = null;
        $tmp = null;
        $inMaintenance = false;
        $wiped = false;

        try {
            $this->preflight($zipPath, $source?->sha256, $password);

            if ($maintenance) {
                Artisan::call('down', ['--retry' => 60]);
                $inMaintenance = true;
            }

            // The safety backup is mandatory whenever there is data to protect. If it cannot be made,
            // nothing has been changed yet and we stop. A brand-new empty database (restoring onto a
            // replacement PC) has nothing to protect, so there is nothing to back up first.
            if ($this->liveDatabaseHasTables()) {
                try {
                    $safety = $this->backups->create(Backup::TIER_SAFETY, $by, applyRetention: false);
                } catch (BackupException $e) {
                    throw new BackupException('Restore cancelled before any change: the safety backup of the current data could not be made ('.$e->getMessage().').', 0, $e);
                }
            }

            $tmp = rtrim((string) config('mrpos.backup.root'), '\\/').DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'restore-'.Str::uuid();
            File::ensureDirectoryExists($tmp);

            $this->extract($zipPath, $tmp, $password);

            try {
                $wiped = true;
                $this->dumper->wipeTables();
                $this->dumper->import($tmp.DIRECTORY_SEPARATOR.'database.sql', $this->liveDatabase(), $tmp);
            } catch (Throwable $e) {
                if ($safety === null) {
                    throw new BackupException('The restore failed. The database was empty before, so there was nothing to put back. Reason: '.$e->getMessage(), 0, $e);
                }

                $this->rollbackTo($safety, $by);
                $this->recordRestore($source, $sourceName, $safety, 'rolled_back', $startedAt, $by, $e->getMessage());

                throw new BackupException('The restore failed and the previous data has been put back automatically. Reason: '.$e->getMessage(), 0, $e);
            }

            $this->restoreFiles($tmp.DIRECTORY_SEPARATOR.'files');

            Artisan::call('migrate', ['--force' => true]);

            DB::table('sessions')->truncate();
            Cache::flush();
            $this->settings->flush();

            $this->backups->resyncCatalogue();

            $restore = $this->recordRestore($source, $sourceName, $safety, 'success', $startedAt, $by);

            return $restore;
        } catch (BackupException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($wiped && $safety) {
                try {
                    $this->rollbackTo($safety, $by);
                } catch (Throwable $inner) {
                    throw new BackupException('Restore failed AND the automatic rollback failed. Restore manually from the safety backup '.($safety->filename ?? '').'. Details: '.$inner->getMessage(), 0, $e);
                }
            }

            throw new BackupException('Restore failed: '.$e->getMessage(), 0, $e);
        } finally {
            if ($tmp) {
                File::deleteDirectory($tmp);
            }
            if ($inMaintenance) {
                Artisan::call('up');
            }
            $lock->release();
        }
    }

    private function liveDatabaseHasTables(): bool
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?', [$this->liveDatabase()])->c > 0;
    }

    private function liveDatabase(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }

    private function extract(string $zipPath, string $dest, ?string $password): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new BackupException('The backup archive cannot be opened.');
        }

        $password ??= $this->settings->string('backup.encryption_password') ?: null;
        if ($password) {
            $zip->setPassword($password);
        }

        // Zip-slip protection: refuse any entry that could land outside the destination.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('/^[A-Za-z]:/', $name)) {
                $zip->close();
                throw new BackupException("The backup contains an unsafe file path ({$name}) and was rejected.");
            }
        }

        if (! $zip->extractTo($dest)) {
            $zip->close();
            throw new BackupException('The backup archive could not be extracted (wrong backup password, or disk full).');
        }
        $zip->close();
    }

    private function restoreFiles(string $extracted): void
    {
        $live = (string) config('mrpos.files_path');

        // Keep what is there: move it aside instead of deleting it.
        if (is_dir($live)) {
            $aside = $live.'.pre-restore-'.now()->format('Ymd-His');
            if (! @rename($live, $aside)) {
                throw new BackupException('Could not move the current uploaded files aside.');
            }
        }

        if (is_dir($extracted)) {
            if (! @rename($extracted, $live)) {
                File::copyDirectory($extracted, $live);
            }
        } else {
            File::ensureDirectoryExists($live);
        }
    }

    /** Put the pre-restore data back from the safety backup. */
    private function rollbackTo(Backup $safety, ?User $by): void
    {
        $zip = $this->backups->absolutePath($safety);
        $tmp = rtrim((string) config('mrpos.backup.root'), '\\/').DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'rollback-'.Str::uuid();
        File::ensureDirectoryExists($tmp);

        try {
            $this->extract($zip, $tmp, null);
            $this->dumper->wipeTables();
            $this->dumper->import($tmp.DIRECTORY_SEPARATOR.'database.sql', $this->liveDatabase(), $tmp);
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    private function recordRestore(?Backup $source, string $sourceName, ?Backup $safety, string $status, \DateTimeInterface $startedAt, ?User $by, ?string $error = null): BackupRestore
    {
        // After a restore the database may be an older copy: make sure the tables we write to exist.
        if (! Schema::hasTable('backup_restores')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        // The acting user may not exist in an older restored database (foreign keys would reject the rows).
        $actor = ($by && User::query()->whereKey($by->getKey())->exists()) ? $by : null;

        $row = BackupRestore::create([
            'backup_id' => $source?->id,
            'source_filename' => $sourceName,
            'safety_backup_filename' => $safety?->filename,
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'performed_by' => $actor?->id,
            'error_message' => $error,
        ]);

        // Written into the restored database and into a file that a restore can never overwrite.
        try {
            $this->audit->log(
                $status === 'success' ? 'restore_performed' : 'restore_'.$status,
                'backups',
                "Restore from {$sourceName}: {$status}".($error ? " ({$error})" : ''),
                new: ['source' => $sourceName, 'safety_backup' => $safety?->filename, 'status' => $status, 'performed_by' => $by?->username],
                user: $actor,
            );
        } catch (Throwable) {
            // The audit table may not exist if the restore rolled back oddly; the log file below still records it.
        }

        File::append(storage_path('logs/restore.log'), sprintf(
            "[%s] restore %s from %s (safety backup: %s) by %s%s\n",
            now()->toDateTimeString(), $status, $sourceName, $safety?->filename ?? 'none', $by?->username ?? 'console', $error ? " - {$error}" : '',
        ));

        return $row;
    }
}
