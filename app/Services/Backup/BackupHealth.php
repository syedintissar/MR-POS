<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\BackupTransfer;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

/**
 * Turns the backup tables into plain-language warnings for the dashboard banner and the Backups page.
 * Severity: critical (act today), warning (act soon).
 */
class BackupHealth
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return list<array{level:string,message:string}> */
    public function warnings(): array
    {
        $w = [];
        $add = function (string $level, string $message) use (&$w) {
            $w[] = ['level' => $level, 'message' => $message];
        };

        $lastOk = Backup::query()->where('status', Backup::STATUS_SUCCESS)->where('verify_status', '!=', 'failed')->max('started_at');
        $hours = $this->settings->int('backup.alert_after_hours');

        if (! $lastOk) {
            $add('critical', 'No backup has ever completed. Run "Backup Now" and set up automatic backups.');
        } elseif (Carbon::parse($lastOk)->lt(now()->subHours($hours))) {
            $add('critical', 'No successful backup in the last '.$hours.' hours (last good backup: '.Carbon::parse($lastOk)->diffForHumans().').');
        }

        $latest = Backup::query()->where('status', '!=', Backup::STATUS_RUNNING)->orderByDesc('started_at')->orderByDesc('id')->first();
        if ($latest && $latest->status === Backup::STATUS_FAILED) {
            $add('critical', 'The last backup attempt FAILED '.$latest->started_at->diffForHumans().': '.mb_substr((string) $latest->error_message, 0, 200));
        }

        if (Backup::query()->where('status', Backup::STATUS_RUNNING)->where('started_at', '<', now()->subHours(2))->exists()) {
            $add('warning', 'A backup has been "running" for over 2 hours. It may have been interrupted.');
        }

        $bad = Backup::available()->where('verify_status', 'failed')->count();
        if ($bad > 0) {
            $add('critical', "{$bad} backup(s) failed verification and may be unusable. Open the Backups page for details.");
        }

        if ($lastOk) {
            $lastDeep = Backup::available()->where('verify_level', 'deep')->where('verify_status', 'passed')->max('verified_at');
            if (! $lastDeep || Carbon::parse($lastDeep)->lt(now()->subDays((int) config('mrpos.backup.deep_verify_every_days') + 1))) {
                $add('warning', 'No full restore-test verification has passed in the last '.((int) config('mrpos.backup.deep_verify_every_days') + 1).' days.');
            }
        }

        $stuck = BackupTransfer::query()->whereIn('status', [BackupTransfer::PENDING, BackupTransfer::FAILED])
            ->where('created_at', '<', now()->subHours(48))->count();
        if ($stuck > 0) {
            $add('warning', "{$stuck} off-site copy(ies) have been waiting for more than 48 hours. Check that the destination is connected.");
        }

        foreach (BackupDestination::query()->where('is_active', true)->whereNotNull('last_error')->get() as $d) {
            $add('warning', "Backup destination \"{$d->name}\" is not reachable: ".mb_substr((string) $d->last_error, 0, 160));
        }

        if (! BackupDestination::query()->where('is_active', true)->exists()) {
            $add('warning', 'Backups are only stored on this computer. Add a second destination (USB drive or another PC) so a disk failure or theft cannot lose your data.');
        }

        $this->diskWarning($add);

        if ($this->settings->bool('backup.enabled') && $this->heartbeatStale()) {
            $add('critical', 'The scheduled-task runner is not running, so automatic backups will NOT happen. See the installation guide (Task Scheduler).');
        }

        usort($w, fn ($a, $b) => strcmp($a['level'], $b['level']));

        return $w;
    }

    public function hasCritical(): bool
    {
        return collect($this->warnings())->contains('level', 'critical');
    }

    /** Called every minute by the scheduler. */
    public static function touchHeartbeat(): void
    {
        $file = (string) config('mrpos.backup.heartbeat_file');
        @mkdir(dirname($file), 0775, true);
        @file_put_contents($file, (string) now()->timestamp);
    }

    public function heartbeatStale(): bool
    {
        $file = (string) config('mrpos.backup.heartbeat_file');
        if (! is_file($file)) {
            return true;
        }

        return (time() - (int) file_get_contents($file)) > ((int) config('mrpos.backup.heartbeat_stale_minutes') * 60);
    }

    private function diskWarning(callable $add): void
    {
        $root = (string) config('mrpos.backup.root');
        $probe = is_dir($root) ? $root : dirname($root);
        $free = @disk_free_space($probe);
        $total = @disk_total_space($probe);

        if ($free === false || ! $total) {
            return;
        }

        if ($free < (int) config('mrpos.backup.min_free_bytes') || ($free / $total * 100) < (int) config('mrpos.backup.min_free_percent')) {
            $add('warning', sprintf('The backup disk is running low on space (%.1f GB free).', $free / 1073741824));
        }
    }
}
