<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\Product;
use App\Services\Backup\BackupHealth;
use App\Services\SettingsService;
use App\Services\SystemCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        $threshold = (string) $settings->get('inventory.low_stock_threshold');
        $stockAlerts = null;
        if ($user->can('inventory.view')) {
            $stockAlerts = InventoryController::alertCounts($threshold) + [
                'items' => Product::query()->with('unit')->where('is_active', true)->stockItems()
                    ->whereRaw('current_stock <= COALESCE(min_stock_level, ?)', [$threshold])   // out of stock (<= 0) is always <= the level
                    ->orderBy('current_stock')->limit(6)->get(),
            ];
        }
        $integrity = $user->can('inventory.adjust') ? Cache::get('integrity.last') : null;

        return view('dashboard', [
            'stockAlerts' => $stockAlerts,
            'integrity' => $integrity && ($integrity['total'] ?? 0) > 0 ? $integrity : null,
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
