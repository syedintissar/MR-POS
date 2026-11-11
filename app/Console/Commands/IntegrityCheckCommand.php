<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\CashBook\BankReconciliationService;
use App\Services\CashBook\MoneyAccountService;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\SupplierAccountService;
use App\Services\Sales\SaleAccountService;
use App\Services\Service\ServiceJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Recomputes every cached total from its ledger and reports any difference. It never "fixes" anything silently:
 * a mismatch means a bug or direct database editing, and a person must look at it.
 */
class IntegrityCheckCommand extends Command
{
    protected $signature = 'pos:integrity:check';

    protected $description = 'Check that cached totals (stock, supplier and customer balances, bill, invoice, service job and customer payments) agree with their ledgers. Exit code 1 if anything is off.';

    public function handle(InventoryService $inventory, SupplierAccountService $suppliers, SaleAccountService $sales, CustomerAccountService $customers, ServiceJobService $serviceJobs, MoneyAccountService $cashBook, BankReconciliationService $statements, AuditLogger $audit): int
    {
        $problems = [];

        foreach ($inventory->findDrift() as $d) {
            $problems[] = "Stock of \"{$d['name']}\" (#{$d['product_id']}) is {$d['cached']} but its ledger adds up to {$d['ledger']}.";
        }

        // Supplier balances vs ledger, paid/due of every bill vs its payments, payment allocations, received goods vs stock movements.
        foreach ($suppliers->findDrift() as $message) {
            $problems[] = $message;
        }

        // Invoice lines vs invoice totals, paid / due vs payments, payment allocations, stock taken vs units invoiced, walk-in balance.
        foreach ($sales->findDrift() as $message) {
            $problems[] = $message;
        }

        // Customer balances vs their ledger; every invoice and receipt of a named customer is on the account exactly once (and reversed when cancelled).
        foreach ($customers->findDrift() as $message) {
            $problems[] = $message;
        }

        // Service jobs: figures agree with their lines and payments, the status with the history, parts with the stock ledger, invoices with the account.
        foreach ($serviceJobs->findDrift() as $message) {
            $problems[] = $message;
        }

        // Returns are checked inside the sales, customer and supplier services above (Phase 8).

        // Cash & bank: every day closing agrees with its own figures and with the over / short entry that carries its difference.
        foreach ($cashBook->findDrift() as $message) {
            $problems[] = $message;
        }

        // Bank statements: every line matched on a completed statement is still in the book with the same amounts.
        foreach ($statements->findDrift() as $message) {
            $problems[] = $message;
        }

        Cache::forever('integrity.last', ['ran_at' => now()->toDateTimeString(), 'problems' => array_slice($problems, 0, 20), 'total' => count($problems)]);

        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->error($p);
            }
            $audit->log('integrity_problem', 'system', count($problems).' integrity problem(s) found', new: ['problems' => array_slice($problems, 0, 20)]);

            return self::FAILURE;
        }

        $this->info('Integrity check passed: stock totals, supplier and customer accounts, bill payments, invoices, returns, service jobs and day closings all agree with their ledgers.');

        return self::SUCCESS;
    }
}
