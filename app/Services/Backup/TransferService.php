<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\BackupTransfer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Copies backups to secondary / off-site destinations and remembers what still has to be copied.
 *
 * A transfer that cannot complete (USB unplugged, no internet) stays pending and is retried with
 * backoff. It never affects the local backup or the POS. Today only the "local_path" driver
 * (USB drive, second PC share) exists; cloud drivers plug into attempt() in Phase 9.
 */
class TransferService
{
    /** Minutes to wait after the 1st, 2nd, 3rd ... failed attempt. The last value repeats. */
    private const BACKOFF_MINUTES = [5, 15, 60, 180, 360];

    /** After this long without success a transfer is flagged "failed" (and keeps retrying). */
    private const FLAG_FAILED_AFTER_HOURS = 24;

    public const DRIVERS = ['local_path'];

    public function queueFor(Backup $backup): void
    {
        foreach (BackupDestination::query()->where('is_active', true)->get() as $destination) {
            BackupTransfer::query()->firstOrCreate(
                ['backup_id' => $backup->id, 'backup_destination_id' => $destination->id],
                ['status' => BackupTransfer::PENDING, 'next_attempt_at' => now()],
            );
        }
    }

    /** Try every transfer that is due. @return array{success:int,failed:int} */
    public function runDue(): array
    {
        $tally = ['success' => 0, 'failed' => 0];

        $due = BackupTransfer::query()
            ->with(['backup', 'destination'])
            ->whereIn('status', [BackupTransfer::PENDING, BackupTransfer::FAILED])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->get();

        foreach ($due as $transfer) {
            $this->attempt($transfer) ? $tally['success']++ : $tally['failed']++;
        }

        return $tally;
    }

    public function attempt(BackupTransfer $transfer): bool
    {
        $backup = $transfer->backup;
        $destination = $transfer->destination;

        $transfer->update(['status' => BackupTransfer::RUNNING, 'attempts' => $transfer->attempts + 1, 'last_attempt_at' => now()]);

        try {
            if (! $destination->is_active) {
                throw new RuntimeException('The destination is switched off.');
            }
            if ($backup->pruned_at !== null) {
                $transfer->update(['status' => BackupTransfer::FAILED, 'next_attempt_at' => null, 'error_message' => 'The local backup file was removed by retention before it could be copied.']);

                return false;
            }
            if ($destination->requires_encryption && ! $backup->encrypted) {
                throw new RuntimeException('This destination requires encrypted backups, and this backup is not encrypted. It was NOT copied.');
            }

            $remote = match ($destination->driver) {
                'local_path' => $this->copyToLocalPath($backup, (array) $destination->config),
                default => throw new RuntimeException("Destination type [{$destination->driver}] is not available yet."),
            };

            $transfer->update(['status' => BackupTransfer::SUCCESS, 'remote_path' => $remote, 'error_message' => null, 'completed_at' => now(), 'next_attempt_at' => null]);
            $destination->update(['last_success_at' => now(), 'last_error' => null]);

            return true;
        } catch (Throwable $e) {
            $minutes = self::BACKOFF_MINUTES[min($transfer->attempts, count(self::BACKOFF_MINUTES)) - 1];
            $age = $backup->finished_at ?? $backup->started_at;
            $flagFailed = $age->lt(now()->subHours(self::FLAG_FAILED_AFTER_HOURS));

            $transfer->update([
                'status' => $flagFailed ? BackupTransfer::FAILED : BackupTransfer::PENDING,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'next_attempt_at' => now()->addMinutes($minutes),
            ]);
            $destination->update(['last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::warning("Backup transfer #{$transfer->id} to [{$destination->name}] failed: ".$e->getMessage());

            return false;
        }
    }

    /** Quick reachability test used by the UI ("Test connection"). */
    public function test(BackupDestination $destination): ?string
    {
        try {
            if ($destination->driver !== 'local_path') {
                return 'Destination type is not available yet.';
            }
            $dir = $this->resolveDirectory((array) $destination->config);
            $probe = $dir.DIRECTORY_SEPARATOR.'.mrpos-write-test';
            if (@file_put_contents($probe, 'ok') === false) {
                return 'The folder exists but cannot be written to.';
            }
            @unlink($probe);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /** @param  array<string, mixed>  $config */
    private function copyToLocalPath(Backup $backup, array $config): string
    {
        $dir = $this->resolveDirectory($config).DIRECTORY_SEPARATOR.$backup->tier;
        File::ensureDirectoryExists($dir);

        $source = rtrim((string) config('mrpos.backup.root'), '\\/').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $backup->disk_path);
        if (! is_file($source)) {
            throw new RuntimeException('The local backup file is missing.');
        }

        $target = $dir.DIRECTORY_SEPARATOR.$backup->filename;
        $partial = $target.'.partial';

        if (! @copy($source, $partial)) {
            @unlink($partial);
            throw new RuntimeException('Could not write to the destination (is the drive connected and has free space?).');
        }

        // Verify the copy before declaring success.
        if (filesize($partial) !== filesize($source) || ! hash_equals((string) $backup->sha256, hash_file('sha256', $partial))) {
            @unlink($partial);
            throw new RuntimeException('The copy does not match the original (checksum differs).');
        }

        rename($partial, $target);
        @file_put_contents($target.'.sha256', $backup->sha256.'  '.$backup->filename.PHP_EOL);

        return $target;
    }

    /** @param  array<string, mixed>  $config */
    private function resolveDirectory(array $config): string
    {
        $path = rtrim((string) ($config['path'] ?? ''), '\\/');
        if ($path === '') {
            throw new RuntimeException('No folder is configured for this destination.');
        }

        // Do not create a missing drive root: if the USB drive is unplugged the drive letter does not exist.
        $parent = dirname($path);
        if (! is_dir($path) && ! is_dir($parent)) {
            throw new RuntimeException("The destination {$path} is not reachable (drive or network share not available).");
        }

        File::ensureDirectoryExists($path);

        return $path;
    }
}
