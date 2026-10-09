<?php

namespace Tests\Feature\Service;

use App\Actions\Service\AddServiceCharge;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Actions\Service\ReceiveServiceJob;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceStatusHistory;
use App\Models\ServiceType;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SaleAccountService;
use App\Services\Service\ServiceJobService;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Backup\BackupTestCase;

/**
 * Several real, separate PHP processes working on service jobs at the same instant: three tills delivering the same job, two
 * workshops promising the last toner, a cancellation racing an advance. Transactions inside one process cannot show these races, so each
 * test starts child processes (tests/Support/service_worker.php) that all wait for the same start time.
 */
class ServiceConcurrencyTest extends BackupTestCase
{
    private int $cashId;

    private User $manager;

    private User $tech;

    /** @var list<User> */
    private array $cashiers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashId = PaymentMethod::query()->where('code', 'cash')->value('id');
        $this->manager = User::factory()->withRole('Manager')->create();
        $this->tech = User::factory()->withRole('Technician')->create();
        for ($i = 0; $i < 4; $i++) {
            $this->cashiers[] = User::factory()->withRole('Cashier')->create();
        }
    }

    /** @param  list<string>  $args */
    private function startWorker(array $args): InvokedProcess
    {
        return Process::path(base_path())->timeout(120)->env([
            'DB_DATABASE' => 'mrpos_test', 'DB_CONNECTION' => 'mariadb', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'APP_ENV' => 'testing', 'BACKUP_DB_USERNAME' => '', 'BACKUP_DB_PASSWORD' => '',
        ])->start([PHP_BINARY, base_path('tests/Support/service_worker.php'), ...$args]);
    }

    /** @return array<string, int> */
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

    private function job(Customer $customer, string $status = 'diagnosing', string $charge = '0'): ServiceJob
    {
        $job = app(ReceiveServiceJob::class)->execute([
            'customer_id' => $customer->id, 'service_type_id' => ServiceType::query()->value('id'), 'device_type' => 'printer',
            'reported_problem' => 'Does not print', 'technician_id' => $this->tech->id,
        ], $this->cashiers[0]);
        foreach (['diagnosing', 'in_progress', 'completed'] as $step) {
            $job = app(ChangeServiceJobStatus::class)->execute($job->fresh(), $step, $this->manager);
            if ($step === $status) {
                break;
            }
        }
        if ($charge !== '0') {
            app(AddServiceCharge::class)->execute($job, ['description' => 'Repair', 'unit_price' => $charge], $this->manager);
        }

        return $job->fresh();
    }

    private function product(string $qty): Product
    {
        $p = Product::factory()->create(['sale_price' => '1500']);
        DB::transaction(fn () => app(InventoryService::class)->move($p, $qty, 'purchase', ['unit_cost' => '600']));

        return $p->refresh();
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(ServiceJobService::class)->findDrift());
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
        $this->assertSame([], app(SaleAccountService::class)->findDrift());
        $this->assertSame([], app(InventoryService::class)->findDrift());
    }

    public function test_two_workshops_promising_the_last_part_get_it_once(): void
    {
        $toner = $this->product('1');
        $customer = Customer::factory()->create();
        $a = $this->job($customer, 'diagnosing');
        $b = $this->job($customer, 'diagnosing');
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->startWorker(['part', (string) $start, (string) $this->tech->id, (string) $a->id, (string) $toner->id, '1']),
            $this->startWorker(['part', (string) $start, (string) $this->manager->id, (string) $b->id, (string) $toner->id, '1']),
        ]);

        $this->assertSame(['OK' => 1, 'REFUSED' => 1], $tally, 'Got: '.json_encode($tally));
        $this->assertSame('0.000', $toner->fresh()->current_stock);
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'service_consumption')->count());
        $this->assertSame(1, DB::table('service_job_items')->where('line_type', 'part')->count(), 'the refused one must leave no line behind');
        $this->assertBooksAgree();
    }

    public function test_the_same_job_delivered_from_three_tills_is_invoiced_once(): void
    {
        $customer = Customer::factory()->create(['allow_credit' => true]);
        $job = $this->job($customer, 'completed', '1000');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn ($i) => $this->startWorker(['deliver', (string) $start, (string) $this->cashiers[$i]->id, (string) $job->id, (string) $this->cashId, '1000']),
            [0, 1, 2],
        ));

        $this->assertSame(['OK' => 1, 'REFUSED' => 2], $tally, 'Got: '.json_encode($tally));
        $job->refresh();
        $this->assertSame('delivered', $job->status);
        $this->assertSame('SRV-000001', $job->invoice_no);
        $this->assertSame(1, CustomerLedgerEntry::query()->where('entry_type', 'service_invoice')->count());
        $this->assertSame(1, CustomerPayment::query()->where('type', 'receipt')->count(), 'the customer paid once');
        $this->assertSame('0.00', (string) $customer->fresh()->getRawOriginal('balance'));
        $this->assertSame(1, ServiceStatusHistory::query()->where('service_job_id', $job->id)->where('to_status', 'delivered')->count());
        $this->assertBooksAgree();
    }

    public function test_two_jobs_of_one_customer_delivered_on_credit_together_cannot_pass_the_limit(): void
    {
        $customer = Customer::factory()->create(['allow_credit' => true, 'credit_limit' => '1000']);
        $a = $this->job($customer, 'completed', '600');
        $b = $this->job($customer, 'completed', '600');
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->startWorker(['deliver', (string) $start, (string) $this->cashiers[0]->id, (string) $a->id, (string) $this->cashId, '0']),
            $this->startWorker(['deliver', (string) $start, (string) $this->cashiers[1]->id, (string) $b->id, (string) $this->cashId, '0']),
        ]);

        $this->assertSame(['OK' => 1, 'REFUSED' => 1], $tally, 'Both 600 jobs fitted under a limit of 1000. Got: '.json_encode($tally));
        $this->assertSame('600.00', (string) $customer->fresh()->getRawOriginal('balance'));
        $this->assertBooksAgree();
    }

    public function test_an_advance_racing_a_cancellation_leaves_the_books_straight(): void
    {
        $customer = Customer::factory()->create(['allow_credit' => true]);
        $job = $this->job($customer, 'in_progress', '1000');
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->startWorker(['cancel', (string) $start, (string) $this->manager->id, (string) $job->id]),
            $this->startWorker(['advance', (string) $start, (string) $this->cashiers[0]->id, (string) $job->id, (string) $this->cashId, '300']),
        ]);

        // either the advance came first (then it became the customer's credit) or the cancellation did (then the advance was refused)
        $this->assertContains($tally, [['OK' => 2], ['OK' => 1, 'REFUSED' => 1]], 'Got: '.json_encode($tally));
        $this->assertSame('cancelled', $job->fresh()->status);
        $advance = (string) app(CustomerAccountService::class)->advanceFor($customer->id);
        $this->assertSame((string) $customer->fresh()->getRawOriginal('balance'), $advance === '0.00' ? '0.00' : '-'.$advance);
        $this->assertBooksAgree();
    }

    public function test_two_people_moving_the_same_job_on_at_once_move_it_once(): void
    {
        $job = $this->job(Customer::factory()->create(), 'diagnosing');
        $start = microtime(true) + 6;

        $tally = $this->tally([
            $this->startWorker(['status', (string) $start, (string) $this->manager->id, (string) $job->id, 'waiting_for_approval']),
            $this->startWorker(['status', (string) $start, (string) $this->tech->id, (string) $job->id, 'in_progress']),
        ]);

        $this->assertSame(['OK' => 1, 'REFUSED' => 1], $tally, 'Both moves left "diagnosing". Got: '.json_encode($tally));
        $last = ServiceStatusHistory::query()->where('service_job_id', $job->id)->latest('id')->value('to_status');
        $this->assertSame($last, $job->fresh()->status);
        $this->assertBooksAgree();
    }

    public function test_many_parts_added_to_one_job_at_once_all_arrive_and_the_totals_add_up(): void
    {
        $toner = $this->product('10');
        $job = $this->job(Customer::factory()->create(), 'diagnosing');
        $start = microtime(true) + 6;

        $tally = $this->tally(array_map(
            fn () => $this->startWorker(['part', (string) $start, (string) $this->tech->id, (string) $job->id, (string) $toner->id, '1']),
            range(1, 4),
        ));

        $this->assertSame(['OK' => 4], $tally, 'No deadlock may reach a technician. Got: '.json_encode($tally));
        $this->assertSame('6.000', $toner->fresh()->current_stock);
        $this->assertSame('6000.00', (string) $job->fresh()->grand_total);
        $this->assertBooksAgree();
    }
}
