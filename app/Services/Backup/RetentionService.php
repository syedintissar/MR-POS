<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the newest N successful backups per tier and removes the rest.
 *
 * Safeguards: protected backups, the newest deep-verified backup, and the single newest successful backup
 * are never removed. This only runs after a *successful* backup, so a broken backup job can never
 * delete the last good copies.
 */
class RetentionService
{
    private const SAFETY_MIN_DAYS = 30;

    public function __construct(private readonly SettingsService $settings, private readonly AuditLogger $audit) {}

    /** @return int number of backups pruned */
    public function apply(): int
    {
        $limits = [
            Backup::TIER_DAILY => $this->settings->int('backup.retention_daily'),
            Backup::TIER_WEEKLY => $this->settings->int('backup.retention_weekly'),
            Backup::TIER_MONTHLY => $this->settings->int('backup.retention_monthly'),
            Backup::TIER_MANUAL => $this->settings->int('backup.retention_manual'),
            Backup::TIER_SAFETY => $this->settings->int('backup.retention_safety'),
        ];

        $keepIds = $this->neverPrune();
        $pruned = 0;

        foreach ($limits as $tier => $keep) {
            $candidates = Backup::available()
                ->where('tier', $tier)
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->get()
                ->slice($keep);

            foreach ($candidates as $backup) {
                if (in_array($backup->id, $keepIds, true) || $backup->is_protected) {
                    continue;
                }
                if ($tier === Backup::TIER_SAFETY && $backup->started_at->gt(now()->subDays(self::SAFETY_MIN_DAYS))) {
                    continue;
                }

                $this->prune($backup);
                $pruned++;
            }
        }

        $this->cleanTemp();

        return $pruned;
    }

    /** @return list<int> */
    private function neverPrune(): array
    {
        $ids = [];

        $newestOk = Backup::available()->orderByDesc('started_at')->orderByDesc('id')->value('id');
        if ($newestOk) {
            $ids[] = $newestOk;
        }

        $newestDeep = Backup::available()->where('verify_status', 'passed')->where('verify_level', 'deep')->orderByDesc('started_at')->value('id');
        if ($newestDeep) {
            $ids[] = $newestDeep;
        }

        return $ids;
    }

    private function prune(Backup $backup): void
    {
        $root = rtrim((string) config('mrpos.backup.root'), '\\/');
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $backup->disk_path);

        // A file shared by two rows (same tier + name) must not happen, but never delete a file another row still uses.
        $stillUsed = Backup::available()->where('disk_path', $backup->disk_path)->where('id', '!=', $backup->id)->exists();

        if (! $stillUsed) {
            @unlink($path);
            @unlink($path.'.sha256');
        }

        $backup->update(['pruned_at' => now()]);
        Log::info("Backup pruned by retention: {$backup->disk_path}");
    }

    /** Remove abandoned working folders (a crash can leave one behind). */
    private function cleanTemp(): void
    {
        $tmp = rtrim((string) config('mrpos.backup.root'), '\\/').DIRECTORY_SEPARATOR.'tmp';
        if (! is_dir($tmp)) {
            return;
        }

        foreach (File::directories($tmp) as $dir) {
            if (filemtime($dir) < now()->subDay()->getTimestamp()) {
                File::deleteDirectory($dir);
            }
        }
    }
}
