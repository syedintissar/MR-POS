<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

/**
 * Decides whether a backup is due. The decision is based on "has there been a good backup since the most
 * recent scheduled time?" and not on the clock alone, so a PC that was switched off at 21:00 still backs
 * up as soon as it is next running (catch-up).
 */
class BackupSchedule
{
    public function __construct(private readonly SettingsService $settings) {}

    /** The most recent moment at which a daily backup should have started. */
    public function latestSlot(?Carbon $now = null): Carbon
    {
        $now ??= now();
        [$h, $m] = array_map('intval', explode(':', $this->settings->string('backup.daily_time') ?: '21:00'));

        $slot = $now->copy()->setTime($h, $m, 0);

        return $slot->gt($now) ? $slot->subDay() : $slot;
    }

    public function dailyDue(?Carbon $now = null): bool
    {
        if (! $this->settings->bool('backup.enabled')) {
            return false;
        }

        $slot = $this->latestSlot($now);

        // A failed attempt does not count; it is retried on the next 15-minute tick.
        return ! Backup::query()
            ->where('tier', Backup::TIER_DAILY)
            ->where('status', Backup::STATUS_SUCCESS)
            ->where('started_at', '>=', $slot)
            ->exists();
    }

    public function deepVerifyDue(?Carbon $now = null): ?Backup
    {
        $now ??= now();
        $latest = Backup::available()->where('tier', Backup::TIER_DAILY)->orderByDesc('started_at')->first();
        if (! $latest) {
            return null;
        }

        $lastDeep = Backup::available()->where('verify_level', 'deep')->where('verify_status', 'passed')->max('verified_at');
        $stale = ! $lastDeep || Carbon::parse($lastDeep)->lt($now->copy()->subDays((int) config('mrpos.backup.deep_verify_every_days')));

        return $stale ? $latest : null;
    }
}
