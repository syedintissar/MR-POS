<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Creates a backup archive:  manifest.json + database.sql + files/…  (optionally AES-256 encrypted).
 *
 * Any failure is recorded (backups.status = failed, audit log, application log) and re-thrown as
 * BackupException so the caller (command, scheduler, UI) can report it. Failures are never swallowed.
 */
class BackupService
{
    public const TIERS = ['daily', 'weekly', 'monthly', 'manual', 'safety', 'uploaded'];

    public function __construct(
        private readonly ToolLocator $tools,
        private readonly DatabaseDumper $dumper,
        private readonly BackupVerifier $verifier,
        private readonly RetentionService $retention,
        private readonly TransferService $transfers,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function root(): string
    {
        return rtrim((string) config('mrpos.backup.root'), '\\/');
    }

    public function tierPath(string $tier): string
    {
        return $this->root().DIRECTORY_SEPARATOR.$tier;
    }

    public function absolutePath(Backup $backup): string
    {
        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $backup->disk_path);
    }

    /**
     * @param  bool  $applyRetention  false for safety backups taken inside a restore
     *
     * @throws BackupException
     */
    public function create(string $tier = Backup::TIER_MANUAL, ?User $by = null, bool $applyRetention = true): Backup
    {
        if (! in_array($tier, self::TIERS, true)) {
            throw new BackupException("Unknown backup tier [{$tier}].");
        }

        $lock = Cache::lock('backup-running', 7200);
        if (! $lock->get()) {
            throw new BackupException('Another backup is already running. Try again in a few minutes.');
        }

        $includeFiles = $this->settings->bool('backup.include_files');
        $encrypt = $this->settings->bool('backup.encryption_enabled');
        $password = $encrypt ? $this->settings->string('backup.encryption_password') : null;

        $backup = Backup::create([
            'tier' => $tier,
            'status' => Backup::STATUS_RUNNING,
            'started_at' => now(),
            'triggered_by' => $by?->id,
            'app_version' => config('mrpos.version'),
            'includes_files' => $includeFiles,
            'encrypted' => $encrypt,
        ]);

        $tmp = null;
        $zipPath = null;

        try {
            if ($encrypt && ! $password) {
                throw new BackupException('Backup encryption is on but no backup password is set. Set one in the backup settings.');
            }

            $this->prepareDirectories();
            $this->assertDiskSpace();

            $tmp = $this->root().DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.(string) Str::uuid();
            File::ensureDirectoryExists($tmp);

            $before = $this->rowCounts();
            $this->dumper->dump($tmp.DIRECTORY_SEPARATOR.'database.sql', $tmp);
            $after = $this->rowCounts();

            $files = [];
            if ($includeFiles) {
                $files = $this->stageFiles($tmp.DIRECTORY_SEPARATOR.'files');
            }

            $manifest = [
                'app' => 'MR-POS',
                'app_version' => config('mrpos.version'),
                'created_at' => now()->toIso8601String(),
                'tier' => $tier,
                'database' => config('database.connections.'.config('database.default').'.database'),
                'db_server' => DB::selectOne('SELECT VERSION() AS v')->v,
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'row_counts_before' => $before,
                'row_counts_after' => $after,
                'includes_files' => $includeFiles,
                'encrypted' => $encrypt,
                'database_sql_sha256' => hash_file('sha256', $tmp.DIRECTORY_SEPARATOR.'database.sql'),
                'files' => $files,
            ];

            $filename = 'mrpos_'.now()->format('Y-m-d_His').'_'.$tier.'.zip';
            $zipPath = $this->tierPath($tier).DIRECTORY_SEPARATOR.$filename;
            if (is_file($zipPath)) {
                $filename = 'mrpos_'.now()->format('Y-m-d_His').'_'.$tier.'_'.Str::lower(Str::random(4)).'.zip';
                $zipPath = $this->tierPath($tier).DIRECTORY_SEPARATOR.$filename;
            }

            $this->buildZip($zipPath, $tmp, $manifest, $password);

            $sha = hash_file('sha256', $zipPath);
            file_put_contents($zipPath.'.sha256', $sha.'  '.$filename.PHP_EOL);

            $backup->update([
                'status' => Backup::STATUS_SUCCESS,
                'filename' => $filename,
                'disk_path' => $tier.'/'.$filename,
                'size_bytes' => filesize($zipPath),
                'sha256' => $sha,
                'db_schema_version' => (string) end($manifest['migrations']),
                'row_counts' => $after,
                'finished_at' => now(),
            ]);

            $result = $this->verifier->quickAndRecord($backup);
            if (! $result->passed) {
                throw new BackupException('The backup was written but failed verification: '.implode('; ', $result->notes));
            }

            $this->audit->log('backup_created', 'backups', "Backup created ({$tier}): {$filename}", $backup, new: [
                'tier' => $tier, 'size_bytes' => $backup->size_bytes, 'sha256' => $sha, 'encrypted' => $encrypt,
            ], user: $by);

            if ($tier === Backup::TIER_DAILY) {
                $this->promote($backup);
            }

            $this->transfers->queueFor($backup);
            $this->transfers->runDue();

            if ($applyRetention) {
                $this->retention->apply();
            }

            return $backup->refresh();
        } catch (Throwable $e) {
            $this->recordFailure($backup, $zipPath, $e, $by);

            throw $e instanceof BackupException ? $e : new BackupException('Backup failed: '.$e->getMessage(), 0, $e);
        } finally {
            if ($tmp !== null) {
                File::deleteDirectory($tmp);
            }
            $lock->release();
        }
    }

