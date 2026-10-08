<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CompleteSale;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SaleAccountService;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Feature\Backup\BackupTestCase;

/**
 * Several real, separate PHP processes ringing up sales at the same instant: three cashiers pressing "complete" on the
 * last toner, or one cashier's double click arriving twice. Transactions inside one process cannot show these races,
 * so each test starts child processes (tests/Support/sale_worker.php) that all wait for the same start time.
 */
class SaleConcurrencyTest extends BackupTestCase
{
    private int $cashId;

    /** @var list<User> */
    private array $cashiers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashId = PaymentMethod::query()->where('code', 'cash')->value('id');
        for ($i = 0; $i < 6; $i++) {
            $this->cashiers[] = User::factory()->withRole('Cashier')->create();
        }
    }

    /** @param  list<string>  $args */
    private function startWorker(array $args): InvokedProcess
    {
        return Process::path(base_path())->timeout(120)->env([
            'DB_DATABASE' => 'mrpos_test', 'DB_CONNECTION' => 'mariadb', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'APP_ENV' => 'testing', 'BACKUP_DB_USERNAME' => '', 'BACKUP_DB_PASSWORD' => '',
        ])->start([PHP_BINARY, base_path('tests/Support/sale_worker.php'), ...$args]);
    }

    /** @return array<string, int> outcome => how many processes ended that way (a sale id is shown as OK) */
    private function tally(array $processes): array
    {
        $tally = [];
        foreach ($processes as $p) {
            $out = trim($p->wait()->output());
            $key = str_starts_with($out, 'OK:') ? 'OK' : ($out === '' ? 'NO OUTPUT' : $out);
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }
        ksort($tally);

        return $tally;
    }

    /** @return list<string> the sale ids the processes reported */
    private function saleIds(array $outputs): array
    {
        return array_values(array_unique(array_map(fn ($o) => substr($o, 3), array_filter($outputs, fn ($o) => str_starts_with($o, 'OK:')))));
    }

    private function product(string $qty): Product
    {
        $p = Product::factory()->create(['sale_price' => '150']);
        DB::transaction(fn () => app(InventoryService::class)->move($p, $qty, 'purchase', ['unit_cost' => '100']));

        return $p->refresh();
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(SaleAccountService::class)->findDrift());
        $this->assertSame([], app(InventoryService::class)->findDrift());
    }

    public function test_four_cashiers_selling_the_last_unit_at_once_sell_it_exactly_once(): void
    {
        $p = $this->product('1');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn ($i) => $this->startWorker(['sell', (string) $start, (string) $this->cashiers[$i]->id, 'auto', (string) $this->cashId, "{$p->id}:1"]),
            range(0, 3),
        ));

        $this->assertSame(['OK' => 1, 'REFUSED' => 3], $tally, 'Exactly one sale may get the last unit. Got: '.json_encode($tally));
        $this->assertSame('0.000', $p->fresh()->current_stock, 'Stock must not go below zero.');
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(1, CustomerPayment::query()->count(), 'Only the sale that happened took any money.');
        $this->assertSame(1, StockMovement::query()->where('product_id', $p->id)->where('movement_type', 'sale')->count());
        $this->assertBooksAgree();
    }

    public function test_the_same_checkout_arriving_from_four_places_at_once_makes_one_sale(): void
    {
        // The last unit on purpose: without the retry protection the second arrival would be told "not enough stock" instead of
        // being handed the sale that was already made.
        $p = $this->product('1');
        $key = (string) Str::uuid();
        $start = microtime(true) + 6;

        $processes = array_map(
            fn () => $this->startWorker(['sell', (string) $start, (string) $this->cashiers[0]->id, $key, (string) $this->cashId, "{$p->id}:1"]),
            range(1, 4),
        );
        $outputs = array_map(fn ($proc) => trim($proc->wait()->output()), $processes);

        $this->assertSame(['OK'], array_unique(array_map(fn ($o) => str_starts_with($o, 'OK:') ? 'OK' : $o, $outputs)), 'Every arrival must get the sale back. Got: '.json_encode($outputs));
        $this->assertCount(1, $this->saleIds($outputs), 'All four must be told about the SAME sale.');
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame('0.000', $p->fresh()->current_stock, 'The goods left once.');
        $this->assertSame(1, CustomerPayment::query()->count());
        $this->assertBooksAgree();
    }

    public function test_concurrent_sales_never_oversell_and_the_ledger_stays_a_straight_line(): void
    {
        $p = $this->product('5');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn ($i) => $this->startWorker(['sell', (string) $start, (string) $this->cashiers[$i]->id, 'auto', (string) $this->cashId, "{$p->id}:2"]),
            range(0, 3),   // four buyers want 2 each; only 5 exist
        ));

        $this->assertSame(['OK' => 2, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('1.000', $p->fresh()->current_stock);

        $expected = '0.000';
        foreach (StockMovement::query()->where('product_id', $p->id)->orderBy('id')->get() as $m) {
            $this->assertSame($expected, $m->qty_before);
            $expected = $m->qty_after;
        }
        $this->assertBooksAgree();
    }

    public function test_carts_with_the_same_products_in_opposite_order_never_deadlock(): void
    {
        $a = $this->product('20');
        $b = $this->product('20');
        $start = microtime(true) + 6;

        $workers = [];
        for ($i = 0; $i < 6; $i++) {
            $lines = $i % 2 === 0 ? ["{$a->id}:1", "{$b->id}:1"] : ["{$b->id}:1", "{$a->id}:1"];
            $workers[] = $this->startWorker(['sell', (string) $start, (string) $this->cashiers[$i]->id, 'auto', (string) $this->cashId, ...$lines]);
        }
        $tally = $this->tally($workers);

        $this->assertSame(['OK' => 6], $tally, 'No deadlock may reach a cashier. Got: '.json_encode($tally));
        $this->assertSame('14.000', $a->fresh()->current_stock);
        $this->assertSame('14.000', $b->fresh()->current_stock);
        $this->assertSame(6, Sale::query()->count());
        $this->assertSame(6, Sale::query()->distinct()->count('invoice_no'), 'Every invoice got its own number.');
        $this->assertSame(['INV-000001', 'INV-000002', 'INV-000003', 'INV-000004', 'INV-000005', 'INV-000006'], Sale::query()->orderBy('invoice_no')->pluck('invoice_no')->all(), 'and the numbers have no gaps');
        $this->assertBooksAgree();
    }

    public function test_the_same_sale_cancelled_by_three_people_at_once_is_reversed_once(): void
    {
        $p = $this->product('10');
        $manager = User::factory()->withRole('Manager')->create();
        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $p->id, 'quantity' => '4']],
            'payments' => [['payment_method_id' => $this->cashId, 'amount' => '600']],
        ], $this->cashiers[0]);
        $this->assertSame('6.000', $p->fresh()->current_stock);
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(fn () => $this->startWorker(['void', (string) $start, (string) $manager->id, (string) $sale->id]), range(1, 3)));

        $this->assertSame(['OK' => 1, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('10.000', $p->fresh()->current_stock, 'The goods went back once, not three times.');
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'sale_void')->count());
        $this->assertBooksAgree();
    }
}
