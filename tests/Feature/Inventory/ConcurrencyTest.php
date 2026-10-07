<?php

namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Backup\BackupTestCase;

/**
 * Several real, separate PHP processes use the same database at the same moment. This is the situation of
 * three cashiers pressing "complete sale" on the last unit together. Transactions inside one process cannot
 * test that, so these tests start child processes (tests/Support/concurrent_worker.php).
 */
class ConcurrencyTest extends BackupTestCase
{
    /** @param  list<string>  $args */
    private function startWorker(array $args): InvokedProcess
    {
        return Process::path(base_path())->timeout(120)->env([
            'DB_DATABASE' => 'mrpos_test', 'DB_CONNECTION' => 'mariadb', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'APP_ENV' => 'testing', 'BACKUP_DB_USERNAME' => '', 'BACKUP_DB_PASSWORD' => '',
        ])->start([PHP_BINARY, base_path('tests/Support/concurrent_worker.php'), ...$args]);
    }

    /** @return array<string, int> outcome => how many processes ended that way */
    private function tally(array $processes): array
    {
        $tally = [];
        foreach ($processes as $p) {
            $out = trim($p->wait()->output());
            $key = str_starts_with($out, 'ERROR') ? $out : ($out === '' ? 'NO OUTPUT' : $out);
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }
        ksort($tally);   // which process finishes first is random; only the counts matter

        return $tally;
    }

    private function committedProduct(string $qty): Product
    {
        $p = Product::factory()->create();
        DB::transaction(fn () => app(InventoryService::class)->move($p, $qty, 'purchase', ['unit_cost' => '100']));

        return $p->refresh();
    }

    public function test_four_cashiers_selling_the_last_unit_at_once_sell_it_exactly_once(): void
    {
        $p = $this->committedProduct('1');
        $start = microtime(true) + 6;   // all children wait for this moment, after they have finished booting

        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $workers[] = $this->startWorker(['sale', (string) $p->id, '1', '1.0', (string) $start]);
        }
        $tally = $this->tally($workers);

        $this->assertSame(['OK' => 1, 'REFUSED' => 3], $tally, 'Exactly one sale may succeed. Got: '.json_encode($tally));
        $this->assertSame('0.000', $p->fresh()->current_stock, 'Stock must not go below zero.');
        $this->assertSame(1, StockMovement::query()->where('product_id', $p->id)->where('movement_type', 'sale')->count());
        $this->assertSame([], app(InventoryService::class)->findDrift());
    }

    public function test_concurrent_sales_never_oversell_and_the_ledger_stays_consistent(): void
    {
        $p = $this->committedProduct('5');
        $start = microtime(true) + 6;

        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $workers[] = $this->startWorker(['sale', (string) $p->id, '2', '0.5', (string) $start]);   // 4 buyers want 2 each; only 5 exist
        }
        $tally = $this->tally($workers);

        $this->assertSame(['OK' => 2, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('1.000', $p->fresh()->current_stock);

        // The ledger is a straight line: each row starts where the previous one ended.
        $rows = StockMovement::query()->where('product_id', $p->id)->orderBy('id')->get();
        $expected = '0.000';
        foreach ($rows as $r) {
            $this->assertSame($expected, $r->qty_before);
            $expected = $r->qty_after;
        }
        $this->assertSame([], app(InventoryService::class)->findDrift());
    }

    public function test_adjustments_touching_the_same_products_in_opposite_order_never_deadlock(): void
    {
        User::factory()->withRole('Admin')->create();
        $a = $this->committedProduct('10');
        $b = $this->committedProduct('10');
        $start = microtime(true) + 6;

        $workers = [];
        for ($i = 0; $i < 6; $i++) {
            $ids = $i % 2 === 0 ? [$a->id, $b->id] : [$b->id, $a->id];
            $workers[] = $this->startWorker(['adjust', (string) $start, '1', ...array_map('strval', $ids)]);
        }
        $tally = $this->tally($workers);

        $this->assertSame(['OK' => 6], $tally, 'No deadlock may reach the user. Got: '.json_encode($tally));
        $this->assertSame('16.000', $a->fresh()->current_stock);
        $this->assertSame('16.000', $b->fresh()->current_stock);
        $this->assertSame(6, StockAdjustment::count());
        $this->assertSame(6, StockAdjustment::query()->distinct()->count('adjustment_no'), 'Every document got its own number.');
        $this->assertSame([], app(InventoryService::class)->findDrift());
    }
}
