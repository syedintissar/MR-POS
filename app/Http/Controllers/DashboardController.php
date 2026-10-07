<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Services\Backup\BackupHealth;
use App\Services\SettingsService;
use App\Services\SystemCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BackupHealth $health, SettingsService $settings, SystemCheck $systemCheck): View
    {
        $user = $request->user();

        $warnings = $user->can('backups.view') ? $health->warnings() : [];

        $lastBackup = $user->can('backups.view')
            ? Backup::available()->orderByDesc('started_at')->first()
            : null;

        return view('dashboard', [
            'warnings' => $warnings,
            'lastBackup' => $lastBackup,
            'shopName' => $settings->string('shop.name'),
            'dbVersion' => $this->dbVersion(),
            // Details for the person who maintains the server only.
            'systemChecks' => $user->can('settings.manage') ? $systemCheck->run() : [],
        ]);
    }

    private function dbVersion(): ?string
    {
        try {
            return (string) DB::selectOne('SELECT VERSION() AS v')->v;
        } catch (Throwable) {
            return null;
        }
    }
}