    /** Copy a successful daily backup into the weekly / monthly tiers when due (grandfather-father-son). */
    private function promote(Backup $daily): void
    {
        $weeklyDay = (int) $this->settings->get('backup.weekly_day');
        $source = $this->absolutePath($daily);

        $hasRecentWeekly = Backup::available()->where('tier', Backup::TIER_WEEKLY)->where('started_at', '>=', now()->subDays(7))->exists();
        if (now()->dayOfWeek === $weeklyDay || ! $hasRecentWeekly) {
            $this->copyAsTier($daily, $source, Backup::TIER_WEEKLY);
        }

        $hasMonthly = Backup::available()->where('tier', Backup::TIER_MONTHLY)->where('started_at', '>=', now()->startOfMonth())->exists();
        if (! $hasMonthly) {
            $this->copyAsTier($daily, $source, Backup::TIER_MONTHLY);
        }
    }

    private function copyAsTier(Backup $original, string $source, string $tier): void
    {
        File::ensureDirectoryExists($this->tierPath($tier));
        $filename = Str::replaceLast('_daily', '_'.$tier, (string) $original->filename);
        $target = $this->tierPath($tier).DIRECTORY_SEPARATOR.$filename;

        if (! copy($source, $target)) {
            Log::error("Could not copy backup to the {$tier} tier.");

            return;
        }
        file_put_contents($target.'.sha256', $original->sha256.'  '.$filename.PHP_EOL);

        $copy = $original->replicate(['id', 'created_at', 'updated_at']);
        $copy->fill(['tier' => $tier, 'filename' => $filename, 'disk_path' => $tier.'/'.$filename, 'is_protected' => false]);
        $copy->save();

        $this->transfers->queueFor($copy);
    }

    private function prepareDirectories(): void
    {
        foreach (array_merge(self::TIERS, ['tmp']) as $dir) {
            File::ensureDirectoryExists($this->root().DIRECTORY_SEPARATOR.$dir);
        }
    }

    private function assertDiskSpace(): void
    {
        $free = @disk_free_space($this->root());
        if ($free === false) {
            return;
        }

        $last = (int) Backup::query()->where('status', Backup::STATUS_SUCCESS)->latest('started_at')->value('size_bytes');
        $needed = max(200 * 1024 * 1024, $last * 3);

        if ($free < $needed) {
            throw new BackupException(sprintf(
                'Not enough free disk space for a backup (%.0f MB free, about %.0f MB needed).',
                $free / 1048576,
                $needed / 1048576,
            ));
        }
    }

    /** @return array<string, int> table => exact row count */
    public function rowCounts(): array
    {
        $db = config('database.connections.'.config('database.default').'.database');
        $tables = DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = "BASE TABLE" ORDER BY table_name', [$db]);

        $counts = [];
        foreach ($tables as $row) {
            $counts[$row->t] = (int) DB::table($row->t)->count();
        }

        return $counts;
    }

