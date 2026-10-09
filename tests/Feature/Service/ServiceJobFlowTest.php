<?php

namespace Tests\Feature\Service;

use App\Actions\Service\AddServiceCharge;
use App\Actions\Service\AddServicePart;
use App\Actions\Service\AssignServiceJob;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Actions\Service\ReceiveServiceJob;
use App\Actions\Service\RemoveServiceLine;
use App\Actions\Service\UpdateServiceJob;
use App\Actions\Service\UpdateServicePricing;
use App\Exceptions\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceStatusHistory;
use App\Models\ServiceType;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Service\ServiceJobAccess;
use App\Services\Service\ServiceJobService;
use App\Services\SettingsService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The life of a service job up to delivery: intake, the state machine, parts and stock, charges, who may touch what. */
class ServiceJobFlowTest extends TestCase
{
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $admin;

    private User $manager;

    private User $cashier;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->withRole('Admin')->create();
        $this->manager = User::factory()->withRole('Manager')->create();
        $this->cashier = User::factory()->withRole('Cashier')->create();
        $this->tech = User::factory()->withRole('Technician')->create();
    }

    private function type(string $name = 'Printer repair'): ServiceType
    {
        return ServiceType::query()->where('name', $name)->firstOrFail();
    }

    /** A job taken in by the cashier for a named customer, assigned to the technician. */
    private function receive(array $over = [], ?User $by = null): ServiceJob
    {
        return app(ReceiveServiceJob::class)->execute($over + [
            'customer_id' => Customer::factory()->create(['allow_credit' => true])->id,
            'service_type_id' => $this->type()->id, 'device_type' => 'printer', 'model' => 'LaserJet 1020', 'serial_number' => 'SN-'.random_int(1000, 9999),
            'reported_problem' => 'Paper jam and strange noise', 'technician_id' => $this->tech->id,
        ], $by ?? $this->cashier);
    }

    private function moveTo(ServiceJob $job, string $to, ?User $by = null, array $extra = []): ServiceJob
    {
        return app(ChangeServiceJobStatus::class)->execute($job, $to, $by ?? $this->manager, $extra);
    }

    /** Walks a job to a status the legal way. */
    private function walk(ServiceJob $job, string ...$path): ServiceJob
    {
        foreach ($path as $to) {
            $job = $this->moveTo($job, $to, $this->manager, $to === ServiceJob::APPROVED ? ['approval_method' => 'phone'] : []);
        }

        return $job;
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(ServiceJobService::class)->findDrift());
        $this->assertSame([], $this->inventory()->findDrift());
    }

    // ---------------------------------------------------------------- intake

    public function test_a_job_is_received_with_a_number_a_history_and_an_audit_entry(): void
    {
        $customer = Customer::factory()->create(['name' => 'Bilal Traders', 'phone' => '0300-1234567']);
        $job = $this->receive(['customer_id' => $customer->id]);

        $this->assertSame('JOB-000001', $job->job_no);
        $this->assertSame(ServiceJob::RECEIVED, $job->status);
        $this->assertSame('Bilal Traders', $job->contact_name);
        $this->assertSame('0300-1234567', $job->contact_phone);
        $this->assertSame($this->tech->id, $job->technician_id);
        $this->assertSame($this->cashier->id, $job->created_by);
        $this->assertSame('0.00', (string) $job->grand_total);
        $this->assertNull($job->invoice_no);

        $h = ServiceStatusHistory::query()->where('service_job_id', $job->id)->get();
        $this->assertCount(1, $h);
        $this->assertNull($h[0]->from_status);
        $this->assertSame(ServiceJob::RECEIVED, $h[0]->to_status);
        $this->assertSame(1, AuditLog::query()->where('event', 'service_job_received')->count());
        $this->assertSame('JOB-000002', $this->receive()->job_no);
        $this->assertBooksAgree();
    }

    public function test_the_suggested_charge_of_the_service_type_becomes_the_first_line(): void
    {
        $this->type('Toner refill')->update(['default_charge' => '800']);

        $job = $this->receive(['service_type_id' => $this->type('Toner refill')->id, 'device_type' => 'toner']);
        $this->assertSame('800.00', (string) $job->grand_total);
        $this->assertSame('800.00', (string) $job->service_charges);
        $this->assertSame(1, $job->items()->count());

        $none = $this->receive(['service_type_id' => $this->type('Toner refill')->id, 'add_default_charge' => false]);
        $this->assertSame('0.00', (string) $none->grand_total);
        $this->assertBooksAgree();
    }

    public function test_intake_rules(): void
    {
        $walkIn = ['customer_id' => null];

        try {
            $this->receive($walkIn);
            $this->fail('A walk-in job without a name and phone was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('name and a phone number', $e->getMessage());
        }
        $job = $this->receive($walkIn + ['contact_name' => 'Mr Ali', 'contact_phone' => '0345-1112233']);
        $this->assertTrue($job->customer->is_walk_in);
        $this->assertSame('Mr Ali', $job->contact_name);

        $inactive = Customer::factory()->create(['is_active' => false]);
        foreach ([
            ['customer_id' => $inactive->id], ['reported_problem' => '  '], ['service_type_id' => 9999], ['device_type' => 'fridge'], ['technician_id' => User::factory()->create()->id],
        ] as $bad) {
            try {
                $this->receive($bad);
                $this->fail('Bad intake accepted: '.json_encode($bad));
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $off = $this->type('Other');
        $off->update(['is_active' => false]);
        $this->expectException(DomainException::class);
        $this->receive(['service_type_id' => $off->id]);
    }

    public function test_a_technician_who_takes_a_job_in_gets_it_and_cannot_assign_others(): void
    {
        $job = $this->receive(['technician_id' => null], $this->tech);
        $this->assertSame($this->tech->id, $job->technician_id);
        $this->assertTrue(ServiceJobAccess::canSee($this->tech, $job));

        $this->expectException(AuthorizationException::class);
        $this->receive(['technician_id' => $this->tech->id], $this->tech);
    }

    public function test_assigning_changes_who_can_see_the_job_and_is_written_in_the_history(): void
    {
        $other = User::factory()->withRole('Technician')->create();
        $job = $this->receive();
        $this->assertTrue(ServiceJobAccess::canSee($this->tech, $job));
        $this->assertFalse(ServiceJobAccess::canSee($other, $job));
        $this->assertSame(1, ServiceJobAccess::visibleTo($this->tech)->count());
        $this->assertSame(0, ServiceJobAccess::visibleTo($other)->count());
        $this->assertSame(1, ServiceJobAccess::visibleTo($this->cashier)->count());

        $job = app(AssignServiceJob::class)->execute($job, $other->id, $this->cashier);
        $this->assertFalse(ServiceJobAccess::canSee($this->tech, $job));
        $this->assertTrue(ServiceJobAccess::canSee($other, $job));
        $this->assertSame(2, ServiceStatusHistory::query()->where('service_job_id', $job->id)->count());
        $this->assertStringContainsString($other->name, (string) ServiceStatusHistory::query()->where('service_job_id', $job->id)->latest('id')->value('note'));

        try {
            app(AssignServiceJob::class)->execute($job, User::factory()->create()->id, $this->manager);
            $this->fail('Someone who cannot work on jobs was given a service job.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(AuthorizationException::class);
        app(AssignServiceJob::class)->execute($job, $this->tech->id, $other);
    }

    // ---------------------------------------------------------------- the state machine

    public function test_the_whole_legal_path_writes_one_history_line_per_step(): void
    {
        $job = $this->receive();
        $job = $this->walk($job, 'inspection_pending', 'diagnosing', 'waiting_for_approval', 'approved', 'in_progress', 'waiting_for_parts', 'in_progress', 'completed');

        $this->assertSame(ServiceJob::COMPLETED, $job->status);
        $this->assertNotNull($job->completed_at);
        $this->assertNotNull($job->approved_at);
        $this->assertSame('phone', $job->approval_method);
        $this->assertSame(9, ServiceStatusHistory::query()->where('service_job_id', $job->id)->count());
        $this->assertSame(['received', 'inspection_pending', 'diagnosing', 'waiting_for_approval', 'approved', 'in_progress', 'waiting_for_parts', 'in_progress', 'completed'],
            ServiceStatusHistory::query()->where('service_job_id', $job->id)->orderBy('id')->pluck('to_status')->all());
        $this->assertBooksAgree();
    }

    public function test_a_refill_can_skip_approval(): void
    {
        $job = $this->walk($this->receive(), 'diagnosing', 'in_progress', 'completed');
        $this->assertSame(ServiceJob::COMPLETED, $job->status);
        $this->assertNull($job->approved_at);
    }

    public function test_every_move_is_checked_against_the_state_machine(): void
    {
        $open = array_values(array_diff(array_keys(ServiceJob::STATUSES), [ServiceJob::DELIVERED, ServiceJob::CANCELLED]));
        $targets = array_diff(array_keys(ServiceJob::STATUSES), [ServiceJob::DELIVERED, ServiceJob::CANCELLED]);   // those two have their own actions

        foreach ($open as $from) {
            foreach ($targets as $to) {
                $job = $this->receive();
                DB::table('service_jobs')->where('id', $job->id)->update(['status' => $from]);
                DB::table('service_status_history')->insert(['service_job_id' => $job->id, 'from_status' => 'received', 'to_status' => $from, 'changed_by' => $this->manager->id, 'changed_at' => now()->format('Y-m-d H:i:s.u')]);
                $extra = ['approval_method' => 'in_person', 'note' => 'reopened because the customer came back'];

                $allowed = in_array($to, ServiceJob::TRANSITIONS[$from], true);
                try {
                    $after = app(ChangeServiceJobStatus::class)->execute($job->fresh(), $to, $this->manager, $extra);
                    $this->assertTrue($allowed, "{$from} -> {$to} was accepted but the state machine forbids it");
                    $this->assertSame($to, $after->status);
                } catch (DomainException) {
                    $this->assertFalse($allowed, "{$from} -> {$to} was refused but the state machine allows it");
                    $this->assertSame($from, $job->fresh()->status, 'a refused move must change nothing');
                }
            }
        }
    }

    public function test_delivered_and_cancelled_cannot_be_reached_by_a_plain_status_change_and_are_locked(): void
    {
        $job = $this->walk($this->receive(), 'diagnosing', 'in_progress', 'completed');
        foreach ([ServiceJob::DELIVERED, ServiceJob::CANCELLED] as $to) {
            try {
                $this->moveTo($job, $to);
                $this->fail("{$to} reached without its own action");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        DB::table('service_jobs')->where('id', $job->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);
        $this->expectException(DomainException::class);
        $this->moveTo($job->fresh(), ServiceJob::IN_PROGRESS);
    }

    public function test_approval_needs_a_method_and_stores_the_approved_estimate(): void
    {
        $job = $this->walk($this->receive(), 'diagnosing', 'waiting_for_approval');
        app(UpdateServicePricing::class)->execute($job, ['estimate_amount' => '4500'], $this->tech);

        try {
            $this->moveTo($job, ServiceJob::APPROVED);
            $this->fail('Approval without a method was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('how the customer approved', $e->getMessage());
        }

        $job = $this->moveTo($job->fresh(), ServiceJob::APPROVED, $this->cashier, ['approval_method' => 'whatsapp']);
        $this->assertSame('whatsapp', $job->approval_method);
        $this->assertSame('4500.00', (string) $job->approved_amount);
        $this->assertSame($this->cashier->id, $job->approved_by);
        $this->assertNotNull($job->approved_at);
    }

    public function test_a_completed_job_can_be_reopened_only_with_a_reason(): void
    {
        $job = $this->walk($this->receive(), 'diagnosing', 'in_progress', 'completed');

        try {
            $this->moveTo($job, ServiceJob::IN_PROGRESS);
            $this->fail('Reopened without a reason.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reopened', $e->getMessage());
        }

        $job = $this->moveTo($job, ServiceJob::IN_PROGRESS, $this->manager, ['note' => 'Customer found another fault']);
        $this->assertNull($job->completed_at);
        $this->assertSame('Customer found another fault', ServiceStatusHistory::query()->where('service_job_id', $job->id)->latest('id')->value('note'));
    }

    public function test_the_history_cannot_be_edited_or_deleted_even_by_hand(): void
    {
        $job = $this->receive();
        foreach ([
            fn () => DB::table('service_status_history')->where('service_job_id', $job->id)->update(['note' => 'hacked']),
            fn () => DB::table('service_status_history')->where('service_job_id', $job->id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The database accepted a change to the service history.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_two_people_changing_the_status_at_once_are_judged_by_the_stored_status(): void
    {
        $job = $this->walk($this->receive(), 'diagnosing');
        $stale = $job->fresh();   // both screens showed "diagnosing"

        $this->moveTo($job, ServiceJob::WAITING_APPROVAL);
        $this->expectException(DomainException::class);   // the second one asks for diagnosing -> in_progress, but the job is already waiting for approval
        $this->moveTo($stale, ServiceJob::IN_PROGRESS);
    }

    // ---------------------------------------------------------------- editing

    public function test_the_counter_edits_the_card_and_the_workshop_writes_the_diagnosis(): void
    {
        $job = $this->receive();
        $edit = app(UpdateServiceJob::class);

        $job = $edit->execute($job, ['model' => 'LaserJet 1022n', 'priority' => 'urgent', 'accessories_received' => 'Power cable, tray'], $this->cashier);
        $this->assertSame('LaserJet 1022n', $job->model);
        $this->assertSame('urgent', $job->priority);

        $job = $edit->execute($job, ['diagnosis' => 'Fuser worn out', 'internal_notes' => 'Order a fuser'], $this->tech);
        $this->assertSame('Fuser worn out', $job->diagnosis);

        try {
            $edit->execute($job, ['diagnosis' => 'I think it is the motor'], $this->cashier);
            $this->fail('A cashier wrote a diagnosis.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('Fuser worn out', $job->fresh()->diagnosis);

        $log = AuditLog::query()->where('event', 'service_job_updated')->get();
        $this->assertCount(2, $log);
        $this->expectException(DomainException::class);
        $edit->execute($job, ['reported_problem' => ' '], $this->cashier);
    }

    public function test_a_technician_cannot_edit_or_change_the_status_of_someone_elses_job(): void
    {
        $other = User::factory()->withRole('Technician')->create();
        $job = $this->receive();

        foreach ([
            fn () => app(UpdateServiceJob::class)->execute($job, ['diagnosis' => 'x'], $other),
            fn () => $this->moveTo($job, ServiceJob::DIAGNOSING, $other),
            fn () => app(AddServiceCharge::class)->execute($job, ['description' => 'Labour', 'unit_price' => '500'], $other),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A technician worked on a job that is not theirs.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(ServiceJob::RECEIVED, $job->fresh()->status);

        $this->moveTo($job, ServiceJob::DIAGNOSING, $this->tech);   // their own job is fine
        $this->assertSame(ServiceJob::DIAGNOSING, $job->fresh()->status);
    }

    // ---------------------------------------------------------------- parts and stock

    public function test_a_part_leaves_the_stock_at_once_at_cost_and_is_billed(): void
    {
        $toner = $this->stocked('10', '600', ['name' => 'Toner 12A', 'sale_price' => '1500', 'wholesale_price' => '1200']);
        $job = $this->walk($this->receive(), 'diagnosing');

        $line = app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '2'], $this->tech);

        $this->assertSame('8.000', $toner->fresh()->current_stock);
        $this->assertSame('part', $line->line_type);
        $this->assertSame('1500.00', (string) $line->unit_price);
        $this->assertSame('600.0000', (string) $line->unit_cost);
        $this->assertSame('3000.00', (string) $line->line_total);

        $m = StockMovement::query()->where('product_id', $toner->id)->where('movement_type', 'service_consumption')->firstOrFail();
        $this->assertSame('-2.000', (string) $m->quantity);
        $this->assertSame('service_job', $m->source_type);
        $this->assertSame($job->id, $m->source_id);
        $this->assertSame($line->id, $m->source_line_id);

        $job->refresh();
        $this->assertSame('3000.00', (string) $job->parts_amount);
        $this->assertSame('1200.00', (string) $job->parts_cost);
        $this->assertSame('3000.00', (string) $job->grand_total);
        $this->assertSame(1, AuditLog::query()->where('event', 'service_part_added')->count());
        $this->assertBooksAgree();
    }

    public function test_a_wholesale_customer_is_billed_the_wholesale_price(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500', 'wholesale_price' => '1200']);
        $wholesale = Customer::factory()->create(['price_tier' => 'wholesale']);
        $job = $this->walk($this->receive(['customer_id' => $wholesale->id]), 'diagnosing');

        $line = app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '1'], $this->tech);
        $this->assertSame('1200.00', (string) $line->unit_price);
    }

    public function test_parts_can_only_be_added_from_diagnosing_to_completed(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500']);
        $job = $this->receive();

        try {
            app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '1'], $this->tech);
            $this->fail('A part was added to a job that had not been looked at.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('diagnosed', $e->getMessage());
        }
        $this->assertSame('10.000', $toner->fresh()->current_stock);

        $job = $this->walk($job, 'diagnosing', 'in_progress', 'completed');
        app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '1'], $this->tech);
        $this->assertSame('9.000', $toner->fresh()->current_stock);
    }

    public function test_not_enough_stock_leaves_the_job_and_the_stock_untouched(): void
    {
        $toner = $this->stocked('1', '600', ['sale_price' => '1500']);
        $job = $this->walk($this->receive(), 'diagnosing');

        try {
            app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '2'], $this->tech);
            $this->fail('More than the stock was taken.');
        } catch (InsufficientStockException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('1.000', $toner->fresh()->current_stock);
        $this->assertSame(0, $job->items()->count(), 'the line must not survive a failed stock move');
        $this->assertSame('0.00', (string) $job->fresh()->grand_total);
        $this->assertBooksAgree();
    }

    public function test_service_items_and_missing_products_cannot_be_added_as_parts(): void
    {
        $svc = Product::factory()->service()->create(['sale_price' => '800']);
        $job = $this->walk($this->receive(), 'diagnosing');

        foreach ([$svc->id, 999999] as $id) {
            try {
                app(AddServicePart::class)->execute($job, ['product_id' => $id, 'quantity' => '1'], $this->tech);
                $this->fail('Accepted a product that cannot be a part.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['0', '-1', ''] as $q) {
            try {
                app(AddServicePart::class)->execute($job, ['product_id' => $svc->id, 'quantity' => $q], $this->tech);
                $this->fail('Accepted a bad quantity.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_price_or_discount_on_a_part_needs_the_charges_permission(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500']);
        $job = $this->walk($this->receive(), 'diagnosing');
        $limited = User::factory()->create();
        $limited->givePermissionTo(['service_jobs.view_all', 'service_jobs.manage_parts']);

        try {
            app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '1', 'unit_price' => '900'], $limited);
            $this->fail('A price was changed without permission.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $line = app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '1', 'unit_price' => '1400', 'discount_amount' => '100'], $this->tech);
        $this->assertSame('1300.00', (string) $line->line_total);
        $this->assertSame('9.000', $toner->fresh()->current_stock);
    }

    public function test_a_part_taken_off_goes_back_to_stock_at_the_cost_it_left_at(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500']);
        $job = $this->walk($this->receive(), 'diagnosing');
        $line = app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '3'], $this->tech);
        $this->assertSame('7.000', $toner->fresh()->current_stock);

        $removed = app(RemoveServiceLine::class)->execute($line, 'returned_to_stock', null, $this->tech);
        $this->assertSame('removed', $removed->status);
        $this->assertSame('returned_to_stock', $removed->removal_disposition);
        $this->assertSame('10.000', $toner->fresh()->current_stock);
        $this->assertSame('600.0000', (string) $toner->fresh()->average_cost);
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'service_return')->count());
        $this->assertSame('0.00', (string) $job->fresh()->grand_total);
        $this->assertSame('0.00', (string) $job->fresh()->parts_cost);
        $this->assertBooksAgree();

        $this->expectException(DomainException::class);   // never twice
        app(RemoveServiceLine::class)->execute($line, 'returned_to_stock', null, $this->tech);
    }

    public function test_a_written_off_part_stays_out_of_stock_and_needs_a_reason(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500']);
        $job = $this->walk($this->receive(), 'diagnosing');
        $line = app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '1'], $this->tech);

        try {
            app(RemoveServiceLine::class)->execute($line, 'written_off', null, $this->tech);
            $this->fail('A part was written off without a reason.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->assertTrue($line->fresh()->isActive());

        $removed = app(RemoveServiceLine::class)->execute($line, 'written_off', 'damaged while fitting', $this->tech);
        $this->assertSame('written_off', $removed->removal_disposition);
        $this->assertSame('9.000', $toner->fresh()->current_stock, 'it already left the stock when it was added');
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'service_return')->count());
        $this->assertSame('0.00', (string) $job->fresh()->grand_total);
        $this->assertBooksAgree();
    }

    public function test_adding_parts_at_the_same_moment_never_takes_more_than_exists(): void
    {
        $toner = $this->stocked('3', '600', ['sale_price' => '1500']);
        $a = $this->walk($this->receive(), 'diagnosing');
        $b = $this->walk($this->receive(), 'diagnosing');

        app(AddServicePart::class)->execute($a, ['product_id' => $toner->id, 'quantity' => '2'], $this->tech);
        try {
            app(AddServicePart::class)->execute($b, ['product_id' => $toner->id, 'quantity' => '2'], $this->tech);
            $this->fail('The same toner was promised twice.');
        } catch (InsufficientStockException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('1.000', $toner->fresh()->current_stock);
        $this->assertBooksAgree();
    }

    // ---------------------------------------------------------------- charges and pricing

    public function test_charges_discount_and_estimate_make_up_the_total(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500']);
        $job = $this->walk($this->receive(), 'diagnosing');
        app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '1'], $this->tech);
        $charge = app(AddServiceCharge::class)->execute($job, ['description' => 'Labour', 'quantity' => '2', 'unit_price' => '500', 'discount_amount' => '100'], $this->tech);
        $this->assertSame('900.00', (string) $charge->line_total);

        $job = app(UpdateServicePricing::class)->execute($job, ['estimate_amount' => '2500', 'discount_amount' => '50'], $this->manager);
        $this->assertSame('2500.00', (string) $job->estimate_amount);
        $this->assertSame('900.00', (string) $job->service_charges);
        $this->assertSame('1500.00', (string) $job->parts_amount);
        $this->assertSame('50.00', (string) $job->discount_amount);
        $this->assertSame('2350.00', (string) $job->grand_total);

        $job = app(UpdateServicePricing::class)->execute($job, ['estimate_amount' => ''], $this->manager);
        $this->assertNull($job->estimate_amount);

        app(RemoveServiceLine::class)->execute($charge, 'ignored', null, $this->tech);
        $this->assertSame('1450.00', (string) $job->fresh()->grand_total);
        $this->assertBooksAgree();
    }

    public function test_a_discount_larger_than_the_job_is_refused_and_changes_nothing(): void
    {
        $job = $this->walk($this->receive(), 'diagnosing');
        app(AddServiceCharge::class)->execute($job, ['description' => 'Labour', 'unit_price' => '500'], $this->tech);

        try {
            app(UpdateServicePricing::class)->execute($job, ['discount_amount' => '900'], $this->manager);
            $this->fail('A discount larger than the job was accepted.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('0.00', (string) $job->fresh()->discount_amount);
        $this->assertSame('500.00', (string) $job->fresh()->grand_total);
    }

    public function test_tax_follows_the_shop_setting(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('tax.enabled', true);
        $settings->set('tax.default_rate', '17');
        $settings->set('tax.inclusive', false);

        $job = $this->walk($this->receive(), 'diagnosing');
        app(AddServiceCharge::class)->execute($job, ['description' => 'Labour', 'unit_price' => '1000'], $this->tech);

        $job->refresh();
        $this->assertSame('170.00', (string) $job->tax_amount);
        $this->assertSame('1170.00', (string) $job->grand_total);
        $this->assertBooksAgree();

        $settings->set('tax.inclusive', true);
        $inclusive = $this->walk($this->receive(), 'diagnosing');
        app(AddServiceCharge::class)->execute($inclusive, ['description' => 'Labour', 'unit_price' => '1170'], $this->tech);
        $inclusive->refresh();
        $this->assertTrue($inclusive->tax_inclusive);
        $this->assertSame('1170.00', (string) $inclusive->grand_total);
        $this->assertSame('170.00', (string) $inclusive->tax_amount);
        $this->assertBooksAgree();
    }

    public function test_charges_and_parts_are_refused_on_a_locked_job(): void
    {
        $job = $this->walk($this->receive(), 'diagnosing');
        DB::table('service_jobs')->where('id', $job->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);

        $this->expectException(DomainException::class);
        app(AddServiceCharge::class)->execute($job->fresh(), ['description' => 'Labour', 'unit_price' => '500'], $this->tech);
    }

    // ---------------------------------------------------------------- the integrity check

    public function test_the_integrity_check_names_a_tampered_job(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500']);
        $job = $this->walk($this->receive(), 'diagnosing');
        app(AddServicePart::class)->execute($job, ['product_id' => $toner->id, 'quantity' => '1'], $this->tech);
        $this->assertBooksAgree();

        DB::table('service_jobs')->where('id', $job->id)->update(['grand_total' => '10.00']);
        $this->assertStringContainsString($job->job_no, implode(' ', app(ServiceJobService::class)->findDrift()));
        DB::table('service_jobs')->where('id', $job->id)->update(['grand_total' => '1500.00']);
        $this->assertBooksAgree();

        DB::table('service_jobs')->where('id', $job->id)->update(['status' => 'in_progress']);   // the history says "diagnosing"
        $this->assertStringContainsString('history', implode(' ', app(ServiceJobService::class)->findDrift()));
        DB::table('service_jobs')->where('id', $job->id)->update(['status' => 'diagnosing']);

    }
}
