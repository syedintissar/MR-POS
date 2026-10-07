<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for permission names and the default role matrix.
 * Mirrors docs/RBAC.md. Code checks these names; the admin can only assign them.
 */
final class PermissionCatalogue
{
    public const ADMIN = 'Admin';

    public const MANAGER = 'Manager';

    public const CASHIER = 'Cashier';

    public const TECHNICIAN = 'Technician';

    /** @return array<string, array<string, string>> module label => [permission => description] */
    public static function modules(): array
    {
        return [
            'Dashboard' => [
                'dashboard.view' => 'See the dashboard (counts, sales)',
                'dashboard.financials' => 'See profit, cost, payables and receivables cards',
            ],
            'POS / Sales' => [
                'pos.access' => 'Open the POS screen',
                'sales.create' => 'Complete sales',
                'sales.view_all' => 'See all invoices',
                'sales.view_own' => 'See own invoices',
                'sales.hold' => 'Hold and resume carts',
                'sales.reprint' => 'Reprint invoices',
                'sales.void' => 'Void a sale within the void window',
                'sales.discount' => 'Give discounts up to the configured maximum',
                'sales.discount_unlimited' => 'Give discounts above the configured maximum',
                'sales.change_price' => 'Change a line unit price',
                'sales.below_min_price' => 'Sell below the minimum sale price',
                'sales.credit' => 'Make credit / partial-payment sales',
                'sales.override_credit_limit' => "Exceed a customer's credit limit",
                'sales.approve' => 'Act as approver for POS approval requests',
            ],
            'Sales returns' => [
                'sale_returns.view' => 'View sales returns',
                'sale_returns.create' => 'Create sales returns',
                'sale_returns.refund' => 'Pay cash or bank refunds',
            ],
            'Customers' => [
                'customers.view' => 'View customers',
                'customers.create' => 'Create customers',
                'customers.edit' => 'Edit customers',
                'customers.delete' => 'Delete customers (only without history)',
                'customers.adjust_balance' => 'Post a customer balance adjustment',
                'customers.statement' => 'Print customer statements',
            ],
            'Customer payments' => [
                'customer_payments.view' => 'View customer payments',
                'customer_payments.create' => 'Collect customer payments',
                'customer_payments.void' => 'Void a customer payment',
            ],
            'Products' => [
                'products.view' => 'View products',
                'products.create' => 'Create products',
                'products.edit' => 'Edit products',
                'products.delete' => 'Delete products (only without history)',
                'products.edit_price' => 'Change sale, wholesale or minimum price',
                'products.view_cost' => 'See purchase and average cost, margins',
                'products.import' => 'Bulk import products',
                'catalog.manage' => 'Manage categories, brands and units',
            ],
            'Inventory' => [
                'inventory.view' => 'View stock levels and movements',
                'inventory.adjust' => 'Post stock adjustments and counts',
                'inventory.void_adjustment' => 'Void a stock adjustment',
            ],
            'Suppliers' => [
                'suppliers.view' => 'View suppliers',
                'suppliers.create' => 'Create suppliers',
                'suppliers.edit' => 'Edit suppliers',
                'suppliers.delete' => 'Delete suppliers',
                'suppliers.adjust_balance' => 'Post a supplier balance adjustment',
            ],
            'Purchases' => [
                'purchases.view' => 'View purchases',
                'purchases.create' => 'Create and edit purchase drafts',
                'purchases.receive' => 'Receive (post) a purchase',
                'purchases.void' => 'Void a received purchase',
            ],
            'Purchase returns' => [
                'purchase_returns.view' => 'View purchase returns',
                'purchase_returns.create' => 'Create purchase returns',
            ],
            'Supplier payments' => [
                'supplier_payments.view' => 'View supplier payments',
                'supplier_payments.create' => 'Record supplier payments',
                'supplier_payments.void' => 'Void a supplier payment',
            ],
            'Service / Repair' => [
                'service_jobs.view_all' => 'See all service jobs',
                'service_jobs.view_assigned' => 'See jobs assigned to me',
                'service_jobs.create' => 'Receive a job (intake)',
                'service_jobs.edit' => 'Edit job details',
                'service_jobs.assign' => 'Assign a technician',
                'service_jobs.change_status' => 'Move a job along its status flow',
                'service_jobs.manage_parts' => 'Add and remove parts (affects stock)',
                'service_jobs.set_charges' => 'Set charges, estimate and discount',
                'service_jobs.deliver' => 'Invoice and deliver a job',
                'service_jobs.cancel' => 'Cancel a job',
                'service_types.manage' => 'Manage service types',
            ],
            'Expenses' => [
                'expenses.view' => 'View expenses',
                'expenses.create' => 'Record expenses',
                'expenses.edit' => 'Edit same-day expenses',
                'expenses.void' => 'Void expenses',
                'expense_categories.manage' => 'Manage expense categories',
            ],
            'Reports' => [
                'reports.sales' => 'Sales, returns and payment-method reports',
                'reports.inventory' => 'Stock, movements and low-stock reports',
                'reports.purchases' => 'Purchase and purchase-return reports',
                'reports.customers' => 'Customer balances, statements, aging',
                'reports.suppliers' => 'Supplier balances, statements, payables',
                'reports.service' => 'Service jobs and technician performance',
                'reports.expenses' => 'Expense reports',
                'reports.profit' => 'Gross and net profit, margins',
                'reports.export' => 'Export reports to PDF / CSV / XLSX',
            ],
            'Users & roles' => [
                'users.view' => 'View users',
                'users.manage' => 'Create, edit, deactivate users, reset passwords',
                'roles.manage' => 'Create and edit roles and permissions',
            ],
            'Settings' => [
                'settings.view' => 'View settings',
                'settings.manage' => 'Change settings',
            ],
            'Backup' => [
                'backups.view' => 'View backup history and health',
                'backups.create' => 'Run "Backup Now"',
                'backups.download' => 'Download a backup file',
                'backups.verify' => 'Run backup verification',
                'backups.delete' => 'Delete a backup file',
                'backups.restore' => 'Restore a backup',
                'backups.settings' => 'Change backup schedule, retention and destinations',
            ],
            'Audit' => [
                'audit_logs.view' => 'View the audit log',
            ],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(array_merge(...array_values(self::modules())));
    }

    /** Permissions that expose cost or profit. They must never reach Cashier or Technician. */
    public const COST_PROFIT = [
        'products.view_cost',
        'reports.profit',
        'dashboard.financials',
    ];

    /** @return list<string> */
    private static function managerExclusions(): array
    {
        return [
            'customers.adjust_balance',
            'suppliers.delete',
            'suppliers.adjust_balance',
            'inventory.void_adjustment',
            'sales.below_min_price',
            'users.manage',
            'roles.manage',
            'settings.manage',
            'backups.download',
            'backups.verify',
            'backups.delete',
            'backups.restore',
            'backups.settings',
            'audit_logs.view',
        ];
    }

    /** @return array<string, list<string>> role => permissions (Admin also passes via Gate::before) */
    public static function defaultRoles(): array
    {
        return [
            self::ADMIN => self::all(),
            self::MANAGER => array_values(array_diff(self::all(), self::managerExclusions())),
            self::CASHIER => [
                'dashboard.view',
                'pos.access',
                'sales.create', 'sales.view_own', 'sales.hold', 'sales.reprint', 'sales.discount', 'sales.credit',
                'sale_returns.view',
                'customers.view', 'customers.create', 'customers.edit', 'customers.statement',
                'customer_payments.view', 'customer_payments.create',
                'products.view',
                'inventory.view',
                'service_jobs.view_all', 'service_jobs.view_assigned', 'service_jobs.create', 'service_jobs.edit',
                'service_jobs.assign', 'service_jobs.change_status', 'service_jobs.deliver',
                'expenses.view', 'expenses.create',
            ],
            self::TECHNICIAN => [
                'dashboard.view',
                'products.view',
                'inventory.view',
                'customers.view',
                'service_jobs.view_assigned', 'service_jobs.create', 'service_jobs.edit',
                'service_jobs.change_status', 'service_jobs.manage_parts', 'service_jobs.set_charges',
            ],
        ];
    }
}
