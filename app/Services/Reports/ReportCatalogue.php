<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Every report the shop has: what it is called, who may open it, which filters it understands and which builder makes it.
 * Permissions follow the area (sales, stock, purchases ...); cost and profit columns inside a report have their own permissions.
 */
final class ReportCatalogue
{
    /** Filters a report understands besides the period: customer, supplier, category, user (cashier), technician, method, status. */
    public const REPORTS = [
        'sales-summary' => [
            'title' => 'Sales summary', 'group' => 'Sales', 'permission' => 'reports.sales', 'builder' => SalesReports::class, 'method' => 'summary',
            'description' => 'Invoices, discounts, tax, returns and totals for each day, week, month or year.', 'filters' => ['group'],
        ],
        'sales-register' => [
            'title' => 'Sales register', 'group' => 'Sales', 'permission' => 'reports.sales', 'builder' => SalesReports::class, 'method' => 'register',
            'description' => 'Every invoice in the period with its customer, cashier, payment and what is still owed.', 'filters' => ['customer', 'user', 'status'],
            'statuses' => ['all' => 'All invoices', 'completed' => 'Completed', 'voided' => 'Cancelled'],
        ],
        'sales-by-product' => [
            'title' => 'Sales by product', 'group' => 'Sales', 'permission' => 'reports.sales', 'builder' => SalesReports::class, 'method' => 'byProduct',
            'description' => 'What sold, how much, and (for people allowed to see it) what it cost and earned.', 'filters' => ['category'],
        ],
        'sales-by-category' => [
            'title' => 'Sales by category', 'group' => 'Sales', 'permission' => 'reports.sales', 'builder' => SalesReports::class, 'method' => 'byCategory',
            'description' => 'The same, added up for each product category.', 'filters' => [],
        ],
        'sales-by-customer' => [
            'title' => 'Sales by customer', 'group' => 'Sales', 'permission' => 'reports.sales', 'builder' => SalesReports::class, 'method' => 'byCustomer',
            'description' => 'Who buys the most, and what they still owe on those invoices.', 'filters' => [],
        ],
        'sales-by-cashier' => [
            'title' => 'Sales by cashier', 'group' => 'Sales', 'permission' => 'reports.sales', 'builder' => SalesReports::class, 'method' => 'byCashier',
            'description' => 'Invoices, discounts, cancelled invoices and returns for each cashier.', 'filters' => [],
        ],
        'sales-returns' => [
            'title' => 'Sales returns', 'group' => 'Sales', 'permission' => 'reports.sales', 'builder' => SalesReports::class, 'method' => 'returns',
            'description' => 'Goods customers brought back, and how each return was settled.', 'filters' => ['customer', 'status'],
            'statuses' => ['posted' => 'Recorded', 'voided' => 'Cancelled', 'all' => 'All'],
        ],
        'top-and-slow' => [
            'title' => 'Top sellers and slow movers', 'group' => 'Stock', 'permission' => 'reports.inventory', 'builder' => InventoryReports::class, 'method' => 'movers',
            'description' => 'The 20 best sellers of the period, and stock on the shelf that did not sell at all.', 'filters' => [],
        ],
        'stock-valuation' => [
            'title' => 'Stock valuation', 'group' => 'Stock', 'permission' => 'reports.inventory', 'builder' => InventoryReports::class, 'method' => 'valuation',
            'description' => 'What is on the shelf now and what it is worth (cost for people allowed to see it).', 'filters' => ['category', 'status'], 'asAt' => true,
            'statuses' => ['in_stock' => 'Only products in stock', 'all' => 'All products'],
        ],
        'purchases' => [
            'title' => 'Purchases and purchase returns', 'group' => 'Purchases and suppliers', 'permission' => 'reports.purchases', 'builder' => PurchaseReports::class, 'method' => 'register',
            'description' => 'Purchases received in the period, and the goods sent back to suppliers.', 'filters' => ['supplier', 'status'],
            'statuses' => ['all' => 'All purchases', 'received' => 'Received', 'voided' => 'Cancelled'],
        ],
        'supplier-balances' => [
            'title' => 'Supplier balances (payables)', 'group' => 'Purchases and suppliers', 'permission' => 'reports.suppliers', 'builder' => PeopleReports::class, 'method' => 'supplierBalances',
            'description' => 'What the shop owes each supplier, aged by how old the unpaid purchases are.', 'filters' => ['supplier'], 'asAt' => true,
        ],
        'customer-balances' => [
            'title' => 'Customer balances (receivables)', 'group' => 'Customers', 'permission' => 'reports.customers', 'builder' => PeopleReports::class, 'method' => 'customerBalances',
            'description' => 'What each customer owes the shop, aged by how old the unpaid invoices are.', 'filters' => ['customer'], 'asAt' => true,
        ],
        'service-jobs' => [
            'title' => 'Service jobs and technicians', 'group' => 'Service', 'permission' => 'reports.service', 'builder' => ServiceReports::class, 'method' => 'jobs',
            'description' => 'Jobs received in the period, and what each technician took in, finished and handed over.', 'filters' => ['technician', 'status'],
            'statuses' => [],   // filled from ServiceJob::STATUSES below
        ],
        'payments-by-method' => [
            'title' => 'Money in and out by payment method', 'group' => 'Money', 'permission' => 'reports.sales', 'builder' => SalesReports::class, 'method' => 'payments',
            'description' => 'Cash, bank and wallet movements: from customers and suppliers, refunds, and expenses.', 'filters' => [],
        ],
        'expenses' => [
            'title' => 'Expenses', 'group' => 'Money', 'permission' => 'reports.expenses', 'builder' => FinanceReports::class, 'method' => 'expenses',
            'description' => 'Every expense in the period, and the total for each category.', 'filters' => ['category', 'method', 'status'],
            'statuses' => ['posted' => 'Recorded', 'voided' => 'Cancelled', 'all' => 'All'],
        ],
        'daily-closing' => [
            'title' => 'Closing summary', 'group' => 'Money', 'permission' => 'reports.sales', 'builder' => FinanceReports::class, 'method' => 'closing',
            'description' => 'The day in numbers, and the money to count in the drawer. Choose "Today" (the default) at closing time.', 'filters' => [], 'preset' => 'today',
        ],
        'profit' => [
            'title' => 'Profit', 'group' => 'Money', 'permission' => 'reports.profit', 'builder' => FinanceReports::class, 'method' => 'profit',
            'description' => 'Gross and net profit including service jobs, by day, week, month or year.', 'filters' => ['group'], 'group_default' => 'month',
        ],
    ];

