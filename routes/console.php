<?php

use App\Services\Backup\BackupHealth;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Windows Task Scheduler runs `php artisan schedule:run` every minute (see docs/INSTALLATION.md).
*/

// Proof of life: the dashboard warns loudly if this stops, because then automatic backups would silently stop too.
Schedule::call(fn () => BackupHealth::touchHeartbeat())->everyMinute()->name('scheduler-heartbeat');

// Runs a backup only when one is due (including catch-up after the PC was switched off), and the weekly restore test.
Schedule::command('pos:backup:run --tier=auto')->everyFifteenMinutes()->withoutOverlapping(120)->name('backup-auto');

// Retry copies to the USB drive / second PC / cloud that could not complete earlier.
Schedule::command('pos:backup:retry-transfers')->everyFifteenMinutes()->withoutOverlapping(30)->name('backup-transfers');

// Tamper check of the audit trail.
Schedule::command('pos:audit:verify')->dailyAt('02:30')->name('audit-verify');

// Recompute cached stock totals from the ledger and report any difference (twice a day, in case the PC was off at night).
Schedule::command('pos:integrity:check')->twiceDaily(2, 14)->name('integrity-check');
