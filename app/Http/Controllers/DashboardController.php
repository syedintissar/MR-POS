<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\ServiceJob;
use App\Models\Supplier;
use App\Services\Backup\BackupHealth;
use App\Services\Reports\DashboardService;
use App\Services\Reports\ReportFilters;
use App\Services\Service\ServiceJobAccess;
use App\Services\SettingsService;
use App\Services\SystemCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BackupHealth $health, SettingsService $settings, SystemCheck $systemCheck, DashboardService $dashboard): View
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

        // Service jobs: everybody who may see jobs sees the figures for the jobs they may see (technicians: their own).
        $service = null;
        if ($user->canAny(['service_jobs.view_all', 'service_jobs.view_assigned'])) {
            $jobs = fn () => ServiceJobAccess::visibleTo($user);
            $service = [
                'open' => $jobs()->open()->count(),
                'ready' => $jobs()->where('status', ServiceJob::COMPLETED)->count(),
                'overdue' => $jobs()->open()->where('status', '<>', ServiceJob::COMPLETED)->whereNotNull('expected_delivery_at')->where('expected_delivery_at', '<', now())->count(),
                'own' => ! $user->can('service_jobs.view_all'),
            ];
        }

        // The business at a glance: people who see every sale choose a period; profit appears only for people who may see profit.
        $glance = $user->can('sales.view_all') ? $this->glance($request, $dashboard, $user->can('reports.profit'), $user->can('dashboard.financials')) : null;
        $latest = $dashboard->latest(
            $user->can('sales.view_all'), $user->can('expenses.view') && $user->can('dashboard.financials'),
            $user->can('customer_payments.view') && $user->can('dashboard.financials'), $user->can('service_jobs.view_all'),
        );

        // A technician's own open jobs, most urgent first.
        $myJobs = null;
        if (! $user->can('service_jobs.view_all') && $user->can('service_jobs.view_assigned')) {
            $myJobs = ServiceJobAccess::visibleTo($user)->open()->with('customer:id,name,is_walk_in')
                ->orderByRaw('expected_delivery_at IS NULL')->orderBy('expected_delivery_at')->orderBy('received_at')->limit(8)->get();
        }

        return view('dashboard', [
            'glance' => $glance,
            'latest' => $latest,
            'myJobs' => $myJobs,
            'service' => $service,
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

    /** @return array<string, mixed> */
    private function glance(Request $request, DashboardService $dashboard, bool $withProfit, bool $withFinancials): array
    {
        // only plain text is accepted from the address bar (a crafted ?preset[]=x must not break the page)
        $text = fn (string $key) => is_string($request->query($key)) ? $request->query($key) : null;
        $preset = $text('preset') ?? 'today';
        $input = in_array($preset, ['today', 'yesterday', 'this_week', 'this_month', 'last_month', 'custom'], true)
            ? ['preset' => $preset, 'from' => $text('from'), 'to' => $text('to')]
            : ['preset' => 'today'];
        try {
            $filters = ReportFilters::make($input);
            if ($filters->days() > 3660) {
                throw new InvalidArgumentException('too long');
            }
        } catch (Throwable) {
            $filters = ReportFilters::make(['preset' => 'today']);
        }

        [$pFrom, $pTo] = $dashboard->previous($filters->from, $filters->to);

        return [
            'filters' => $filters,
            'presets' => ['today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Choose dates'],
            'now' => $dashboard->figures($filters->from, $filters->to, $withProfit, $withFinancials),
            'before' => $dashboard->figures($pFrom, $pTo, $withProfit, $withFinancials),
            'trend' => $dashboard->trend($filters->from, $filters->to, $withProfit),
            'year' => $dashboard->yearOnYear(),
            'top' => $dashboard->topProducts($filters->from, $filters->to),
            'withProfit' => $withProfit,
            'withFinancials' => $withFinancials,
        ];
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