    /** @return array{title: string, group: string, permission: string, builder: class-string, method: string, description: string, filters: list<string>, statuses?: array<string, string>, asAt?: bool, preset?: string, group_default?: string} */
    public static function find(string $key): array
    {
        $def = self::REPORTS[$key] ?? throw new InvalidArgumentException("Unknown report [{$key}].");
        if ($key === 'service-jobs') {
            $def['statuses'] = ['' => 'All jobs'] + ServiceJob::STATUSES;
        }

        return $def;
    }

    /** @return array<string, list<array{key: string, title: string, description: string}>> reports the person may open, by area */
    public static function visibleTo(User $user): array
    {
        $out = [];
        foreach (self::REPORTS as $key => $def) {
            if ($user->can($def['permission'])) {
                $out[$def['group']][] = ['key' => $key, 'title' => $def['title'], 'description' => $def['description']];
            }
        }

        return $out;
    }

    public static function canOpenAny(User $user): bool
    {
        return $user->canAny(array_values(array_unique(array_column(self::REPORTS, 'permission'))));
    }

    public function build(string $key, ReportFilters $filters, User $user): ReportResult
    {
        $def = self::find($key);
        if (! $user->can($def['permission'])) {
            throw new AuthorizationException('You are not allowed to open this report.');
        }

        // the result is cut down to what this person may see before anything leaves this class
        return app($def['builder'])->{$def['method']}($filters)->forUser($user);
    }
}
