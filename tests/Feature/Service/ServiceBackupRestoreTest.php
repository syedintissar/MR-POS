<?php

namespace Tests\Feature\Service;

use App\Actions\Service\AddServiceCharge;
use App\Actions\Service\AddServicePart;
use App\Actions\Service\CancelServiceJob;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Actions\Service\DeliverServiceJob;
use App\Actions\Service\ReceiveServiceJob;
use App\Actions\Service\TakeServiceAdvance;
use App\Models\Backup;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Backup\RestoreService;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Services\Service\ServiceJobService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Backup\BackupTestCase;

/** A backup that holds service jobs restores them exactly, with their protections (append-only history, constraints) and a clean integrity check. */
class ServiceBackupRestoreTest extends BackupTestCase
{
    private function snapshot(): array
    {
        $c = Customer::query()->where('name', 'Restore Rafay')->first();

        return [
            'jobs' => ServiceJob::query()->count(), 'items' => DB::table('service_job_items')->count(), 'history' => DB::table('service_status_history')->count(),
            'allocations' => DB::table('service_job_payments')->count(), 'payments' => DB::table('customer_payments')->count(),
            'ledger' => DB::table('customer_ledger_entries')->count(), 'balance' => $c ? (string) $c->fresh()->getRawOriginal('balance') : null,
            'advance' => $c ? app(CustomerAccountService::class)->advanceFor($c->id) : null,
            'stock' => (string) Product::query()->where('sku', 'RST-1')->value('current_stock'), 'invoices' => ServiceJob::query()->whereNotNull('invoice_no')->orderBy('id')->pluck('invoice_no')->all(),
            'types' => ServiceType::query()->count(),
            'triggers' => DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereIn('TRIGGER_NAME', ['service_history_no_update', 'service_history_no_delete'])->count(),
        ];
    }

    public function test_service_data_comes_back_exactly_and_the_protections_survive(): void
    {
        $admin = $this->admin();
        $manager = User::factory()->withRole('Manager')->create();
        $cashier = User::factory()->withRole('Cashier')->create();
        $tech = User::factory()->withRole('Technician')->create();
        $cash = PaymentMethod::query()->where('code', 'cash')->value('id');
        $c = Customer::factory()->create(['name' => 'Restore Rafay', 'allow_credit' => true]);
        $toner = Product::factory()->create(['sku' => 'RST-1', 'sale_price' => '1500']);
        DB::transaction(fn () => app(InventoryService::class)->move($toner, '10', 'purchase', ['unit_cost' => '600']));
        $type = ServiceType::query()->value('id');

        $make = function () use ($c, $type, $tech, $cashier, $manager) {
            $job = app(ReceiveServiceJob::class)->execute(['customer_id' => $c->id, 'service_type_id' => $type, 'device_type' => 'printer', 'reported_problem' => 'Dead', 'technician_id' => $tech->id], $cashier);

            return app(ChangeServiceJobStatus::class)->execute(app(ChangeServiceJobStatus::class)->execute($job, 'diagnosing', $manager), 'in_progress', $manager);
        };

        // one job delivered on account (part + charge + advance + final payment), one cancelled with its part returned
        $a = $make();
        app(AddServicePart::class)->execute($a, ['product_id' => $toner->id, 'quantity' => '2'], $tech);
        app(AddServiceCharge::class)->execute($a, ['description' => 'Labour', 'unit_price' => '500'], $manager);
        app(TakeServiceAdvance::class)->execute($a, ['payment_method_id' => $cash, 'amount' => '400'], $cashier);
        $a = app(ChangeServiceJobStatus::class)->execute($a->fresh(), 'completed', $manager);
        app(DeliverServiceJob::class)->execute($a, [['payment_method_id' => $cash, 'amount' => '600']], $cashier);
        $b = $make();
        app(AddServicePart::class)->execute($b, ['product_id' => $toner->id, 'quantity' => '1'], $tech);
        app(AddServiceCharge::class)->execute($b, ['description' => 'Labour', 'unit_price' => '300'], $manager);
        app(TakeServiceAdvance::class)->execute($b, ['payment_method_id' => $cash, 'amount' => '100'], $cashier);
        app(CancelServiceJob::class)->execute($b->fresh(), 'Declined', $manager);

        $this->assertSame([], app(ServiceJobService::class)->findDrift());
        $before = $this->snapshot();
        $backup = $this->service()->create(Backup::TIER_MANUAL, $admin);

        // the bad day: another job, another payment, more stock used
        $z = $make();
        app(AddServicePart::class)->execute($z, ['product_id' => $toner->id, 'quantity' => '3'], $tech);
        app(TakeServiceAdvance::class)->execute($z->fresh(), ['payment_method_id' => $cash, 'amount' => '50'], $cashier);
        $this->assertNotSame($before, $this->snapshot());

        app(RestoreService::class)->restore($this->zipPath($backup), $admin, null, maintenance: false, source: $backup);

        $this->assertSame($before, $this->snapshot(), 'everything is exactly as it was when the backup was made');
        $this->assertSame(2, $before['triggers']);
        $this->assertSame(0, ServiceJob::query()->where('job_no', $z->job_no)->count(), 'the job made after the backup is gone');

        foreach ([
            'the service history cannot be edited after a restore' => fn () => DB::table('service_status_history')->limit(1)->update(['note' => 'x']),
            'the service history cannot be deleted from after a restore' => fn () => DB::table('service_status_history')->limit(1)->delete(),
            'an invalid job status is refused after a restore' => fn () => DB::table('service_jobs')->limit(1)->update(['status' => 'bogus']),
            'a delivered job without an invoice is refused after a restore' => fn () => DB::table('service_jobs')->where('status', 'delivered')->limit(1)->update(['invoice_no' => null]),
            'a part line without a product is refused after a restore' => fn () => DB::table('service_job_items')->where('line_type', 'part')->limit(1)->update(['product_id' => null]),
            'a negative job total is refused after a restore' => fn () => DB::table('service_jobs')->limit(1)->update(['grand_total' => -1]),
        ] as $label => $attempt) {
            try {
                $attempt();
                $this->fail("{$label}: the database accepted it");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], app(ServiceJobService::class)->findDrift());
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
    }
}
