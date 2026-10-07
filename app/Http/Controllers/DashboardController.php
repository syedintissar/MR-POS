<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
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

        // What the shop owes its suppliers: financial information, so it needs the financial permission as well.
        $payables = null;
        if ($user->can('dashboard.financials') && $user->can('suppliers.view')) {
            $row = Supplier::query()->toBase()->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS owed, COALESCE(SUM(balance > 0), 0) AS suppliers')->first();
            $payables = [
                'owed' => (string) $row->owed,
                'suppliers' => (int) $row->suppliers,
                'open_bills' => Purchase::query()->where('status', Purchase::RECEIVED)->where('due_amount', '>', 0)->count(),
                'drafts' => Purchase::query()->where('status', Purchase::DRAFT)->count(),
            ];
        }

        return view('dashboard', [
            'stockAlerts' => $stockAlerts,
            'payables' => $payables,
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
