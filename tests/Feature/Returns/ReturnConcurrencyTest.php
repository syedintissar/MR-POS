<?php

namespace Tests\Feature\Returns;

use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\CreateSaleReturn;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\SupplierAccountService;
use App\Services\Sales\SaleAccountService;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Feature\Backup\BackupTestCase;

/**
 * Several real, separate PHP processes taking goods back (or sending them back to a supplier) at the same instant: the same units returned
 * twice, a return racing the cancelling of its invoice, a return racing a payment, the same return cancelled by three people. Transactions
 * inside one process cannot show these races, so each test starts child processes (tests/Support/return_worker.php and customer_worker.php)
 * that all wait for the same start time.
 */
class ReturnConcurrencyTest extends BackupTestCase
{
    private int $cashId;

    private User $manager;

    /** @var list<User> */
    private array $managers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashId = PaymentMethod::query()->where('code', 'cash')->value('id');
        $this->manager = User::factory()->withRole('Manager')->create();
        for ($i = 0; $i < 5; $i++) {
            $this->managers[] = User::factory()->withRole('Manager')->create();
        }
    }

    /** @param list<string> $args */
    private function start(string $script, array $args): InvokedProcess
    {
        return Process::path(base_path())->timeout(120)->env([
            'DB_DATABASE' => 'mrpos_test', 'DB_CONNECTION' => 'mariadb', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'APP_ENV' => 'testing', 'BACKUP_DB_USERNAME' => '', 'BACKUP_DB_PASSWORD' => '',
        ])->start([PHP_BINARY, base_path("tests/Support/{$script}.php"), ...$args]);
    }

    /** @return array<string, int> outcome => how many processes ended that way (an id is shown as OK) */
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

    private function product(string $price, string $qty = '50'): Product
    {
        $p = Product::factory()->create(['sale_price' => $price]);
        DB::transaction(fn () => app(InventoryService::class)->move($p, $qty, 'purchase', ['unit_cost' => '10']));

        return $p->refresh();
    }

    /** A sale made in this process. $paid = null: paid in cash; '0': nothing paid (on account). */
    private function sale(string $price, string $qty, ?Customer $c = null, ?string $paid = null): Sale
    {
        $p = $this->product($price);

        return app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $c?->id, 'lines' => [['product_id' => $p->id, 'quantity' => $qty]],
            'payments' => $paid === '0' ? [] : [['payment_method_id' => $this->cashId, 'amount' => $paid ?? '100000']],
        ], $this->manager);
    }

    private function bill(string $qty, string $cost): Purchase
    {
        $supplier = Supplier::factory()->create();
        $draft = app(SavePurchaseDraft::class)->execute([
            'supplier_id' => $supplier->id, 'purchase_date' => now()->toDateString(), 'lines' => [['product_id' => Product::factory()->create()->id, 'quantity' => $qty, 'unit_cost' => $cost]],
        ], $this->manager);

        return app(ReceivePurchase::class)->execute($draft, $this->manager);
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
        $this->assertSame([], app(SaleAccountService::class)->findDrift());
        $this->assertSame([], app(SupplierAccountService::class)->findDrift());
        $this->assertSame([], app(InventoryService::class)->findDrift());
    }

    // ---------------------------------------------------------------- sales returns

    public function test_the_same_units_cannot_be_returned_twice_by_different_terminals(): void
    {
        $sale = $this->sale('100', '5');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn ($i) => $this->start('return_worker', ['sreturn', (string) $start, (string) $this->managers[$i]->id, (string) $sale->id, '2']),
            [0, 1, 2, 3],   // four terminals each take back 2 of the 5: only two of them can
        ));

        $this->assertSame(['OK' => 2, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $item = $sale->items()->sole();
        $this->assertSame('4.000', (string) $item->returned_quantity);
        $this->assertSame('400.00', (string) $sale->fresh()->returned_amount);
        $this->assertSame(2, SaleReturn::query()->where('status', 'posted')->count());
        $this->assertSame('49.000', (string) Product::query()->findOrFail($item->product_id)->current_stock, '50 - 5 sold + 4 back');
        $this->assertBooksAgree();
    }

    public function test_a_return_and_the_cancelling_of_its_invoice_cannot_both_happen(): void
    {
        $sale = $this->sale('100', '3');
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->start('return_worker', ['sreturn', (string) $start, (string) $this->managers[0]->id, (string) $sale->id, '1']),
            $this->start('customer_worker', ['voidsale', (string) $start, (string) $this->managers[1]->id, (string) $sale->id]),
        ]);

        $this->assertSame(['OK' => 1, 'REFUSED' => 1], $tally, 'Got: '.json_encode($tally));
        $voided = $sale->fresh()->status === 'voided';
        $this->assertSame($voided ? 0 : 1, SaleReturn::query()->where('status', 'posted')->count(), 'a cancelled invoice has no return, and an invoice with a return is not cancelled');
        $this->assertBooksAgree();
    }

    public function test_a_return_and_a_payment_on_the_same_invoice_end_in_the_same_place_either_way_round(): void
    {
        $c = Customer::factory()->create(['allow_credit' => true]);
        $sale = $this->sale('100', '5', $c, '0');   // 500 on account
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->start('return_worker', ['sreturn', (string) $start, (string) $this->managers[0]->id, (string) $sale->id, '5', 'credit']),
            $this->start('customer_worker', ['collect', (string) $start, (string) $this->managers[1]->id, (string) $c->id, (string) $this->cashId, '500']),
        ]);

        $this->assertSame(['OK' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('0.00', (string) $sale->fresh()->due_amount);
        $this->assertSame('-500.00', (string) $c->fresh()->getRawOriginal('balance'), 'we hold 500 for them');
        $this->assertSame('500.00', app(CustomerAccountService::class)->advanceFor($c->id), 'as credit they can spend');
        $this->assertBooksAgree();
    }

    public function test_the_same_return_cancelled_by_three_people_is_reversed_once(): void
    {
        $sale = $this->sale('100', '5');
        $return = app(CreateSaleReturn::class)->execute($sale, [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => '2', 'restock' => true]], [], 'x', $this->manager);
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(fn ($i) => $this->start('return_worker', ['svoid', (string) $start, (string) $this->managers[$i]->id, (string) $return->id]), [0, 1, 2]));

        $this->assertSame(['OK' => 1, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('0.00', (string) $sale->fresh()->returned_amount);
        $this->assertSame('0.000', (string) $sale->items()->sole()->returned_quantity);
        $this->assertSame('45.000', (string) Product::query()->findOrFail($sale->items()->sole()->product_id)->current_stock, 'the 2 units left the shelf again exactly once');
        $this->assertBooksAgree();
    }

    // ---------------------------------------------------------------- purchase returns

    public function test_the_same_units_cannot_be_sent_back_to_a_supplier_twice(): void
    {
        $bill = $this->bill('10', '100');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn ($i) => $this->start('return_worker', ['preturn', (string) $start, (string) $this->managers[$i]->id, (string) $bill->id, '4']),
            [0, 1, 2, 3],   // four people each send back 4 of the 10: only two can
        ));

        $this->assertSame(['OK' => 2, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('8.000', (string) $bill->items()->sole()->returned_quantity);
        $this->assertSame('800.00', (string) $bill->fresh()->returned_amount);
        $this->assertSame('200.00', (string) $bill->supplier->fresh()->getRawOriginal('balance'), '1000 owed - 800 credited');
        $this->assertSame('2.000', (string) Product::query()->findOrFail($bill->items()->sole()->product_id)->current_stock);
        $this->assertBooksAgree();
    }

    public function test_a_return_to_a_supplier_and_the_cancelling_of_the_purchase_cannot_both_happen(): void
    {
        $bill = $this->bill('10', '100');
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->start('return_worker', ['preturn', (string) $start, (string) $this->managers[0]->id, (string) $bill->id, '3']),
            $this->start('return_worker', ['voidpurchase', (string) $start, (string) $this->managers[1]->id, (string) $bill->id]),
        ]);

        $this->assertSame(['OK' => 1, 'REFUSED' => 1], $tally, 'Got: '.json_encode($tally));
        $voided = $bill->fresh()->status === 'voided';
        $this->assertSame($voided ? 0 : 1, PurchaseReturn::query()->where('status', 'posted')->count());
        $this->assertBooksAgree();
    }

    public function test_the_same_purchase_return_cancelled_by_three_people_is_reversed_once(): void
    {
        $bill = $this->bill('10', '100');
        $return = app(CreatePurchaseReturn::class)->execute(['purchase_id' => $bill->id, 'lines' => [['purchase_item_id' => $bill->items()->sole()->id, 'quantity' => '4']], 'reason' => 'x'], $this->manager);
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(fn ($i) => $this->start('return_worker', ['pvoid', (string) $start, (string) $this->managers[$i]->id, (string) $return->id]), [0, 1, 2]));

        $this->assertSame(['OK' => 1, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('0.00', (string) $bill->fresh()->returned_amount);
        $this->assertSame('1000.00', (string) $bill->supplier->fresh()->getRawOriginal('balance'));
        $this->assertSame('10.000', (string) Product::query()->findOrFail($bill->items()->sole()->product_id)->current_stock, 'back in stock exactly once');
        $this->assertBooksAgree();
    }

    // ---------------------------------------------------------------- everything at once

    public function test_returns_cancellations_payments_and_credit_sales_on_one_account_at_once_never_deadlock(): void
    {
        $c = Customer::factory()->create(['allow_credit' => true]);
        $inv1 = $this->sale('100', '4', $c, '0');   // 400
        $inv2 = $this->sale('100', '3', $c, '0');   // 300
        $earlier = app(CreateSaleReturn::class)->execute($inv1, [['sale_item_id' => $inv1->items()->sole()->id, 'quantity' => '1', 'restock' => true]], [], 'x', $this->manager);
        $p = $this->product('50');
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->start('return_worker', ['sreturn', (string) $start, (string) $this->managers[0]->id, (string) $inv1->id, '1', 'credit']),
            $this->start('return_worker', ['sreturn', (string) $start, (string) $this->managers[1]->id, (string) $inv2->id, '1', 'credit']),
            $this->start('return_worker', ['svoid', (string) $start, (string) $this->managers[2]->id, (string) $earlier->id]),
            $this->start('customer_worker', ['collect', (string) $start, (string) $this->managers[3]->id, (string) $c->id, (string) $this->cashId, '100']),
            $this->start('customer_worker', ['credit', (string) $start, (string) $this->managers[4]->id, (string) $c->id, '0', (string) $this->cashId, "{$p->id}:1"]),
        ]);

        $this->assertSame(['OK' => 5], $tally, 'No deadlock may reach anyone. Got: '.json_encode($tally));
        $this->assertSame('450.00', (string) $c->fresh()->getRawOriginal('balance'), '400 + 300 + 50 sold, 2 x 100 returned, 100 collected (the cancelled return counts for nothing)');
        $this->assertBooksAgree();
    }

    // ---------------------------------------------------------------- exchanges and supplier credit

    public function test_two_terminals_exchanging_the_same_units_cannot_both_succeed(): void
    {
        $sale = $this->sale('100', '3');
        $other = $this->product('120');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn ($i) => $this->start('return_worker', ['exchange', (string) $start, (string) $this->managers[$i]->id, (string) $sale->id, '2', (string) $other->id]),
            [0, 1, 2],   // three terminals each exchange 2 of the 3: only one can
        ));

        $this->assertSame(['OK' => 1, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('2.000', (string) $sale->items()->sole()->returned_quantity);
        $this->assertSame(1, SaleReturn::query()->where('status', 'posted')->count());
        $this->assertSame(2, Sale::query()->count(), 'the original and exactly one replacement');
        $this->assertSame('49.000', (string) $other->fresh()->current_stock, 'one replacement unit left the shelf, not three');
        $this->assertBooksAgree();
    }

    public function test_supplier_credit_pressed_by_two_people_at_once_is_used_once(): void
    {
        $paid = app(ReceivePurchase::class)->execute(app(SavePurchaseDraft::class)->execute([
            'supplier_id' => ($supplier = Supplier::factory()->create())->id, 'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => Product::factory()->create()->id, 'quantity' => '10', 'unit_cost' => '100']],
        ], $this->manager), $this->manager, ['payment_method_id' => $this->cashId, 'amount' => '1000']);
        app(CreatePurchaseReturn::class)->execute(['purchase_id' => $paid->id, 'lines' => [['purchase_item_id' => $paid->items()->sole()->id, 'quantity' => '3']], 'reason' => 'x'], $this->manager);   // 300 of credit
        $bill = app(ReceivePurchase::class)->execute(app(SavePurchaseDraft::class)->execute([
            'supplier_id' => $supplier->id, 'purchase_date' => now()->toDateString(), 'lines' => [['product_id' => Product::factory()->create()->id, 'quantity' => '3', 'unit_cost' => '100']],
        ], $this->manager), $this->manager);   // 300 owed
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(fn ($i) => $this->start('return_worker', ['usecredit', (string) $start, (string) $this->managers[$i]->id, (string) $supplier->id]), [0, 1, 2]));

        $this->assertSame(['OK' => 1, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('300.00', (string) DB::table('supplier_credit_applications')->where('status', 'posted')->sum('amount'), 'the 300 was used once, not three times');
        $this->assertSame('0.00', (string) $bill->fresh()->due_amount);
        $this->assertBooksAgree();
    }
}
