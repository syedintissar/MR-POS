<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\VoidSale;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\TotalMismatchException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DocumentSequence;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Sales\PosApprovalService;
use App\Services\Sales\SaleAccountService;
use App\Services\SettingsService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class CompleteSaleTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->withRole('Cashier')->create();
        $this->manager = User::factory()->withRole('Manager')->create();
    }

    private function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    /** A product that sells for $price and holds $qty units bought at $cost. */
    private function item(string $price = '150', string $qty = '10', string $cost = '100', array $attrs = []): Product
    {
        return $this->stocked($qty, $cost, ['sale_price' => $price] + $attrs);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function sell(array $lines, array $over = [], ?User $by = null): Sale
    {
        $payload = $over + [
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $lines,
            'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '100000']],   // plenty of cash: the change is given back
        ];

        return app(CompleteSale::class)->execute($payload, $by ?? $this->cashier);
    }

    private function line(Product $p, string $qty = '1', array $extra = []): array
    {
        return ['product_id' => $p->id, 'quantity' => $qty] + $extra;
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(SaleAccountService::class)->findDrift());
        $this->assertSame([], $this->inventory()->findDrift());
    }

    // ---------------------------------------------------------------- the basics

    public function test_a_sale_numbers_the_invoice_takes_the_stock_out_and_snapshots_the_cost(): void
    {
        $p = $this->item('150', '10', '100');

        $sale = $this->sell([$this->line($p, '2')], ['payments' => [['payment_method_id' => $this->method()->id, 'amount' => '300']]]);

        $this->assertSame('INV-000001', $sale->invoice_no);
        $this->assertSame('completed', $sale->status);
        $this->assertSame($this->cashier->id, $sale->user_id);
        $this->assertTrue($sale->customer->is_walk_in);
        $this->assertSame('300.00', $sale->grand_total);
        $this->assertSame('300.00', $sale->items_total);
        $this->assertSame('8.000', $p->fresh()->current_stock);

        $item = $sale->items->sole();
        $this->assertSame('150.00', $item->unit_price);
        $this->assertSame('100.0000', $item->unit_cost, 'The average cost at the moment of sale is kept for profit.');
        $this->assertSame($p->name, $item->product_name);
        $this->assertSame($p->sku, $item->sku);

        $movement = StockMovement::query()->where('source_type', 'sale')->sole();
        $this->assertSame('sale', $movement->movement_type);
        $this->assertSame('-2.000', $movement->quantity);
        $this->assertSame($item->id, $movement->source_line_id);
        $this->assertSame($this->cashier->id, $movement->user_id);

        $this->assertSame('100.0000', $p->fresh()->average_cost, 'A sale does not change the average cost.');
        $this->assertBooksAgree();
    }

    public function test_the_payment_is_recorded_as_a_receipt_that_settles_the_sale(): void
    {
        $sale = $this->sell([$this->line($this->item('150'), '2')], ['payments' => [['payment_method_id' => $this->method()->id, 'amount' => '300']]]);

        $payment = CustomerPayment::query()->sole();
        $this->assertSame('RCP-000001', $payment->receipt_no);
        $this->assertSame('receipt', $payment->type);
        $this->assertSame('300.00', $payment->amount);
        $this->assertSame('posted', $payment->status);
        $this->assertSame($sale->customer_id, $payment->customer_id);
        $this->assertSame($sale->id, SalePayment::query()->sole()->sale_id);

        $this->assertSame('300.00', $sale->paid_amount);
        $this->assertSame('0.00', $sale->due_amount);
        $this->assertSame('paid', $sale->payment_status);
    }

    public function test_cash_handed_over_in_excess_becomes_change(): void
    {
        $sale = $this->sell([$this->line($this->item('150'), '2')], ['payments' => [['payment_method_id' => $this->method()->id, 'amount' => '500']]]);

        $this->assertSame('300.00', CustomerPayment::query()->sole()->amount, 'Only what the sale needs is recorded as received.');
        $this->assertSame('500.00', $sale->amount_tendered);
        $this->assertSame('200.00', $sale->change_amount);
        $this->assertSame('300.00', $sale->paid_amount);
    }

    public function test_a_split_payment_makes_one_receipt_per_method(): void
    {
        $sale = $this->sell([$this->line($this->item('150'), '2')], ['payments' => [
            ['payment_method_id' => $this->method()->id, 'amount' => '100'],
            ['payment_method_id' => $this->method('card')->id, 'amount' => '200'],
        ]]);

        $this->assertSame(['100.00', '200.00'], CustomerPayment::query()->orderBy('id')->pluck('amount')->all());
        $this->assertSame(['RCP-000001', 'RCP-000002'], CustomerPayment::query()->orderBy('id')->pluck('receipt_no')->all());
        $this->assertSame('300.00', $sale->paid_amount);
        $this->assertSame('100.00', $sale->amount_tendered, 'Only the cash part was handed over.');
        $this->assertSame('0.00', $sale->change_amount);
    }

    public function test_change_is_taken_from_the_cash_part_of_a_split_payment(): void
    {
        $sale = $this->sell([$this->line($this->item('150'), '2')], ['payments' => [
            ['payment_method_id' => $this->method('card')->id, 'amount' => '200'],
            ['payment_method_id' => $this->method()->id, 'amount' => '150'],
        ]]);

        $this->assertSame(['200.00', '100.00'], CustomerPayment::query()->orderBy('id')->pluck('amount')->all());
        $this->assertSame('50.00', $sale->change_amount);
    }

    public function test_the_walk_in_customer_must_pay_in_full_and_nothing_is_saved_otherwise(): void
    {
        $p = $this->item('150');
        $nextNumber = DocumentSequence::query()->where('doc_type', 'sale')->value('next_number');

        try {
            $this->sell([$this->line($p, '2')], ['payments' => [['payment_method_id' => $this->method()->id, 'amount' => '299.99']]]);
            $this->fail('A part payment was accepted for the walk-in customer.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('pay in full', $e->getMessage());
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10.000', $p->fresh()->current_stock);
        $this->assertSame($nextNumber, DocumentSequence::query()->where('doc_type', 'sale')->value('next_number'), 'The invoice number was handed back.');
    }

    public function test_a_sale_without_any_payment_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->sell([$this->line($this->item(), '1')], ['payments' => []]);
    }

    public function test_only_cash_may_be_handed_over_in_excess(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only cash');
        $this->sell([$this->line($this->item('150'))], ['payments' => [['payment_method_id' => $this->method('card')->id, 'amount' => '200']]]);
    }

    public function test_a_payment_line_that_is_not_needed_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->sell([$this->line($this->item('150'))], ['payments' => [
            ['payment_method_id' => $this->method('card')->id, 'amount' => '150'],
            ['payment_method_id' => $this->method()->id, 'amount' => '50'],   // all of this would come back as change
        ]]);
    }

    public function test_payment_method_rules_are_enforced(): void
    {
        $p = $this->item('150');
        $cases = [
            'needs a reference' => [['payment_method_id' => $this->method('easypaisa')->id, 'amount' => '150']],
            'customer credit cannot be used yet' => [['payment_method_id' => $this->method('customer_credit')->id, 'amount' => '150']],
            'unknown method' => [['payment_method_id' => 999999, 'amount' => '150']],
        ];
        foreach ($cases as $label => $payments) {
            try {
                $this->sell([$this->line($p)], ['payments' => $payments]);
                $this->fail("Accepted: {$label}");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->method('card')->update(['is_active' => false]);
        try {
            $this->sell([$this->line($p)], ['payments' => [['payment_method_id' => $this->method('card')->id, 'amount' => '150']]]);
            $this->fail('A switched-off method was accepted.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $ok = $this->sell([$this->line($p)], ['payments' => [['payment_method_id' => $this->method('easypaisa')->id, 'amount' => '150', 'reference_no' => 'EP-9']]]);
        $this->assertSame('EP-9', CustomerPayment::query()->where('notes', "Sale {$ok->invoice_no}")->sole()->reference_no);
        $this->assertSame(1, Sale::query()->count(), 'Only the valid sale was saved.');
    }

    // ---------------------------------------------------------------- stock

    public function test_not_enough_stock_refuses_the_whole_sale_and_changes_nothing(): void
    {
        $plenty = $this->item('150', '10');
        $scarce = $this->item('50', '1');
        $nextNumber = DocumentSequence::query()->where('doc_type', 'sale')->value('next_number');
        $movements = StockMovement::query()->count();

        try {
            $this->sell([$this->line($plenty, '3'), $this->line($scarce, '2')]);
            $this->fail('Sold more than was in stock.');
        } catch (InsufficientStockException $e) {
            $this->assertSame($scarce->name, $e->productName);
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10.000', $plenty->fresh()->current_stock, 'Nothing left the shelf, not even the first line.');
        $this->assertSame('1.000', $scarce->fresh()->current_stock);
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame(0, CustomerPayment::query()->count());
        $this->assertSame($nextNumber, DocumentSequence::query()->where('doc_type', 'sale')->value('next_number'));
    }

    public function test_selling_below_zero_is_possible_only_when_the_owner_allows_it(): void
    {
        $p = $this->item('150', '1');
        app(SettingsService::class)->set('inventory.allow_negative_stock', true);

        $this->sell([$this->line($p, '3')]);

        $this->assertSame('-2.000', $p->fresh()->current_stock);
        $this->assertBooksAgree();
    }

    public function test_service_items_are_sold_without_touching_stock(): void
    {
        $service = Product::factory()->service()->create(['sale_price' => '500']);

        $sale = $this->sell([$this->line($service, '1')]);

        $this->assertSame('500.00', $sale->grand_total);
        $this->assertSame(0, StockMovement::query()->where('source_type', 'sale')->count());
        $this->assertSame('0.0000', $sale->items->sole()->unit_cost);
        $this->assertBooksAgree();
    }

    public function test_whole_unit_products_refuse_fractions_and_unavailable_products_are_refused(): void
    {
        $p = $this->item();
        $inactive = $this->item('100', '5', '50', ['is_active' => false]);
        $deleted = $this->item('100', '5', '50');
        $deleted->delete();

        foreach ([[$this->line($p, '1.5')], [$this->line($p, '0')], [$this->line($inactive)], [$this->line($deleted)], [['product_id' => 999999, 'quantity' => '1']]] as $i => $lines) {
            try {
                $this->sell($lines);
                $this->fail("Case {$i} was accepted.");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_the_cost_snapshot_follows_the_average_cost_at_the_moment_of_sale(): void
    {
        $p = $this->item('300', '10', '100');
        $this->inventory()->move($p, '10', 'purchase', ['unit_cost' => '200']);   // average is now 150

        $sale = $this->sell([$this->line($p, '1')]);

        $this->assertSame('150.0000', $sale->items->sole()->unit_cost);
    }

    // ---------------------------------------------------------------- prices, discounts, tax

    public function test_the_server_uses_the_price_list_and_ignores_the_browsers_idea_of_the_total(): void
    {
        $p = $this->item('150');

        $this->expectException(TotalMismatchException::class);
        $this->sell([$this->line($p, '2')], ['expected_total' => '250.00']);
    }

    public function test_a_total_mismatch_changes_nothing_and_reports_the_servers_figure(): void
    {
        $p = $this->item('150');

        try {
            $this->sell([$this->line($p, '2')], ['expected_total' => '250.00']);
            $this->fail('Mismatch was accepted.');
        } catch (TotalMismatchException $e) {
            $this->assertSame('300.00', $e->serverTotal);
        }
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10.000', $p->fresh()->current_stock);

        $this->assertSame('300.00', $this->sell([$this->line($p, '2')], ['expected_total' => '300.00'])->grand_total);
    }

    public function test_a_cashier_cannot_change_a_price_without_approval(): void
    {
        $p = $this->item('150');

        try {
            $this->sell([$this->line($p, '1', ['unit_price' => '120'])]);
            $this->fail('A price change went through without approval.');
        } catch (ApprovalRequiredException $e) {
            $this->assertSame(['price_change'], array_column($e->reasons, 'code'));
            $this->assertSame(['sales.change_price'], $e->permissions());
        }
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10.000', $p->fresh()->current_stock);
    }

    public function test_a_manager_can_change_a_price_without_asking_anyone(): void
    {
        $p = $this->item('150');

        $sale = $this->sell([$this->line($p, '1', ['unit_price' => '120'])], by: $this->manager);

        $this->assertSame('120.00', $sale->grand_total);
        $item = $sale->items->sole();
        $this->assertSame('150.00', $item->list_price, 'The list price is kept so overrides can be reported.');
        $this->assertSame('120.00', $item->unit_price);
        $this->assertNull($sale->approved_by);
    }

    public function test_a_managers_approval_lets_a_cashiers_price_change_through_once(): void
    {
        $p = $this->item('150');
        $grant = app(PosApprovalService::class)->grant($this->cashier, $this->manager->username, 'password');
        $payload = ['approval_token' => $grant['token']];

        $sale = $this->sell([$this->line($p, '1', ['unit_price' => '120'])], $payload);

        $this->assertSame($this->manager->id, $sale->approved_by);
        $this->assertSame('120.00', $sale->grand_total);
        $audit = AuditLog::query()->where('event', 'sale_completed')->latest('id')->first();
        $this->assertSame($this->manager->username, $audit->new_values['approved_by'], 'The audit trail names the manager who approved.');

        // the approval is used up
        $this->expectException(ApprovalRequiredException::class);
        $this->sell([$this->line($p, '1', ['unit_price' => '110'])], $payload);
    }

    public function test_an_approval_cannot_be_borrowed_by_another_cashier(): void
    {
        $p = $this->item('150');
        $grant = app(PosApprovalService::class)->grant($this->cashier, $this->manager->username, 'password');
        $other = User::factory()->withRole('Cashier')->create();

        $this->expectException(ApprovalRequiredException::class);
        $this->sell([$this->line($p, '1', ['unit_price' => '120'])], ['approval_token' => $grant['token']], $other);
    }

    public function test_an_approval_that_does_not_cover_everything_is_not_enough(): void
    {
        // a price below the lowest allowed price needs an administrator; a manager's approval does not cover it
        $p = $this->item('150', '10', '100', ['min_sale_price' => '140']);
        $grant = app(PosApprovalService::class)->grant($this->cashier, $this->manager->username, 'password');

        try {
            $this->sell([$this->line($p, '1', ['unit_price' => '100'])], ['approval_token' => $grant['token']]);
            $this->fail('A manager approved a sale below the minimum price.');
        } catch (ApprovalRequiredException $e) {
            $this->assertContains('sales.below_min_price', $e->permissions());
        }

        $admin = User::factory()->withRole('Admin')->create(['username' => 'boss']);
        $grant = app(PosApprovalService::class)->grant($this->cashier, 'boss', 'password');
        $sale = $this->sell([$this->line($p, '1', ['unit_price' => '100'])], ['approval_token' => $grant['token']]);
        $this->assertSame($admin->id, $sale->approved_by);
    }

    public function test_approval_needs_a_real_approver_and_is_throttled(): void
    {
        $approvals = app(PosApprovalService::class);

        foreach ([[$this->manager->username, 'wrong-password'], ['nobody', 'password'], [$this->cashier->username, 'password']] as [$user, $pw]) {
            try {
                $approvals->grant($this->cashier, $user, $pw);
                $this->fail("Approval granted to {$user}.");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(3, AuditLog::query()->where('event', 'pos_approval_failed')->count());

        for ($i = 0; $i < 2; $i++) {
            try {
                $approvals->grant($this->cashier, 'nobody', 'x');
            } catch (DomainException) {
            }
        }
        $this->expectExceptionMessage('Too many');
        $approvals->grant($this->cashier, $this->manager->username, 'password');   // even the right password is refused while throttled
    }

    public function test_discounts_within_the_cashiers_limit_need_no_approval(): void
    {
        $p = $this->item('1000');

        $sale = $this->sell([$this->line($p, '1', ['discount_percent' => '5'])]);

        $this->assertSame('950.00', $sale->grand_total);
        $this->assertSame('50.00', $sale->line_discount_total);
    }

    public function test_a_bigger_discount_needs_a_manager_but_a_manager_may_give_it(): void
    {
        $p = $this->item('1000');

        try {
            $this->sell([$this->line($p, '1', ['discount_percent' => '5.01'])]);
            $this->fail('A discount above the limit went through.');
        } catch (ApprovalRequiredException $e) {
            $this->assertSame(['discount_limit'], array_column($e->reasons, 'code'));
        }
        try {
            $this->sell([$this->line($p)], ['discount_amount' => '51']);   // an invoice discount counts the same way
            $this->fail('An invoice discount above the limit went through.');
        } catch (ApprovalRequiredException) {
            $this->addToAssertionCount(1);
        }

        $sale = $this->sell([$this->line($p, '1', ['discount_percent' => '20'])], by: $this->manager);
        $this->assertSame('800.00', $sale->grand_total);
    }

    public function test_someone_without_the_discount_permission_needs_approval_even_for_one_rupee(): void
    {
        $p = $this->item('1000');
        $this->asWithOnly(['pos.access', 'sales.create']);
        $limited = auth()->user();

        $this->expectException(ApprovalRequiredException::class);
        $this->sell([$this->line($p)], ['discount_amount' => '1'], $limited);
    }

    public function test_the_invoice_discount_is_shared_over_the_lines_and_saved_per_line(): void
    {
        $a = $this->item('600');
        $b = $this->item('300');

        $sale = $this->sell([$this->line($a), $this->line($b)], ['discount_amount' => '45'], $this->manager);   // 5% of 900

        $this->assertSame(['30.00', '15.00'], $sale->items->pluck('invoice_discount_share')->all());
        $this->assertSame('855.00', $sale->grand_total);
        $this->assertSame('45.00', $sale->invoice_discount_amount);
        $this->assertBooksAgree();
    }

    public function test_wholesale_prices_need_approval_for_a_cashier(): void
    {
        $p = $this->item('150', '10', '100', ['wholesale_price' => '130']);

        try {
            $this->sell([$this->line($p)], ['price_tier' => 'wholesale']);
            $this->fail('A cashier used the wholesale list on their own.');
        } catch (ApprovalRequiredException $e) {
            $this->assertSame(['wholesale'], array_column($e->reasons, 'code'));
        }

        $sale = $this->sell([$this->line($p)], ['price_tier' => 'wholesale'], $this->manager);
        $this->assertSame('130.00', $sale->grand_total);
        $this->assertSame('wholesale', $sale->price_tier);
    }

    public function test_a_product_without_a_wholesale_price_sells_at_its_normal_price_on_the_wholesale_list(): void
    {
        $p = $this->item('150');

        $this->assertSame('150.00', $this->sell([$this->line($p)], ['price_tier' => 'wholesale'], $this->manager)->grand_total);
    }

    public function test_a_price_below_the_minimum_needs_an_administrator(): void
    {
        $p = $this->item('150', '10', '100', ['min_sale_price' => '140']);

        foreach ([$this->cashier, $this->manager] as $user) {
            try {
                $this->sell([$this->line($p, '1', ['unit_price' => '139.99'])], by: $user);
                $this->fail('Sold below the minimum price.');
            } catch (ApprovalRequiredException $e) {
                $this->assertContains('sales.below_min_price', $e->permissions());
            }
        }

        $this->assertSame('140.00', $this->sell([$this->line($p, '1', ['unit_price' => '140'])], by: $this->manager)->grand_total);
        $admin = User::factory()->withRole('Admin')->create();
        $this->assertSame('100.00', $this->sell([$this->line($p, '1', ['unit_price' => '100'])], by: $admin)->grand_total);
    }

    public function test_a_discount_that_takes_the_price_below_the_minimum_is_caught_too(): void
    {
        $p = $this->item('150', '10', '100', ['min_sale_price' => '145']);

        $this->expectException(ApprovalRequiredException::class);
        $this->sell([$this->line($p, '1', ['discount_percent' => '4'])]);   // within the cashier's 5%, but 144 is under 145
    }

    public function test_tax_is_added_only_when_the_owner_turns_it_on(): void
    {
        $p = $this->item('1000');
        $this->assertSame('0.00', $this->sell([$this->line($p)])->tax_total);

        $settings = app(SettingsService::class);
        $settings->set('tax.enabled', true);
        $settings->set('tax.default_rate', '17');
        $sale = $this->sell([$this->line($p)]);
        $this->assertSame('170.00', $sale->tax_total);
        $this->assertSame('1170.00', $sale->grand_total);
        $this->assertSame('1000.00', $sale->items->sole()->net_amount);
        $this->assertSame('17.00', $sale->items->sole()->tax_rate);

        $own = $this->item('1000', '10', '100', ['tax_rate' => '5']);
        $this->assertSame('1050.00', $this->sell([$this->line($own)])->grand_total, "A product's own rate wins over the default.");
    }

    public function test_tax_inclusive_prices_do_not_change_what_the_customer_pays(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('tax.enabled', true);
        $settings->set('tax.default_rate', '17');
        $settings->set('tax.inclusive', true);

        $sale = $this->sell([$this->line($this->item('1170'))]);

        $this->assertSame('1170.00', $sale->grand_total);
        $this->assertSame('170.00', $sale->tax_total);
        $this->assertSame('1000.00', $sale->items->sole()->net_amount);
        $this->assertTrue($sale->tax_inclusive);
    }

    public function test_the_total_can_be_rounded_and_the_difference_is_kept(): void
    {
        app(SettingsService::class)->set('sales.round_to', '10');

        $sale = $this->sell([$this->line($this->item('1234'))]);

        $this->assertSame('1230.00', $sale->grand_total);
        $this->assertSame('-4.00', $sale->round_off);
        $this->assertBooksAgree();
    }

    public function test_serial_numbers_and_warranty_dates_are_kept(): void
    {
        $p = $this->item('150', '10', '100', ['warranty_months' => 12]);

        $sale = $this->sell([$this->line($p, '1', ['serial_number' => '  SN-123  '])]);

        $item = $sale->items->sole();
        $this->assertSame('SN-123', $item->serial_number);
        $this->assertSame(now()->addMonthsNoOverflow(12)->toDateString(), $item->warranty_until->toDateString());
    }

    // ---------------------------------------------------------------- retries, customers, permissions, rollback

    public function test_the_same_checkout_key_never_sells_twice(): void
    {
        $p = $this->item('150', '10');
        $key = (string) Str::uuid();

        $first = $this->sell([$this->line($p, '2')], ['idempotency_key' => $key]);
        $again = $this->sell([$this->line($p, '2')], ['idempotency_key' => $key]);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame('8.000', $p->fresh()->current_stock, 'The stock left once.');
        $this->assertSame(1, CustomerPayment::query()->count());
    }

    public function test_a_checkout_key_belongs_to_the_cashier_who_used_it(): void
    {
        $p = $this->item();
        $key = (string) Str::uuid();
        $this->sell([$this->line($p)], ['idempotency_key' => $key]);

        $this->expectException(DomainException::class);
        $this->sell([$this->line($p)], ['idempotency_key' => $key], $this->manager);
    }

    public function test_a_missing_or_malformed_checkout_key_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sell([$this->line($this->item())], ['idempotency_key' => 'not-a-uuid']);
    }

    public function test_a_named_customer_can_buy_and_pay_in_full_and_an_unknown_one_is_refused(): void
    {
        $named = Customer::factory()->create();
        $sale = $this->sell([$this->line($this->item())], ['customer_id' => $named->id]);
        $this->assertSame($named->id, $sale->customer_id);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertBooksAgree();

        $this->expectException(DomainException::class);
        $this->sell([$this->line($this->item())], ['customer_id' => 999999]);
    }

    public function test_the_walk_in_customer_is_unique_and_cannot_hold_credit(): void
    {
        $this->assertSame(1, Customer::query()->where('is_walk_in', true)->count());

        try {
            Customer::factory()->create()->forceFill(['is_walk_in' => true])->save();
            $this->fail('A second walk-in customer was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        try {
            Customer::walkIn()->forceFill(['allow_credit' => true])->save();
            $this->fail('The walk-in customer was given credit.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_someone_who_may_not_sell_cannot(): void
    {
        $technician = User::factory()->withRole('Technician')->create();

        $this->expectException(AuthorizationException::class);
        $this->sell([$this->line($this->item())], by: $technician);
    }

    public function test_an_exception_in_the_middle_of_posting_leaves_no_trace(): void
    {
        $p = $this->item('150', '10');
        // break the receipt numbering AFTER the sale, its lines and the stock movement were already written
        DocumentSequence::query()->where('doc_type', 'customer_payment')->delete();
        $movements = StockMovement::query()->count();
        $audits = AuditLog::query()->count();

        try {
            $this->sell([$this->line($p, '2')]);
            $this->fail('The injected failure did not happen.');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, DB::table('sale_items')->count());
        $this->assertSame(0, CustomerPayment::query()->count());
        $this->assertSame('10.000', $p->fresh()->current_stock);
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_the_sale_is_written_to_the_audit_trail_with_its_payments_and_lines(): void
    {
        $sale = $this->sell([$this->line($this->item('150'), '2')], ['payments' => [['payment_method_id' => $this->method()->id, 'amount' => '300']]]);

        $log = AuditLog::query()->where('event', 'sale_completed')->sole();
        $this->assertSame('sales', $log->module);
        $this->assertSame($this->cashier->id, $log->user_id);
        $new = $log->new_values;
        $this->assertSame($sale->invoice_no, $new['invoice']);
        $this->assertSame('300.00', $new['total']);
        $this->assertSame([['method' => 'Cash', 'amount' => '300.00']], $new['payments']);
    }

    // ---------------------------------------------------------------- voiding

    public function test_voiding_a_sale_puts_the_goods_back_and_gives_the_money_back(): void
    {
        $p = $this->item('150', '10', '100');
        $sale = $this->sell([$this->line($p, '4')], ['payments' => [['payment_method_id' => $this->method()->id, 'amount' => '600']]]);
        $this->assertSame('6.000', $p->fresh()->current_stock);

        $void = app(VoidSale::class)->execute($sale, 'Customer changed their mind', $this->manager);

        $this->assertSame('voided', $void->status);
        $this->assertSame('Customer changed their mind', $void->void_reason);
        $this->assertSame($this->manager->id, $void->voided_by);
        $this->assertSame('10.000', $p->fresh()->current_stock);
        $this->assertSame('100.0000', $p->fresh()->average_cost);
        $this->assertSame('voided', CustomerPayment::query()->sole()->status);
        $this->assertSame('0.00', $void->paid_amount);
        $this->assertSame('0.00', $void->due_amount);
        $this->assertSame(['sale', 'sale_void'], StockMovement::query()->where('source_type', 'sale')->orderBy('id')->pluck('movement_type')->all());
        $this->assertSame('600.00', $void->grand_total, 'The invoice keeps what it said.');
        $this->assertBooksAgree();
    }

    public function test_a_void_returns_the_goods_at_the_cost_they_left_at(): void
    {
        $p = $this->item('150', '10', '100');
        $sale = $this->sell([$this->line($p, '4')]);
        $this->inventory()->move($p, '10', 'purchase', ['unit_cost' => '200']);   // the average moves on after the sale

        app(VoidSale::class)->execute($sale, 'oops', $this->manager);

        $movement = StockMovement::query()->where('movement_type', 'sale_void')->sole();
        $this->assertSame('100.0000', $movement->unit_cost, 'The original cost, not today\'s average.');
        $this->assertBooksAgree();
    }

    public function test_a_sale_cannot_be_cancelled_twice_or_without_a_reason(): void
    {
        $sale = $this->sell([$this->line($this->item())]);
        $void = app(VoidSale::class);

        try {
            $void->execute($sale, '   ', $this->manager);
            $this->fail('No reason was accepted.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $void->execute($sale, 'ok', $this->manager);
        $this->expectExceptionMessage('already been cancelled');
        $void->execute($sale, 'again', $this->manager);
    }

    public function test_a_cashier_may_not_cancel_a_sale(): void
    {
        $sale = $this->sell([$this->line($this->item())]);

        $this->expectException(AuthorizationException::class);
        app(VoidSale::class)->execute($sale, 'x', $this->cashier);
    }

    public function test_the_void_window_stops_a_manager_but_not_an_administrator(): void
    {
        $p = $this->item('150', '10');
        $sale = $this->sell([$this->line($p, '2')]);
        DB::table('sales')->where('id', $sale->id)->update(['sale_date' => now()->subHours(25)]);

        try {
            app(VoidSale::class)->execute($sale->fresh(), 'late', $this->manager);
            $this->fail('A manager cancelled a sale outside the window.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('void window', $e->getMessage());
        }
        $this->assertSame('8.000', $p->fresh()->current_stock);

        $admin = User::factory()->withRole('Admin')->create();
        $this->assertSame('voided', app(VoidSale::class)->execute($sale->fresh(), 'late but allowed', $admin)->status);
        $this->assertSame('10.000', $p->fresh()->current_stock);
    }

    public function test_a_sale_with_returned_goods_cannot_be_cancelled(): void
    {
        $sale = $this->sell([$this->line($this->item(), '5')]);
        DB::table('sale_items')->where('sale_id', $sale->id)->update(['returned_quantity' => '1']);

        $this->expectExceptionMessage('returned');
        app(VoidSale::class)->execute($sale, 'x', $this->manager);
    }

    // ---------------------------------------------------------------- integrity

    public function test_the_integrity_check_names_a_tampered_invoice(): void
    {
        $sale = $this->sell([$this->line($this->item('150'), '2')], ['payments' => [['payment_method_id' => $this->method()->id, 'amount' => '300']]]);
        $this->assertSame([], app(SaleAccountService::class)->findDrift());

        DB::table('sales')->where('id', $sale->id)->update(['grand_total' => '999.00']);
        $this->assertStringContainsString($sale->invoice_no, implode(' ', app(SaleAccountService::class)->findDrift()));

        DB::table('sales')->where('id', $sale->id)->update(['grand_total' => '300.00', 'paid_amount' => '100.00', 'due_amount' => '200.00', 'payment_status' => 'partial']);
        $this->assertStringContainsString('paid / due / status', implode(' ', app(SaleAccountService::class)->findDrift()));
    }

    public function test_the_integrity_check_notices_stock_that_does_not_match_an_invoice(): void
    {
        $p = $this->item('150', '10');
        $sale = $this->sell([$this->line($p, '2')]);

        DB::table('sale_items')->where('sale_id', $sale->id)->update(['quantity' => '3']);

        $this->assertStringContainsString('units are on the invoice', implode(' ', app(SaleAccountService::class)->findDrift()));
    }
}
