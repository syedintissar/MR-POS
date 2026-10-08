<?php

namespace Tests\Feature\Customers;

use App\Actions\Customers\PostCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SaleAccountService;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Feature\Backup\BackupTestCase;

/**
 * Several real, separate PHP processes working on one customer's money at the same instant: two people collecting on the
 * same invoice, cashiers selling on credit up to the limit, a sale cancelled while its money is being collected.
 * Transactions inside one process cannot show these races, so each test starts child processes
 * (tests/Support/customer_worker.php) that all wait for the same start time.
 */
class CustomerConcurrencyTest extends BackupTestCase
{
    private int $cashId;

    private int $creditId;

    /** @var list<User> */
    private array $cashiers = [];

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashId = PaymentMethod::query()->where('code', 'cash')->value('id');
        $this->creditId = PaymentMethod::query()->where('kind', 'customer_credit')->value('id');
        $this->manager = User::factory()->withRole('Manager')->create();
        for ($i = 0; $i < 5; $i++) {
            $this->cashiers[] = User::factory()->withRole('Cashier')->create();
        }
    }

    /** @param  list<string>  $args */
    private function startWorker(array $args): InvokedProcess
    {
        return Process::path(base_path())->timeout(120)->env([
            'DB_DATABASE' => 'mrpos_test', 'DB_CONNECTION' => 'mariadb', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'APP_ENV' => 'testing', 'BACKUP_DB_USERNAME' => '', 'BACKUP_DB_PASSWORD' => '',
        ])->start([PHP_BINARY, base_path('tests/Support/customer_worker.php'), ...$args]);
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

    private function credit(array $attrs = []): Customer
    {
        return Customer::factory()->create($attrs + ['allow_credit' => true]);
    }

    /** A credit sale made in this process (nothing paid). */
    private function invoice(Customer $c, string $amount): Sale
    {
        return app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $c->id, 'lines' => [['product_id' => $this->product($amount)->id, 'quantity' => '1']],
        ], $this->cashiers[0]);
    }

    private function balance(Customer $c): string
    {
        return (string) $c->fresh()->getRawOriginal('balance');
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
        $this->assertSame([], app(SaleAccountService::class)->findDrift());
        $this->assertSame([], app(InventoryService::class)->findDrift());
    }

    public function test_two_people_collecting_on_the_same_invoice_never_settle_it_twice(): void
    {
        $c = $this->credit();
        $sale = $this->invoice($c, '100');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn ($i) => $this->startWorker(['collect', (string) $start, (string) $this->cashiers[$i]->id, (string) $c->id, (string) $this->cashId, '100']),
            [1, 2],
        ));

        $this->assertSame(['OK' => 2], $tally, 'Got: '.json_encode($tally));
        $sale->refresh();
        $this->assertSame('100.00', (string) $sale->paid_amount, 'The invoice was settled once, not twice.');
        $this->assertSame('0.00', (string) $sale->due_amount);
        $this->assertSame('-100.00', $this->balance($c), 'The second 100 stays with the customer as credit.');
        $this->assertSame('100.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertSame(2, CustomerPayment::query()->where('customer_id', $c->id)->where('status', 'posted')->count());
        $this->assertBooksAgree();
    }

    public function test_cashiers_selling_on_credit_at_the_same_time_cannot_pass_the_limit(): void
    {
        $c = $this->credit(['credit_limit' => '1000']);
        $p = $this->product('400');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn ($i) => $this->startWorker(['credit', (string) $start, (string) $this->cashiers[$i]->id, (string) $c->id, '0', (string) $this->cashId, "{$p->id}:1"]),
            [0, 1, 2, 3],   // four cashiers each put 400 on the account; only two fit under 1000
        ));

        $this->assertSame(['APPROVAL' => 2, 'OK' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('800.00', $this->balance($c));
        $this->assertSame(2, Sale::query()->where('customer_id', $c->id)->count());
        $this->assertBooksAgree();
    }

    public function test_the_same_customer_credit_cannot_be_spent_by_two_tills_at_once(): void
    {
        $c = $this->credit(['allow_credit' => false]);
        app(PostCustomerPayment::class)->execute(['customer_id' => $c->id, 'payment_method_id' => $this->cashId, 'amount' => '300', 'allocation' => 'advance'], $this->manager);
        $this->assertSame('300.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $p = $this->product('300');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn ($i) => $this->startWorker(['credit', (string) $start, (string) $this->cashiers[$i]->id, (string) $c->id, '300', (string) $this->creditId, "{$p->id}:1"]),
            [0, 1],
        ));

        $this->assertSame(['OK' => 1, 'REFUSED' => 1], $tally, 'The 300 of credit can pay for one sale only. Got: '.json_encode($tally));
        $this->assertSame('0.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertSame('0.00', $this->balance($c));
        $this->assertSame(1, Sale::query()->where('customer_id', $c->id)->count());
        $this->assertBooksAgree();
    }

    public function test_cancelling_an_invoice_while_its_money_is_being_collected_leaves_the_books_straight(): void
    {
        $c = $this->credit();
        $sale = $this->invoice($c, '500');
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->startWorker(['voidsale', (string) $start, (string) $this->manager->id, (string) $sale->id]),
            $this->startWorker(['collect', (string) $start, (string) $this->cashiers[1]->id, (string) $c->id, (string) $this->cashId, '500']),
        ]);

        // either order is fine, but the end state must be the same: the invoice is cancelled and the 500 paid in is the customer's credit
        $this->assertSame(['OK' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertSame('-500.00', $this->balance($c));
        $this->assertSame('500.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertBooksAgree();
    }

    public function test_the_same_payment_cancelled_by_three_people_at_once_is_reversed_once(): void
    {
        $c = $this->credit();
        $sale = $this->invoice($c, '300');
        $payment = app(PostCustomerPayment::class)->execute(['customer_id' => $c->id, 'payment_method_id' => $this->cashId, 'amount' => '300', 'allocation' => 'auto'], $this->cashiers[0]);
        $this->assertSame('0.00', $this->balance($c));
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(fn () => $this->startWorker(['voidpay', (string) $start, (string) $this->manager->id, (string) $payment->id]), [1, 2, 3]));

        $this->assertSame(['OK' => 1, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('300.00', $this->balance($c), 'The account was corrected once, not three times.');
        $this->assertSame('300.00', (string) $sale->fresh()->due_amount);
        $this->assertBooksAgree();
    }

    public function test_collections_and_credit_sales_on_one_account_at_once_never_deadlock(): void
    {
        $c = $this->credit();
        $this->invoice($c, '200');
        $p = $this->product('50');
        $start = microtime(true) + 6;

        $workers = [];
        foreach ([0, 1, 2] as $i) {
            $workers[] = $this->startWorker(['credit', (string) $start, (string) $this->cashiers[$i]->id, (string) $c->id, '0', (string) $this->cashId, "{$p->id}:1"]);
        }
        foreach ([3, 4] as $i) {
            $workers[] = $this->startWorker(['collect', (string) $start, (string) $this->cashiers[$i]->id, (string) $c->id, (string) $this->cashId, '75']);
        }
        $tally = $this->tally($workers);

        $this->assertSame(['OK' => 5], $tally, 'No deadlock may reach a cashier. Got: '.json_encode($tally));
        $this->assertSame('200.00', $this->balance($c), '200 + 3 x 50 sold - 2 x 75 collected');
        $this->assertBooksAgree();
    }
}