    /** @return array<string, string> relative path => sha256 */
    private function stageFiles(string $stageDir): array
    {
        $source = (string) config('mrpos.files_path');
        File::ensureDirectoryExists($stageDir);
        $map = [];

        if (! is_dir($source)) {
            return $map;
        }

        $root = realpath($source);
        foreach (File::allFiles($source) as $file) {
            $real = $file->getRealPath();
            $rel = str_replace('\\', '/', ltrim(substr($real, strlen($root)), '\\/'));

            // Never recurse into the backups themselves, and skip temp/hidden files.
            if (str_starts_with($rel, 'backups/') || str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            $dest = $stageDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            File::ensureDirectoryExists(dirname($dest));
            copy($real, $dest);
            $map[$rel] = hash_file('sha256', $dest);
        }

        return $map;
    }

    /** @param  array<string, mixed>  $manifest */
    private function buildZip(string $zipPath, string $tmp, array $manifest, ?string $password): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('Could not create the backup archive.');
        }

        $entries = [];
        if ($password) {
            $zip->setPassword($password);
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $entries[] = 'manifest.json';
        $zip->addFile($tmp.DIRECTORY_SEPARATOR.'database.sql', 'database.sql');
        $entries[] = 'database.sql';

        foreach (array_keys($manifest['files']) as $rel) {
            $zip->addFile($tmp.DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel), 'files/'.$rel);
            $entries[] = 'files/'.$rel;
        }

        if ($password) {
            foreach ($entries as $name) {
                $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
            }
        }

        if (! $zip->close()) {
            @unlink($zipPath);
            throw new BackupException('Could not finish writing the backup archive (disk full?).');
        }
    }

    private function recordFailure(Backup $backup, ?string $zipPath, Throwable $e, ?User $by): void
    {
        if ($zipPath && is_file($zipPath)) {
            @unlink($zipPath);
            @unlink($zipPath.'.sha256');
        }

        Log::error('Backup failed: '.$e->getMessage(), ['backup_id' => $backup->id]);

        try {
            $backup->update([
                'status' => Backup::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
                'filename' => null,
                'disk_path' => null,
            ]);
            $this->audit->log('backup_failed', 'backups', 'Backup FAILED ('.$backup->tier.'): '.mb_substr($e->getMessage(), 0, 300), $backup, user: $by);
        } catch (Throwable $inner) {
            Log::critical('Could not record the backup failure: '.$inner->getMessage());
        }
    }

    /**
     * Re-register backup files that exist on disk but not in the backups table (a restore replaces the table
     * with the copy that was inside the backup, which does not know about newer backups).
     *
     * "Running" rows in a restored table are snapshots of the backup that was in progress when the dump was
     * taken, not real failures. They are removed; the finished file is re-registered from disk below.
     *
     * @return int number of files re-registered
     */
    public function resyncCatalogue(): int
    {
        Backup::query()->where('status', Backup::STATUS_RUNNING)->delete();

        $added = 0;
        foreach (self::TIERS as $tier) {
            $dir = $this->tierPath($tier);
            if (! is_dir($dir)) {
                continue;
            }

            foreach (File::glob($dir.DIRECTORY_SEPARATOR.'mrpos_*.zip') as $file) {
                $name = basename($file);
                if (Backup::query()->where('tier', $tier)->where('filename', $name)->exists()) {
                    continue;
                }

                $sha = is_file($file.'.sha256') ? strtok((string) file_get_contents($file.'.sha256'), ' ') : hash_file('sha256', $file);
                Backup::create([
                    'tier' => $tier,
                    'status' => Backup::STATUS_SUCCESS,
                    'filename' => $name,
                    'disk_path' => $tier.'/'.$name,
                    'size_bytes' => filesize($file),
                    'sha256' => $sha,
                    'started_at' => Carbon::createFromTimestamp(filemtime($file)),
                    'finished_at' => Carbon::createFromTimestamp(filemtime($file)),
                    'verify_status' => 'pending',
                    'verify_notes' => 'Re-registered from disk after a restore.',
                ]);
                $added++;
            }
        }

        return $added;
    }
}
