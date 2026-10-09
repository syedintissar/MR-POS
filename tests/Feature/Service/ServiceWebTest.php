<?php

namespace Tests\Feature\Service;

use App\Actions\Service\AddServiceCharge;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Actions\Service\ReceiveServiceJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The service pages driven the way the browser drives them. */
class ServiceWebTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $cashier;

    private User $tech;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->withRole('Cashier')->create();
        $this->tech = User::factory()->withRole('Technician')->create();
        $this->manager = User::factory()->withRole('Manager')->create();
    }

    private function cash(): int
    {
        return PaymentMethod::query()->where('code', 'cash')->value('id');
    }

    private function job(array $over = [], ?User $tech = null): ServiceJob
    {
        return app(ReceiveServiceJob::class)->execute($over + [
            'customer_id' => Customer::factory()->create(['name' => 'Webb Traders', 'phone' => '0300-5551234', 'allow_credit' => true])->id,
            'service_type_id' => ServiceType::query()->where('name', 'Printer repair')->value('id'), 'device_type' => 'printer', 'model' => 'LaserJet 1020',
            'serial_number' => 'SN-777', 'reported_problem' => 'Paper jam', 'technician_id' => ($tech ?? $this->tech)->id,
        ], $this->cashier);
    }

    private function completed(string $amount = '1000'): ServiceJob
    {
        $job = $this->job();
        foreach (['diagnosing', 'in_progress'] as $to) {
            $job = app(ChangeServiceJobStatus::class)->execute($job, $to, $this->manager);
        }
        app(AddServiceCharge::class)->execute($job, ['description' => 'Repair', 'unit_price' => $amount], $this->manager);

        return app(ChangeServiceJobStatus::class)->execute($job->fresh(), 'completed', $this->manager);
    }

    // ---------------------------------------------------------------- pages

    public function test_the_service_pages_open(): void
    {
        $job = $this->job();
        $this->as('Manager');

        $this->get('/service/jobs')->assertOk()->assertSee($job->job_no)->assertSee('Webb Traders');
        foreach (['open', 'ready', 'overdue', 'delivered', 'cancelled', 'all'] as $view) {
            $this->get("/service/jobs?view={$view}")->assertOk();
        }
        $this->get('/service/jobs?q=SN-777&view=all&technician=none&type=1&from=2020-01-01&to=2099-01-01&status=received')->assertOk();
        $this->get('/service/jobs/create')->assertOk()->assertSee('Receive a device');
        $this->get("/service/jobs/{$job->id}")->assertOk()->assertSee($job->job_no)->assertSee('Paper jam')->assertSee('Parts and charges')->assertSee('History');
        $this->get("/service/jobs/{$job->id}/edit")->assertOk();
        $this->get('/service/types')->assertOk()->assertSee('Toner refill');
        $this->get('/service/types/create')->assertOk();
        $this->get('/service/types/1/edit')->assertOk();
    }

    public function test_a_job_number_typed_or_scanned_opens_the_job(): void
    {
        $job = $this->job();
        $this->as('Cashier')->get('/service/jobs?q='.strtolower($job->job_no))->assertRedirect(route('service.jobs.show', $job));
        $this->get('/service/jobs?q=JOB-999999')->assertOk()->assertSee('No jobs found');
    }

    // ---------------------------------------------------------------- intake

    public function test_a_cashier_receives_a_device_and_gets_the_slip(): void
    {
        $customer = Customer::factory()->create(['name' => 'Intake Imran']);
        $this->as('Cashier');

        $r = $this->post('/service/jobs', [
            'customer_id' => $customer->id, 'service_type_id' => ServiceType::query()->where('name', 'Toner refill')->value('id'), 'device_type' => 'toner',
            'model' => 'HP 12A', 'reported_problem' => 'Faint prints', 'priority' => 'urgent', 'expected_delivery_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $job = ServiceJob::query()->firstOrFail();
        $r->assertRedirect(route('service.jobs.show', $job))->assertSessionHas('success')->assertSessionHas('slip_url');
        $this->assertSame('urgent', $job->priority);
        $this->assertSame($customer->id, $job->customer_id);
        $this->assertSame('JOB-000001', $job->job_no);

        $slip = session('slip_url');
        $this->get($slip)->assertOk()->assertSee('SERVICE JOB SLIP')->assertSee('JOB-000001')->assertSee('Faint prints')->assertDontSee('DUPLICATE');
    }

    public function test_intake_validates_and_keeps_what_was_typed(): void
    {
        $this->as('Cashier');
        $type = ServiceType::query()->value('id');

        $this->from('/service/jobs/create')->post('/service/jobs', ['service_type_id' => $type, 'device_type' => 'printer', 'reported_problem' => ''])->assertSessionHasErrors('reported_problem');
        $this->post('/service/jobs', ['service_type_id' => $type, 'device_type' => 'fridge', 'reported_problem' => 'x'])->assertSessionHasErrors('device_type');
        $this->post('/service/jobs', ['service_type_id' => $type, 'device_type' => 'printer', 'reported_problem' => 'x'])->assertSessionHas('error');   // walk-in without a name and phone
        $this->assertSame(0, ServiceJob::query()->count());

        $this->post('/service/jobs', ['service_type_id' => $type, 'device_type' => 'printer', 'reported_problem' => 'x', 'contact_name' => 'Mr Ali', 'contact_phone' => '0345-1112233'])->assertSessionHas('success');
        $this->assertTrue(ServiceJob::query()->firstOrFail()->customer->is_walk_in);
    }

    public function test_an_advance_can_be_taken_at_intake(): void
    {
        $this->as('Cashier');

        $this->post('/service/jobs', [
            'customer_id' => Customer::factory()->create()->id, 'service_type_id' => ServiceType::query()->value('id'), 'device_type' => 'printer', 'reported_problem' => 'Dead',
            'advance_amount' => '500', 'advance_method' => $this->cash(),
        ])->assertSessionHas('success');

        $job = ServiceJob::query()->firstOrFail();
        $this->assertSame('500.00', (string) $job->paid_amount);
        $this->assertSame(1, CustomerPayment::query()->count());
    }

    public function test_a_technician_cannot_take_money_at_intake_but_the_job_is_received(): void
    {
        $this->as('Technician');

        $this->post('/service/jobs', [
            'customer_id' => Customer::factory()->create()->id, 'service_type_id' => ServiceType::query()->value('id'), 'device_type' => 'printer', 'reported_problem' => 'Dead',
            'advance_amount' => '500', 'advance_method' => $this->cash(),
        ])->assertSessionHas('success')->assertSessionHas('error');

        $this->assertSame(1, ServiceJob::query()->count());
        $this->assertSame(0, CustomerPayment::query()->count());
    }

    // ---------------------------------------------------------------- who sees which job

    public function test_a_technician_sees_only_the_jobs_assigned_to_them(): void
    {
        $other = User::factory()->withRole('Technician')->create();
        $mine = $this->job(['serial_number' => 'MINE-1'], $this->tech);
        $theirs = $this->job(['serial_number' => 'THEIRS-1'], $other);

        $this->actingAs($this->tech);
        $this->get('/service/jobs?view=all')->assertOk()->assertSee($mine->job_no)->assertDontSee($theirs->job_no);
        $this->get("/service/jobs/{$mine->id}")->assertOk();
        $this->get("/service/jobs/{$theirs->id}")->assertForbidden();
        $this->get("/service/jobs/{$theirs->id}/edit")->assertForbidden();
        $this->post("/service/jobs/{$theirs->id}/status", ['to' => 'diagnosing'])->assertForbidden();
        $this->post("/service/jobs/{$theirs->id}/charges", ['description' => 'x', 'unit_price' => '5'])->assertForbidden();
        $this->get('/service/jobs?q='.$theirs->job_no)->assertOk()->assertDontSee($theirs->job_no.'<');   // a scanned number of someone else's job does not open it
        $this->assertSame(ServiceJob::RECEIVED, $theirs->fresh()->status);

        $this->actingAs($this->cashier)->get('/service/jobs?view=all')->assertOk()->assertSee($mine->job_no)->assertSee($theirs->job_no);
    }

    public function test_a_technician_does_not_see_the_money_or_the_cost_on_a_job(): void
    {
        $job = $this->completed('1000');
        $this->actingAs($this->cashier)->post("/service/jobs/{$job->id}/advance", ['payment_method_id' => $this->cash(), 'amount' => '300'])->assertRedirect();

        $this->actingAs($this->tech)->get("/service/jobs/{$job->id}")->assertOk()->assertSee('Repair')->assertDontSee('Money')->assertDontSee('Take an advance')->assertDontSee('Deliver and invoice')->assertDontSee('Cancel this job')->assertDontSee('Parts cost');
        $this->actingAs($this->cashier)->get("/service/jobs/{$job->id}")->assertOk()->assertSee('Money')->assertSee('Take an advance')->assertSee('Deliver and invoice')->assertDontSee('Parts cost')->assertDontSee('Cancel this job');
        $this->actingAs($this->manager)->get("/service/jobs/{$job->id}")->assertOk()->assertSee('Parts cost (internal)')->assertDontSee('Cancel this job', false);   // a completed job is reopened before it can be cancelled

        $open = $this->job();
        $this->actingAs($this->manager)->get("/service/jobs/{$open->id}")->assertOk()->assertSee('Cancel this job');
        $this->actingAs($this->cashier)->get("/service/jobs/{$open->id}")->assertOk()->assertDontSee('Cancel this job');
    }

    // ---------------------------------------------------------------- working on a job

    public function test_the_status_moves_through_the_page_and_a_wrong_move_is_explained(): void
    {
        $job = $this->job();
        $this->as('Manager');

        $this->post("/service/jobs/{$job->id}/status", ['to' => 'diagnosing', 'note' => 'Looking at it now'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('diagnosing', $job->fresh()->status);
        $this->post("/service/jobs/{$job->id}/status", ['to' => 'completed'])->assertSessionHas('error');
        $this->assertSame('diagnosing', $job->fresh()->status);
        $this->post("/service/jobs/{$job->id}/status", ['to' => 'delivered'])->assertSessionHas('error');
        $this->post("/service/jobs/{$job->id}/status", ['to' => 'nonsense'])->assertSessionHasErrors('to');

        $this->post("/service/jobs/{$job->id}/status", ['to' => 'waiting_for_approval']);
        $this->post("/service/jobs/{$job->id}/status", ['to' => 'approved'])->assertSessionHas('error');
        $this->post("/service/jobs/{$job->id}/status", ['to' => 'approved', 'approval_method' => 'phone'])->assertSessionHas('success');
        $this->assertSame('phone', $job->fresh()->approval_method);
    }

    public function test_parts_charges_and_pricing_through_the_pages(): void
    {
        $toner = $this->stocked('5', '600', ['name' => 'Toner 12A', 'sku' => 'T-12A', 'sale_price' => '1500']);
        $job = $this->job();
        $this->as('Manager');
        $this->post("/service/jobs/{$job->id}/status", ['to' => 'diagnosing']);

        $this->post("/service/jobs/{$job->id}/parts", ['product_id' => $toner->id, 'quantity' => '2'])->assertSessionHas('success');
        $this->assertSame('3.000', $toner->fresh()->current_stock);
        $this->post("/service/jobs/{$job->id}/parts", ['product_id' => $toner->id, 'quantity' => '9'])->assertSessionHas('error');
        $this->assertSame('3.000', $toner->fresh()->current_stock);
        $this->post("/service/jobs/{$job->id}/parts", ['product_id' => $toner->id, 'quantity' => '0'])->assertSessionHasErrors('quantity');

        $this->post("/service/jobs/{$job->id}/charges", ['description' => 'Labour', 'quantity' => '1', 'unit_price' => '500'])->assertSessionHas('success');
        $this->post("/service/jobs/{$job->id}/pricing", ['estimate_amount' => '3500', 'discount_amount' => '100'])->assertSessionHas('success');
        $job->refresh();
        $this->assertSame('3400.00', (string) $job->grand_total);
        $this->assertSame('3500.00', (string) $job->estimate_amount);

        $this->get("/service/jobs/{$job->id}")->assertOk()->assertSee('Toner 12A')->assertSee('Labour')->assertSee('3,400.00');

        $part = $job->items()->where('line_type', 'part')->firstOrFail();
        $this->post("/service/jobs/{$job->id}/lines/{$part->id}/remove", ['disposition' => 'returned_to_stock'])->assertSessionHas('success');
        $this->assertSame('5.000', $toner->fresh()->current_stock);
        $this->post("/service/jobs/{$job->id}/lines/{$part->id}/remove", ['disposition' => 'returned_to_stock'])->assertSessionHas('error');
        $this->post('/service/jobs/'.($job->id + 99)."/lines/{$part->id}/remove", [])->assertNotFound();
    }

    public function test_a_cashier_cannot_change_parts_or_charges(): void
    {
        $job = $this->job();
        $this->as('Cashier');

        $this->post("/service/jobs/{$job->id}/parts", ['product_id' => 1, 'quantity' => '1'])->assertForbidden();
        $this->post("/service/jobs/{$job->id}/charges", ['description' => 'x', 'unit_price' => '1'])->assertForbidden();
        $this->post("/service/jobs/{$job->id}/pricing", ['estimate_amount' => '1'])->assertForbidden();
    }

    public function test_the_card_can_be_edited_and_the_technician_changed(): void
    {
        $other = User::factory()->withRole('Technician')->create();
        $job = $this->job();
        $this->as('Cashier');

        $this->put("/service/jobs/{$job->id}", ['service_type_id' => $job->service_type_id, 'device_type' => 'printer', 'reported_problem' => 'Paper jam and noise', 'model' => 'LaserJet 1022'])->assertRedirect(route('service.jobs.show', $job));
        $this->assertSame('LaserJet 1022', $job->fresh()->model);
        $this->put("/service/jobs/{$job->id}", ['service_type_id' => $job->service_type_id, 'device_type' => 'printer', 'reported_problem' => ''])->assertSessionHasErrors('reported_problem');
        $this->put("/service/jobs/{$job->id}", ['service_type_id' => $job->service_type_id, 'device_type' => 'printer', 'reported_problem' => 'x', 'diagnosis' => 'Cashier guess'])->assertForbidden();   // a crafted request: the form does not offer the field

        $this->post("/service/jobs/{$job->id}/assign", ['technician_id' => $other->id])->assertSessionHas('success');
        $this->assertSame($other->id, $job->fresh()->technician_id);
        $this->post("/service/jobs/{$job->id}/assign", ['technician_id' => $this->cashier->id])->assertSessionHas('success');   // a cashier holds view_assigned too
    }

    // ---------------------------------------------------------------- money

    public function test_an_advance_delivery_and_cancellation_through_the_pages(): void
    {
        $job = $this->completed('1000');
        $this->as('Cashier');

        $this->post("/service/jobs/{$job->id}/advance", ['payment_method_id' => $this->cash(), 'amount' => '300'])->assertRedirect()->assertSessionHas('success')->assertSessionHas('receipt_url');
        $this->get(session('receipt_url'))->assertOk()->assertSee('RCP-000001')->assertSee('300.00');
        $this->post("/service/jobs/{$job->id}/advance", ['payment_method_id' => $this->cash(), 'amount' => '9999'])->assertSessionHas('error');
        $this->post("/service/jobs/{$job->id}/advance", ['payment_method_id' => $this->cash(), 'amount' => '1.234'])->assertSessionHasErrors('amount');

        $r = $this->post("/service/jobs/{$job->id}/deliver", ['payments' => [['payment_method_id' => $this->cash(), 'amount' => '1000']]])->assertRedirect(route('service.jobs.show', $job));
        $r->assertSessionHas('success')->assertSessionHas('invoice_url')->assertSessionHas('change');
        $this->assertSame('300.00', session('change'));   // 1000 handed over for the 700 still due

        $job->refresh();
        $this->assertSame('delivered', $job->status);
        $this->assertSame('SRV-000001', $job->invoice_no);
        $this->assertSame('0.00', (string) $job->due_amount);

        $this->get(session('invoice_url'))->assertOk()->assertSee('SERVICE INVOICE')->assertSee('SRV-000001')->assertSee('1,000.00');
        $this->post("/service/jobs/{$job->id}/deliver", [])->assertSessionHas('error');   // already delivered
        $this->get("/service/jobs/{$job->id}")->assertOk()->assertSee('SRV-000001');
    }

    public function test_delivery_refused_for_the_walk_in_customer_keeps_what_was_typed(): void
    {
        $job = app(ReceiveServiceJob::class)->execute([
            'customer_id' => null, 'contact_name' => 'Mr Ali', 'contact_phone' => '0300-1', 'service_type_id' => ServiceType::query()->value('id'), 'device_type' => 'printer',
            'reported_problem' => 'Dead', 'technician_id' => $this->tech->id,
        ], $this->cashier);
        foreach (['diagnosing', 'in_progress'] as $to) {
            $job = app(ChangeServiceJobStatus::class)->execute($job, $to, $this->manager);
        }
        app(AddServiceCharge::class)->execute($job, ['description' => 'Repair', 'unit_price' => '800'], $this->manager);
        app(ChangeServiceJobStatus::class)->execute($job->fresh(), 'completed', $this->manager);

        $this->as('Cashier')->from("/service/jobs/{$job->id}")->post("/service/jobs/{$job->id}/deliver", ['payments' => [['payment_method_id' => $this->cash(), 'amount' => '300']]])
            ->assertRedirect("/service/jobs/{$job->id}")->assertSessionHas('error');
        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_only_a_manager_cancels_a_job_and_the_advance_follows_the_choice(): void
    {
        $job = $this->job();
        $this->as('Manager');
        $this->post("/service/jobs/{$job->id}/status", ['to' => 'diagnosing']);
        $this->post("/service/jobs/{$job->id}/advance", ['payment_method_id' => $this->cash(), 'amount' => '400'])->assertSessionHas('success');

        $this->actingAs($this->cashier)->post("/service/jobs/{$job->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->manager)->post("/service/jobs/{$job->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/service/jobs/{$job->id}/cancel", ['reason' => 'Declined the estimate', 'advance' => 'refund', 'refund_method_id' => $this->cash()])->assertRedirect(route('service.jobs.show', $job))->assertSessionHas('success');

        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertSame(1, CustomerPayment::query()->where('type', 'refund')->count());
        $this->get("/service/jobs/{$job->id}")->assertOk()->assertSee('Declined the estimate');
    }

    // ---------------------------------------------------------------- prints

    public function test_the_slip_and_invoice_open_only_through_signed_links_and_reprints_are_marked(): void
    {
        $job = $this->completed('500');
        $this->actingAs($this->cashier)->post("/service/jobs/{$job->id}/deliver", ['payments' => [['payment_method_id' => $this->cash(), 'amount' => '500']]]);

        foreach (['slip', 'invoice'] as $doc) {
            foreach (['thermal', 'a4'] as $format) {
                $this->get("/service/jobs/{$job->id}/{$doc}/{$format}")->assertForbidden();
                $signed = URL::temporarySignedRoute("service.jobs.{$doc}", now()->addMinutes(10), ['job' => $job->id, 'format' => $format]);
                $this->get($signed)->assertOk()->assertDontSee('DUPLICATE');

                $this->get("/service/jobs/{$job->id}/{$doc}/{$format}/reprint")->assertOk()->assertSee('DUPLICATE');
            }
        }
        $this->assertSame(1, AuditLog::query()->where('event', 'service_slip_reprinted')->count() > 0 ? 1 : 0);
        $this->assertGreaterThanOrEqual(4, AuditLog::query()->whereIn('event', ['service_slip_reprinted', 'service_invoice_reprinted'])->count());

        $this->get("/service/jobs/{$job->id}/slip/legal")->assertForbidden();   // unsigned: refused before the format is looked at
        $this->get(URL::temporarySignedRoute('service.jobs.slip', now()->addMinutes(10), ['job' => $job->id, 'format' => 'legal']))->assertNotFound();
        $this->get(URL::temporarySignedRoute('service.jobs.slip', now()->subMinute(), ['job' => $job->id, 'format' => 'thermal']))->assertForbidden();   // expired
    }

    public function test_the_invoice_pdf_is_a_real_pdf_and_only_for_delivered_jobs(): void
    {
        $job = $this->completed('500');
        $this->as('Manager')->get("/service/jobs/{$job->id}/invoice.pdf")->assertNotFound();
        $this->post("/service/jobs/{$job->id}/deliver", ['payments' => [['payment_method_id' => $this->cash(), 'amount' => '500']]]);

        $pdf = $this->get("/service/jobs/{$job->id}/invoice.pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(1, AuditLog::query()->where('event', 'service_invoice_pdf')->count());
        $this->get("/service/jobs/{$job->id}/invoice/thermal/reprint")->assertOk();

        $undelivered = $this->job();
        $this->get("/service/jobs/{$undelivered->id}/invoice/a4/reprint")->assertNotFound();
        $this->asWithOnly(['service_jobs.view_all'])->get("/service/jobs/{$job->id}/invoice.pdf")->assertForbidden();   // needs sales.reprint
    }

    // ---------------------------------------------------------------- pickers and types

    public function test_the_part_picker_returns_no_cost_and_needs_the_parts_permission(): void
    {
        $this->stocked('5', '777.77', ['name' => 'Secret Toner', 'sku' => 'SEC-1', 'sale_price' => '1500']);
        Product::factory()->service()->create(['name' => 'Secret Service', 'sale_price' => '100']);

        $rows = $this->as('Technician')->getJson('/service/products/search?q=Secret')->assertOk()->json();
        $this->assertCount(1, $rows, 'service items are not parts');
        $this->assertSame('Secret Toner', $rows[0]['name']);
        $this->assertSame(['id', 'name', 'sku', 'unit', 'stock', 'price', 'wholesale_price'], array_keys($rows[0]));
        $this->assertStringNotContainsString('777', json_encode($rows));

        $this->getJson('/service/products/search')->assertStatus(422);
        $this->as('Cashier')->getJson('/service/products/search?q=Secret')->assertForbidden();
    }

    public function test_the_customer_picker_shows_names_and_phones_only(): void
    {
        Customer::factory()->create(['name' => 'Picker Pervez', 'phone' => '0300-9990000', 'balance' => '123456.00']);
        $rows = $this->as('Cashier')->getJson('/service/customers/search?q=Pervez')->assertOk()->json();

        $this->assertSame(['id', 'name', 'phone', 'code'], array_keys($rows[0]));
        $this->assertStringNotContainsString('123456', json_encode($rows));
        $this->asWithOnly(['service_jobs.view_all'])->getJson('/service/customers/search?q=Pervez')->assertForbidden();
    }

    public function test_service_types_are_managed_by_managers(): void
    {
        $this->as('Cashier')->get('/service/types')->assertForbidden();
        $this->post('/service/types', ['name' => 'X'])->assertForbidden();

        $this->as('Manager');
        $this->post('/service/types', ['name' => 'Scanner repair', 'default_charge' => '1500'])->assertRedirect(route('service.types.index'));
        $type = ServiceType::query()->where('name', 'Scanner repair')->firstOrFail();
        $this->assertSame('1500.00', (string) $type->default_charge);

        $this->post('/service/types', ['name' => 'Scanner repair'])->assertSessionHasErrors('name');
        $this->post('/service/types', ['name' => 'Bad', 'default_charge' => '-1'])->assertSessionHasErrors('default_charge');

        $this->put("/service/types/{$type->id}", ['name' => 'Scanner service', 'default_charge' => '', 'sort_order' => 5])->assertRedirect();
        $this->assertSame('Scanner service', $type->fresh()->name);
        $this->assertNull($type->fresh()->default_charge);

        $this->post("/service/types/{$type->id}/toggle")->assertRedirect();
        $this->assertFalse($type->fresh()->is_active);
        $this->actingAs($this->cashier)->get('/service/jobs/create')->assertOk()->assertDontSee('Scanner service');   // a switched-off type is not offered
    }

    // ---------------------------------------------------------------- elsewhere in the program

    public function test_the_dashboard_and_the_customer_page_show_the_jobs(): void
    {
        $customer = Customer::factory()->create(['name' => 'Dash Danish', 'allow_credit' => true]);
        $job = $this->job(['customer_id' => $customer->id]);

        $this->as('Manager')->get('/')->assertOk()->assertSee('Service jobs')->assertSee('1')->assertSee('ready to collect');
        $this->get("/customers/{$customer->id}")->assertOk()->assertSee($job->job_no)->assertSee('Service jobs');
        $this->actingAs($this->tech)->get('/')->assertOk()->assertSee('My service jobs');
        $this->asWithOnly(['dashboard.view'])->get('/')->assertOk()->assertDontSee('ready to collect');
    }

    public function test_a_job_left_on_account_appears_among_the_unpaid_invoices_and_can_be_paid_from_the_customer_page(): void
    {
        $customer = Customer::factory()->create(['name' => 'Ledger Latif', 'allow_credit' => true]);
        $job = $this->job(['customer_id' => $customer->id]);
        foreach (['diagnosing', 'in_progress'] as $to) {
            $job = app(ChangeServiceJobStatus::class)->execute($job, $to, $this->manager);
        }
        app(AddServiceCharge::class)->execute($job, ['description' => 'Repair', 'unit_price' => '900'], $this->manager);
        app(ChangeServiceJobStatus::class)->execute($job->fresh(), 'completed', $this->manager);
        $this->actingAs($this->cashier)->post("/service/jobs/{$job->id}/deliver", ['payments' => [['payment_method_id' => $this->cash(), 'amount' => '200']]])->assertSessionHas('success');

        $this->get("/customers/{$customer->id}")->assertOk()->assertSee('Invoices still to pay')->assertSee('SRV-000001')->assertSee('700.00');
        $this->get("/customer-payments/create?customer={$customer->id}&job={$job->id}")->assertOk()->assertSee('SRV-000001');
        $this->post('/customer-payments', ['customer_id' => $customer->id, 'payment_method_id' => $this->cash(), 'amount' => '700', 'allocation' => 'manual', 'allocations' => ['j'.$job->id => '700']])->assertSessionHas('success');
        $this->assertSame('paid', $job->fresh()->payment_status);

        $this->actingAs($this->manager)->get('/receivables')->assertOk()->assertDontSee('SRV-000001');
    }
}
