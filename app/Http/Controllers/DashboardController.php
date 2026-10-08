<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
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

        // Today's sales: everybody who may see sales sees the figure for the sales they may see (cashiers: their own).
        $salesToday = null;
        if ($user->can('sales.view_all') || $user->can('sales.view_own')) {
            $row = Sale::query()->completed()->whereDate('sale_date', now()->toDateString())
                ->when(! $user->can('sales.view_all'), fn ($q) => $q->where('user_id', $user->id))
                ->toBase()->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS total')->first();
            $salesToday = ['count' => (int) $row->n, 'total' => (string) $row->total, 'own' => ! $user->can('sales.view_all')];
        }

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

        // What customers owe the shop (unpaid invoices and opening balances): also financial information.
        $receivables = null;
        if ($user->can('dashboard.financials') && $user->can('customers.view')) {
            $row = Customer::query()->where('is_walk_in', false)->toBase()
                ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS owed, COALESCE(SUM(balance > 0), 0) AS customers')->first();
            $receivables = [
                'owed' => (string) $row->owed,
                'customers' => (int) $row->customers,
                'open_invoices' => Sale::query()->completed()->where('due_amount', '>', 0)->count(),
            ];
        }

        return view('dashboard', [
            'stockAlerts' => $stockAlerts,
            'payables' => $payables,
            'receivables' => $receivables,
            'salesToday' => $salesToday,
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
