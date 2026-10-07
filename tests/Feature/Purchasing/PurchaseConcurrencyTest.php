<?php

namespace Tests\Feature\Purchasing;

use App\Actions\Purchasing\PostSupplierPayment;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\SupplierAccountService;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Backup\BackupTestCase;

/**
 * Real, separate PHP processes working on the same supplier at the same moment: two people pressing "pay" on the same
 * bill, four people pressing "receive" on the same draft. Transactions inside one process cannot show these races,
 * so each test starts child processes (tests/Support/purchase_worker.php) that all wait for the same start time.
 */
class PurchaseConcurrencyTest extends BackupTestCase
{
    private User $user;

    private Supplier $supplier;

    private PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->withRole('Admin')->create();   // the workers act as the first user
        $this->supplier = Supplier::factory()->create();
        $this->cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    }

    /** @param  list<string>  $args */
    private function startWorker(array $args): InvokedProcess
    {
        return Process::path(base_path())->timeout(120)->env([
            'DB_DATABASE' => 'mrpos_test', 'DB_CONNECTION' => 'mariadb', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'APP_ENV' => 'testing', 'BACKUP_DB_USERNAME' => '', 'BACKUP_DB_PASSWORD' => '',
        ])->start([PHP_BINARY, base_path('tests/Support/purchase_worker.php'), ...$args]);
    }

    /** @return array<string, int> */
    private function tally(array $processes): array
    {
        $tally = [];
        foreach ($processes as $p) {
            $out = trim($p->wait()->output());
            $key = $out === '' ? 'NO OUTPUT' : $out;
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }
        ksort($tally);

        return $tally;
    }

    /** @param  list<array{0: Product, 1: string, 2: string}>  $lines */
    private function draft(array $lines): Purchase
    {
        return app(SavePurchaseDraft::class)->execute([
            'supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(),
            'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1], 'unit_cost' => $l[2]], $lines),
        ], $this->user);
    }

    private function bill(string $qty, string $cost): Purchase
    {
        return app(ReceivePurchase::class)->execute($this->draft([[Product::factory()->create(), $qty, $cost]]), $this->user);
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(SupplierAccountService::class)->findDrift());
        $this->assertSame([], app(InventoryService::class)->findDrift());
        $this->assertSame(
            app(SupplierAccountService::class)->balanceFromLedger($this->supplier->id),
            (string) $this->supplier->fresh()->getRawOriginal('balance'),
        );
    }

    public function test_four_people_receiving_the_same_draft_at_once_receive_it_exactly_once(): void
    {
        $p = Product::factory()->create();
        $d = $this->draft([[$p, '10', '100']]);
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(fn () => $this->startWorker(['receive', (string) $start, (string) $d->id]), range(1, 4)));

        $this->assertSame(['OK' => 1, 'REFUSED' => 3], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('10.000', $p->fresh()->current_stock, 'The goods must be added once.');
        $this->assertSame(1, StockMovement::query()->where('source_type', 'purchase')->count());
        $this->assertSame(1, SupplierLedgerEntry::query()->count());
        $this->assertSame('1000.00', (string) $this->supplier->fresh()->getRawOriginal('balance'));
        $this->assertSame('PUR-000001', $d->fresh()->purchase_no);
        $this->assertBooksAgree();
    }

    public function test_two_people_paying_the_same_bill_in_full_at_once_settle_it_only_once(): void
    {
        $bill = $this->bill('10', '100');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn () => $this->startWorker(['pay', (string) $start, (string) $this->supplier->id, (string) $this->cash->id, '1000', 'manual', (string) $bill->id]),
            range(1, 2),
        ));

        $this->assertSame(['OK' => 1, 'REFUSED' => 1], $tally, 'The bill may be paid once. Got: '.json_encode($tally));
        $this->assertSame(1, SupplierPayment::query()->count());
        $this->assertSame('1000.00', $bill->fresh()->paid_amount);
        $this->assertSame('0.00', $bill->fresh()->due_amount);
        $this->assertSame('0.00', (string) $this->supplier->fresh()->getRawOriginal('balance'));
        $this->assertBooksAgree();
    }

    public function test_several_automatic_payments_at_once_never_pay_a_bill_more_than_it_is_due(): void
    {
        $a = $this->bill('10', '100');   // 1000
        $b = $this->bill('5', '100');    // 500  -> 1500 owed in all
        $start = microtime(true) + 6;

        // four payments of 500 = 2000: 1500 settles the bills, the last 500 is an advance. Every one of them must succeed.
        $tally = $this->tally(array_map(
            fn () => $this->startWorker(['pay', (string) $start, (string) $this->supplier->id, (string) $this->cash->id, '500', 'auto']),
            range(1, 4),
        ));

        $this->assertSame(['OK' => 4], $tally, 'No payment may fail or deadlock. Got: '.json_encode($tally));
        $this->assertSame('1000.00', $a->fresh()->paid_amount);
        $this->assertSame('500.00', $b->fresh()->paid_amount);
        $this->assertSame('paid', $a->fresh()->payment_status);
        $this->assertSame('paid', $b->fresh()->payment_status);
        $this->assertSame('-500.00', (string) $this->supplier->fresh()->getRawOriginal('balance'), 'The extra 500 is an advance with the supplier.');
        $this->assertSame('500.00', app(SupplierAccountService::class)->advanceFor($this->supplier->id));
        $this->assertBooksAgree();
    }

    public function test_cancelling_a_purchase_while_it_is_being_paid_leaves_consistent_books(): void
    {
        $bill = $this->bill('10', '100');
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->startWorker(['void', (string) $start, (string) $bill->id]),
            $this->startWorker(['pay', (string) $start, (string) $this->supplier->id, (string) $this->cash->id, '1000', 'manual', (string) $bill->id]),
        ]);

        // Whichever got there first, nothing may deadlock and the books must agree: either the bill was paid and then cancelled
        // (the 1000 is an advance), or it was cancelled first and the payment was refused.
        foreach (array_keys($tally) as $outcome) {
            $this->assertContains($outcome, ['OK', 'REFUSED'], 'Unexpected outcome: '.json_encode($tally));
        }
        $this->assertSame('voided', $bill->fresh()->status);
        $this->assertBooksAgree();
    }

    public function test_the_same_payment_cancelled_by_two_people_at_once_is_reversed_once(): void
    {
        $bill = $this->bill('10', '100');
        $payment = app(PostSupplierPayment::class)->execute([
            'supplier_id' => $this->supplier->id, 'payment_method_id' => $this->cash->id, 'amount' => '600', 'allocation' => 'auto',
        ], $this->user);
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(fn () => $this->startWorker(['voidpay', (string) $start, (string) $payment->id]), range(1, 3)));

        $this->assertSame(['OK' => 1, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('1000.00', (string) $this->supplier->fresh()->getRawOriginal('balance'));
        $this->assertSame('1000.00', $bill->fresh()->due_amount);
        $this->assertSame(1, SupplierLedgerEntry::query()->where('entry_type', 'payment_void')->count());
        $this->assertBooksAgree();
    }
}
