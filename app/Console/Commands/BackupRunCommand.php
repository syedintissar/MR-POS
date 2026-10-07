<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Services\Backup\BackupSchedule;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupVerifier;
use Illuminate\Console\Command;

class BackupRunCommand extends Command
{
    protected $signature = 'pos:backup:run {--tier=auto : auto (scheduler), manual, daily, weekly, monthly or safety}';

    protected $description = 'Create a backup now, or (tier=auto) only when one is due. Exit code 1 on any failure.';

    public function handle(BackupService $backups, BackupSchedule $schedule, BackupVerifier $verifier): int
    {
        $tier = (string) $this->option('tier');
        $failed = false;

        if ($tier === 'auto') {
            if ($schedule->dailyDue()) {
                $failed = ! $this->run1($backups, Backup::TIER_DAILY);
            } else {
                $this->line('No backup due.');
            }

            if ($candidate = $schedule->deepVerifyDue()) {
                $this->line("Running weekly restore-test verification on {$candidate->filename} ...");
                $r = $verifier->deepAndRecord($candidate);
                $this->line($r->passed ? '  deep verification passed' : '  deep verification FAILED: '.implode('; ', $r->notes));
                $failed = $failed || ! $r->passed;
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        return $this->run1($backups, $tier) ? self::SUCCESS : self::FAILURE;
    }

    private function run1(BackupService $backups, string $tier): bool
    {
        $this->line("Creating {$tier} backup ...");

        try {
            $b = $backups->create($tier);
            $this->info(sprintf('Backup OK: %s (%.1f MB, sha256 %s)', $b->filename, $b->size_bytes / 1048576, substr((string) $b->sha256, 0, 12).'…'));

            return true;
        } catch (BackupException $e) {
            $this->error('BACKUP FAILED: '.$e->getMessage());

            return false;
        }
    }
}
