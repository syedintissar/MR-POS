<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Recomputes every cached total from its ledger and reports any difference. It never "fixes" anything silently:
 * a mismatch means a bug or direct database editing, and a person must look at it.
 */
class IntegrityCheckCommand extends Command
{
    protected $signature = 'pos:integrity:check';

    protected $description = 'Check that cached stock totals agree with the stock ledger. Exit code 1 if anything is off.';

    public function handle(InventoryService $inventory, AuditLogger $audit): int
    {
        $problems = [];

        foreach ($inventory->findDrift() as $d) {
            $problems[] = "Stock of \"{$d['name']}\" (#{$d['product_id']}) is {$d['cached']} but its ledger adds up to {$d['ledger']}.";
        }

        // Later phases add their checks here (customer and supplier balances, invoice totals, ...).

        Cache::forever('integrity.last', ['ran_at' => now()->toDateTimeString(), 'problems' => array_slice($problems, 0, 20), 'total' => count($problems)]);

        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->error($p);
            }
            $audit->log('integrity_problem', 'system', count($problems).' integrity problem(s) found', new: ['problems' => array_slice($problems, 0, 20)]);

            return self::FAILURE;
        }

        $this->info('Integrity check passed: all stock totals agree with the ledger.');

        return self::SUCCESS;
    }
}
