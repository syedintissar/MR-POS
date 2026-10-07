<?php

namespace Tests\Feature\Purchasing;

use App\Actions\Purchasing\AdjustSupplierBalance;
use App\Actions\Purchasing\AllocateSupplierAdvance;
use App\Actions\Purchasing\DeletePurchaseDraft;
use App\Actions\Purchasing\PostSupplierPayment;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Actions\Purchasing\VoidPurchase;
use App\Actions\Purchasing\VoidSupplierPayment;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Purchasing\SupplierAccountService;
use App\Services\SettingsService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class PurchaseActionsTest extends TestCase
{
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $user;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->supplier = Supplier::factory()->create();
    }

    private function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    /** @param list<array{0: Product, 1: string, 2: string}> $lines [product, qty, unit cost] */
    private function draft(array $lines, array $over = [], ?Supplier $supplier = null): Purchase
    {
        return app(SavePurchaseDraft::class)->execute($over + [
            'supplier_id' => ($supplier ?? $this->supplier)->id,
            'purchase_date' => now()->toDateString(),
            'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1], 'unit_cost' => $l[2]], $lines),
        ], $this->user);
    }

    private function receive(Purchase $p, ?array $payment = null): Purchase
    {
        return app(ReceivePurchase::class)->execute($p, $this->user, $payment);
    }

    /** One received bill for one fresh product. */
    private function bill(string $qty, string $cost, ?Supplier $supplier = null, array $over = []): Purchase
    {
        return $this->receive($this->draft([[Product::factory()->create(), $qty, $cost]], $over, $supplier));
    }

    private function pay(string $amount, array $over = [], ?Supplier $supplier = null): SupplierPayment
    {
        return app(PostSupplierPayment::class)->execute($over + [
            'supplier_id' => ($supplier ?? $this->supplier)->id, 'payment_method_id' => $this->method()->id, 'amount' => $amount,
        ], $this->user);
    }

    private function balance(?Supplier $s = null): string
    {
        return (string) ($s ?? $this->supplier)->fresh()->getRawOriginal('balance');
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(SupplierAccountService::class)->findDrift());
        $this->assertSame([], $this->inventory()->findDrift());
        $this->assertSame(
            app(SupplierAccountService::class)->balanceFromLedger($this->supplier->id),
            $this->balance(),
            'The cached balance must equal what the ledger adds up to.',
        );
    }

    // ---------------------------------------------------------------- drafts

    public function test_a_draft_has_totals_but_no_number_no_stock_and_no_ledger_entry(): void
    {
        $p = Product::factory()->create();

        $d = $this->draft([[$p, '10', '100']], ['additional_cost' => '50', 'discount_amount' => '20']);

        $this->assertSame('draft', $d->status);
        $this->assertNull($d->purchase_no);
        $this->assertSame('1000.00', $d->subtotal);
        $this->assertSame('1030.00', $d->grand_total);
        $this->assertSame('0.000', $p->fresh()->current_stock);
        $this->assertSame(0, SupplierLedgerEntry::query()->count());
        $this->assertSame('0.00', $this->balance());
    }

    public function test_a_draft_can_be_edited_and_deleted_but_a_received_purchase_cannot(): void
    {
        $p = Product::factory()->create();
        $d = $this->draft([[$p, '2', '10']]);

        $d = app(SavePurchaseDraft::class)->execute([
            'supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $p->id, 'quantity' => '5', 'unit_cost' => '10']],
        ], $this->user, $d);
        $this->assertSame('50.00', $d->grand_total);
        $this->assertSame(1, $d->items()->count());

        $received = $this->receive($d);
        try {
            app(SavePurchaseDraft::class)->execute(['supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(),
                'lines' => [['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '1']]], $this->user, $received);
            $this->fail('A received purchase must not be editable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already been received', $e->getMessage());
        }
        $this->expectException(DomainException::class);
        app(DeletePurchaseDraft::class)->execute($received, $this->user);
    }

    public function test_deleting_a_draft_removes_it_and_its_lines(): void
    {
        $d = $this->draft([[Product::factory()->create(), '1', '5']]);

        app(DeletePurchaseDraft::class)->execute($d, $this->user);

        $this->assertSame(0, Purchase::query()->count());
        $this->assertSame(0, DB::table('purchase_items')->count());
    }

    public function test_service_items_and_unavailable_products_cannot_be_purchased(): void
    {
        $service = Product::factory()->create(['type' => 'service']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('service item');
        $this->draft([[$service, '1', '10']]);
    }

    public function test_a_whole_unit_product_refuses_a_fractional_quantity(): void
    {
        $p = Product::factory()->create();   // default unit is counted in whole pieces

        $this->expectException(DomainException::class);
        $this->draft([[$p, '1.5', '10']]);
    }

    public function test_the_same_supplier_invoice_number_cannot_be_entered_twice_for_one_supplier(): void
    {
        $p = Product::factory()->create();
        $this->draft([[$p, '1', '10']], ['supplier_invoice_no' => 'INV-77']);

        try {
            $this->draft([[$p, '1', '10']], ['supplier_invoice_no' => 'INV-77']);
            $this->fail('Duplicate invoice number accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('INV-77', $e->getMessage());
        }

        // another supplier may use the same number
        $other = Supplier::factory()->create();
        $this->assertSame('INV-77', $this->draft([[$p, '1', '10']], ['supplier_invoice_no' => 'INV-77'], $other)->supplier_invoice_no);
    }

    public function test_a_cancelled_purchase_frees_its_invoice_number(): void
    {
        $p = Product::factory()->create();
        $received = $this->receive($this->draft([[$p, '2', '10']], ['supplier_invoice_no' => 'INV-9']));

        app(VoidPurchase::class)->execute($received, 'entered wrongly', $this->user);

        $again = $this->draft([[$p, '2', '10']], ['supplier_invoice_no' => 'INV-9']);   // must not throw: the number is free again
        $this->assertTrue($again->isDraft());
        $this->assertSame('INV-9', $again->supplier_invoice_no);
    }

    // ---------------------------------------------------------------- receiving

    public function test_receiving_adds_stock_numbers_the_bill_and_credits_the_supplier(): void
    {
        $p = Product::factory()->create();
        $d = $this->draft([[$p, '10', '100']]);

        $r = $this->receive($d);

        $this->assertSame('received', $r->status);
        $this->assertSame('PUR-000001', $r->purchase_no);
        $this->assertSame($this->user->id, $r->received_by);
        $this->assertSame('10.000', $p->fresh()->current_stock);
        $this->assertSame('100.0000', $p->fresh()->average_cost);
        $this->assertSame('1000.00', $this->balance());
        $this->assertSame('1000.00', $r->due_amount);
        $this->assertSame('0.00', $r->paid_amount);
        $this->assertSame('unpaid', $r->payment_status);

        $entry = SupplierLedgerEntry::query()->sole();
        $this->assertSame('purchase', $entry->entry_type);
        $this->assertSame('1000.00', $entry->credit);
        $this->assertSame('0.00', $entry->debit);
        $this->assertSame($r->id, $entry->source_id);

        $movement = StockMovement::query()->where('source_type', 'purchase')->sole();
        $this->assertSame('purchase', $movement->movement_type);
        $this->assertSame('10.000', $movement->quantity);
        $this->assertSame($d->items()->first()->id, $movement->source_line_id);
        $this->assertBooksAgree();
    }

    public function test_the_average_cost_blends_with_the_stock_already_in_hand(): void
    {
        $p = $this->stocked('10', '100');

        $this->receive($this->draft([[$p, '10', '200']]));

        $p->refresh();
        $this->assertSame('20.000', $p->current_stock);
        $this->assertSame('150.0000', $p->average_cost);
        $this->assertSame('200.0000', $p->last_purchase_cost);
        $this->assertBooksAgree();
    }

    public function test_freight_and_invoice_discount_become_part_of_the_landed_cost(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();

        $r = $this->receive($this->draft([[$a, '10', '100'], [$b, '5', '100']], ['additional_cost' => '150']));

        $this->assertSame('1650.00', $r->grand_total);
        $this->assertSame('110.0000', $a->fresh()->average_cost);   // (1000 + 100) / 10
        $this->assertSame('110.0000', $b->fresh()->average_cost);   // (500 + 50) / 5
        $this->assertSame(['110.0000', '110.0000'], $r->items->pluck('landed_unit_cost')->all());
        $this->assertSame('1650.00', $this->balance());
    }

    public function test_purchase_tax_is_part_of_the_cost_unless_the_shop_claims_it_back(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $draft = fn (Product $p) => $this->draft([[$p, '10', '100']], ['tax_amount' => '180']);

        $this->receive($draft($a));
        $this->assertSame('118.0000', $a->fresh()->average_cost);

        app(SettingsService::class)->set('tax.purchase_tax_in_cost', false);
        $this->receive($draft($b));
        $this->assertSame('100.0000', $b->fresh()->average_cost, 'Claimed tax is not a cost.');
        $this->assertBooksAgree();
    }

    public function test_a_purchase_cannot_be_received_twice(): void
    {
        $p = Product::factory()->create();
        $d = $this->draft([[$p, '10', '100']]);
        $this->receive($d);

        try {
            $this->receive($d);
            $this->fail('Second receive accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already been received', $e->getMessage());
        }

        $this->assertSame('10.000', $p->fresh()->current_stock);
        $this->assertSame('1000.00', $this->balance());
        $this->assertSame(1, SupplierLedgerEntry::query()->count());
    }

    public function test_numbers_are_gap_free_and_in_order(): void
    {
        $this->assertSame('PUR-000001', $this->bill('1', '10')->purchase_no);
        $this->assertSame('PUR-000002', $this->bill('1', '10')->purchase_no);
    }

    public function test_paying_part_of_the_bill_when_receiving_records_the_payment_and_the_balance(): void
    {
        $r = $this->receive($this->draft([[Product::factory()->create(), '10', '100']]), ['payment_method_id' => $this->method()->id, 'amount' => '400']);

        $this->assertSame('400.00', $r->paid_amount);
        $this->assertSame('600.00', $r->due_amount);
        $this->assertSame('partial', $r->payment_status);
        $this->assertSame('600.00', $this->balance());

        $payment = SupplierPayment::query()->sole();
        $this->assertSame('PAY-000001', $payment->payment_no);
        $this->assertSame('400.00', $payment->amount);
        $this->assertSame(['400.00'], $payment->allocations->pluck('amount')->all());
        $this->assertBooksAgree();
    }

    public function test_paying_the_whole_bill_when_receiving_marks_it_paid(): void
    {
        $r = $this->receive($this->draft([[Product::factory()->create(), '2', '50']]), ['payment_method_id' => $this->method()->id, 'amount' => '100']);

        $this->assertSame('paid', $r->payment_status);
        $this->assertSame('0.00', $r->due_amount);
        $this->assertSame('0.00', $this->balance());
        $this->assertBooksAgree();
    }

    // ------------------------------------------------- rollback: nothing half-done

    public function test_a_failed_payment_while_receiving_rolls_the_whole_receipt_back(): void
    {
        $p = Product::factory()->create();
        $d = $this->draft([[$p, '10', '100']]);

        try {
            // bank transfers need a reference number, none given
            $this->receive($d, ['payment_method_id' => $this->method('bank_transfer')->id, 'amount' => '500']);
            $this->fail('The payment without a reference should have been refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reference', $e->getMessage());
        }

        $this->assertSame('draft', $d->fresh()->status, 'The draft must stay a draft.');
        $this->assertNull($d->fresh()->purchase_no);
        $this->assertSame('0.000', $p->fresh()->current_stock);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, SupplierLedgerEntry::query()->count());
        $this->assertSame(0, SupplierPayment::query()->count());
        $this->assertSame('0.00', $this->balance());

        // the numbers were handed back too: the next successful receipt is PUR-000001 / PAY-000001, not 000002
        $r = $this->receive($d, ['payment_method_id' => $this->method('bank_transfer')->id, 'amount' => '500', 'reference_no' => 'TRX-1']);
        $this->assertSame('PUR-000001', $r->purchase_no);
        $this->assertSame('PAY-000001', SupplierPayment::query()->sole()->payment_no);
        $this->assertBooksAgree();
    }

    public function test_paying_more_than_the_bill_while_receiving_is_refused_and_changes_nothing(): void
    {
        $p = Product::factory()->create();
        $d = $this->draft([[$p, '1', '100']]);

        try {
            $this->receive($d, ['payment_method_id' => $this->method()->id, 'amount' => '101']);
            $this->fail('Overpayment accepted.');
        } catch (DomainException) {
        }

        $this->assertSame('draft', $d->fresh()->status);
        $this->assertSame('0.000', $p->fresh()->current_stock);
        $this->assertSame(0, SupplierLedgerEntry::query()->count());
    }

    public function test_a_product_deleted_after_the_draft_was_made_blocks_receiving_without_side_effects(): void
    {
        $keep = Product::factory()->create();
        $gone = Product::factory()->create();
        $d = $this->draft([[$keep, '5', '10'], [$gone, '5', '10']]);
        $gone->delete();

        try {
            $this->receive($d);
            $this->fail('A deleted product was received.');
        } catch (DomainException $e) {
            $this->assertStringContainsString($gone->name, $e->getMessage());
        }

        $this->assertSame('0.000', $keep->fresh()->current_stock);
        $this->assertSame('draft', $d->fresh()->status);
        $this->assertSame(0, StockMovement::query()->count());
    }

    // ---------------------------------------------------------------- voiding a purchase

    public function test_voiding_a_purchase_reverses_stock_average_cost_and_the_supplier_account(): void
    {
        $p = $this->stocked('10', '100');
        $r = $this->receive($this->draft([[$p, '10', '200']]));
        $this->assertSame('150.0000', $p->fresh()->average_cost);

        $v = app(VoidPurchase::class)->execute($r, 'Wrong supplier', $this->user);

        $this->assertSame('voided', $v->status);
        $this->assertSame('Wrong supplier', $v->void_reason);
        $this->assertSame($this->user->id, $v->voided_by);
        $p->refresh();
        $this->assertSame('10.000', $p->current_stock);
        $this->assertSame('100.0000', $p->average_cost, 'The cost the cancelled goods brought in is taken out again.');
        $this->assertSame('0.00', $this->balance());
        $this->assertSame('0.00', $v->due_amount);

        $types = SupplierLedgerEntry::query()->orderBy('id')->pluck('entry_type')->all();
        $this->assertSame(['purchase', 'purchase_void'], $types);
        $this->assertSame(['purchase', 'purchase_void'], StockMovement::query()->where('source_type', 'purchase')->orderBy('id')->pluck('movement_type')->all());
        $this->assertBooksAgree();
    }

    public function test_money_already_paid_on_a_cancelled_purchase_becomes_an_advance(): void
    {
        $r = $this->receive($this->draft([[Product::factory()->create(), '10', '100']]), ['payment_method_id' => $this->method()->id, 'amount' => '400']);

        $v = app(VoidPurchase::class)->execute($r, 'Rejected', $this->user);

        $this->assertSame('0.00', $v->paid_amount);
        $this->assertSame('-400.00', $this->balance(), 'The supplier now holds 400 of ours.');
        $this->assertSame('400.00', app(SupplierAccountService::class)->advanceFor($this->supplier->id));
        $this->assertBooksAgree();
    }

    public function test_a_purchase_whose_goods_were_sold_cannot_be_cancelled_and_nothing_changes(): void
    {
        $p = Product::factory()->create();
        $r = $this->receive($this->draft([[$p, '10', '100']]));
        $this->inventory()->move($p, '-8', 'sale');
        $movementsBefore = StockMovement::query()->count();

        try {
            app(VoidPurchase::class)->execute($r, 'Mistake', $this->user);
            $this->fail('Cancelled although most of the goods are gone.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('cannot be cancelled', $e->getMessage());
        }

        $this->assertSame('received', $r->fresh()->status);
        $this->assertSame('2.000', $p->fresh()->current_stock);
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(1, SupplierLedgerEntry::query()->count());
        $this->assertSame('1000.00', $this->balance());
    }

    public function test_a_purchase_cannot_be_cancelled_twice_or_without_a_reason_and_a_draft_cannot_be_cancelled(): void
    {
        $r = $this->bill('2', '10');
        $void = app(VoidPurchase::class);

        try {
            $void->execute($r, '  ', $this->user);
            $this->fail('No reason accepted.');
        } catch (DomainException) {
        }

        $void->execute($r, 'ok', $this->user);
        try {
            $void->execute($r, 'again', $this->user);
            $this->fail('Second cancel accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already been cancelled', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $void->execute($this->draft([[Product::factory()->create(), '1', '1']]), 'x', $this->user);
    }

    public function test_a_purchase_with_returned_goods_cannot_be_cancelled(): void
    {
        $r = $this->bill('5', '10');
        DB::table('purchase_items')->where('purchase_id', $r->id)->update(['returned_quantity' => '1']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('returned');
        app(VoidPurchase::class)->execute($r, 'x', $this->user);
    }

    // ---------------------------------------------------------------- supplier payments

    public function test_a_payment_settles_the_oldest_bills_first_and_keeps_the_balance_right(): void
    {
        $a = $this->bill('10', '100');   // 1000
        $b = $this->bill('5', '100');    // 500

        $payment = $this->pay('1200');

        $this->assertSame('paid', $a->fresh()->payment_status);
        $this->assertSame('0.00', $a->fresh()->due_amount);
        $this->assertSame('partial', $b->fresh()->payment_status);
        $this->assertSame('200.00', $b->fresh()->paid_amount);
        $this->assertSame('300.00', $b->fresh()->due_amount);
        $this->assertSame('300.00', $this->balance());
        $this->assertSame('0.00', $payment->advanceAmount());

        $debit = SupplierLedgerEntry::query()->where('entry_type', 'payment')->sole();
        $this->assertSame('1200.00', $debit->debit);
        $this->assertBooksAgree();
    }

    public function test_the_part_of_a_payment_beyond_what_is_owed_stays_as_an_advance(): void
    {
        $this->bill('1', '100');

        $payment = $this->pay('150');

        $this->assertSame('100.00', $payment->allocatedAmount());
        $this->assertSame('50.00', $payment->advanceAmount());
        $this->assertSame('-50.00', $this->balance());
        $this->assertBooksAgree();
    }

    public function test_a_manual_payment_settles_exactly_the_chosen_bills(): void
    {
        $a = $this->bill('10', '100');
        $b = $this->bill('5', '100');

        $this->pay('300', ['allocation' => 'manual', 'allocations' => [$b->id => '300']]);

        $this->assertSame('1000.00', $a->fresh()->due_amount, 'The older bill is untouched.');
        $this->assertSame('200.00', $b->fresh()->due_amount);
        $this->assertBooksAgree();
    }

    public function test_a_payment_cannot_settle_a_bill_for_more_than_is_due(): void
    {
        $b = $this->bill('5', '100');

        try {
            $this->pay('900', ['allocation' => 'manual', 'allocations' => [$b->id => '600']]);
            $this->fail('Over-allocation accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('only 500.00 left', $e->getMessage());
        }

        $this->assertSame(0, SupplierPayment::query()->count());
        $this->assertSame('500.00', $this->balance());
    }

    public function test_the_amounts_given_to_bills_cannot_exceed_the_payment(): void
    {
        $a = $this->bill('5', '100');
        $b = $this->bill('5', '100');

        $this->expectException(DomainException::class);
        $this->pay('100', ['allocation' => 'manual', 'allocations' => [$a->id => '80', $b->id => '80']]);
    }

    public function test_a_bill_of_another_supplier_cannot_be_settled(): void
    {
        $other = Supplier::factory()->create();
        $theirs = $this->bill('5', '100', $other);

        $this->expectException(DomainException::class);
        $this->pay('100', ['allocation' => 'manual', 'allocations' => [$theirs->id => '100']]);
    }

    public function test_a_voided_or_draft_bill_cannot_be_settled(): void
    {
        $draft = $this->draft([[Product::factory()->create(), '1', '10']]);

        $this->expectException(DomainException::class);
        $this->pay('10', ['allocation' => 'manual', 'allocations' => [$draft->id => '10']]);
    }

    public function test_payment_method_rules_are_enforced(): void
    {
        $this->bill('1', '100');

        try {
            $this->pay('50', ['payment_method_id' => $this->method('easypaisa')->id]);
            $this->fail('Reference was required.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reference', $e->getMessage());
        }

        try {
            $this->pay('50', ['payment_method_id' => $this->method('customer_credit')->id]);
            $this->fail('Customer credit cannot pay a supplier.');
        } catch (DomainException) {
        }

        $this->method('card')->update(['is_active' => false]);
        try {
            $this->pay('50', ['payment_method_id' => $this->method('card')->id]);
            $this->fail('Inactive method accepted.');
        } catch (DomainException) {
        }

        $this->assertSame(0, SupplierPayment::query()->count());
        $this->assertSame('100.00', $this->balance());

        $ok = $this->pay('50', ['payment_method_id' => $this->method('easypaisa')->id, 'reference_no' => 'EP-123']);
        $this->assertSame('EP-123', $ok->reference_no);
    }

    public function test_zero_and_negative_payments_are_refused(): void
    {
        foreach (['0', '-5', '0.004'] as $amount) {
            try {
                $this->pay($amount);
                $this->fail("Amount {$amount} accepted.");
            } catch (DomainException) {
            }
        }
        $this->assertSame(0, SupplierPayment::query()->count());
    }

    public function test_cancelling_a_payment_reopens_the_bills_and_restores_the_balance(): void
    {
        $a = $this->bill('10', '100');
        $b = $this->bill('5', '100');
        $payment = $this->pay('1200');

        $v = app(VoidSupplierPayment::class)->execute($payment, 'Paid the wrong supplier', $this->user);

        $this->assertSame('voided', $v->status);
        $this->assertSame('1000.00', $a->fresh()->due_amount);
        $this->assertSame('unpaid', $a->fresh()->payment_status);
        $this->assertSame('500.00', $b->fresh()->due_amount);
        $this->assertSame('1500.00', $this->balance());
        $this->assertSame('0.00', $v->advanceAmount());
        $this->assertSame(['purchase', 'purchase', 'payment', 'payment_void'], SupplierLedgerEntry::query()->orderBy('id')->pluck('entry_type')->all());
        $this->assertBooksAgree();

        $this->expectException(DomainException::class);
        app(VoidSupplierPayment::class)->execute($payment, 'again', $this->user);
    }

    public function test_an_advance_can_be_applied_to_a_later_bill_without_moving_any_money(): void
    {
        $first = $this->receive($this->draft([[Product::factory()->create(), '10', '100']]), ['payment_method_id' => $this->method()->id, 'amount' => '400']);
        app(VoidPurchase::class)->execute($first, 'Rejected', $this->user);          // the 400 is now an advance
        $payment = SupplierPayment::query()->sole();
        $second = $this->bill('6', '100');                                           // owes 600
        $entriesBefore = SupplierLedgerEntry::query()->count();

        $payment = app(AllocateSupplierAdvance::class)->execute($payment, [$second->id => '400'], $this->user);

        $this->assertSame('400.00', $second->fresh()->paid_amount);
        $this->assertSame('200.00', $second->fresh()->due_amount);
        $this->assertSame('partial', $second->fresh()->payment_status);
        $this->assertSame('0.00', $payment->advanceAmount());
        $this->assertSame('200.00', $this->balance());
        $this->assertSame($entriesBefore, SupplierLedgerEntry::query()->count(), 'Applying an advance moves no money, so there is no ledger line.');
        $this->assertBooksAgree();
    }

    public function test_an_advance_cannot_be_applied_for_more_than_is_left_of_it(): void
    {
        $payment = $this->pay('100');                 // nothing owed yet: all advance
        $bill = $this->bill('5', '100');

        try {
            app(AllocateSupplierAdvance::class)->execute($payment, [$bill->id => '150'], $this->user);
            $this->fail('Over-applied.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('still unapplied', $e->getMessage());
        }

        app(AllocateSupplierAdvance::class)->execute($payment, [$bill->id => '60'], $this->user);
        $this->expectException(DomainException::class);
        app(AllocateSupplierAdvance::class)->execute($payment, [$bill->id => '60'], $this->user);   // only 40 left
    }

    // ---------------------------------------------------------------- balance adjustments

    public function test_an_opening_balance_can_be_set_once_and_changes_the_ledger_and_balance(): void
    {
        $adjust = app(AdjustSupplierBalance::class);

        $s = $adjust->execute($this->supplier, 'opening_balance', 'we_owe', '25000', 'Carried over from the old register', $this->user);

        $this->assertSame('25000.00', $s->balance);
        $this->assertSame('25000.00', $s->opening_balance);
        $this->assertSame('opening_balance', SupplierLedgerEntry::query()->sole()->entry_type);

        try {
            $adjust->execute($this->supplier, 'opening_balance', 'we_owe', '1', 'again', $this->user);
            $this->fail('Second opening balance accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already has an opening balance', $e->getMessage());
        }
        $this->assertBooksAgree();
    }

    public function test_an_opening_balance_can_be_in_the_shops_favour_and_is_then_settled_by_a_payment_logic(): void
    {
        $s = app(AdjustSupplierBalance::class)->execute($this->supplier, 'opening_balance', 'they_owe', '5000', 'Advance given earlier', $this->user);

        $this->assertSame('-5000.00', $s->balance);
        $this->assertSame('-5000.00', $s->opening_balance);
    }

    public function test_a_balance_adjustment_needs_a_reason_and_a_direction(): void
    {
        $adjust = app(AdjustSupplierBalance::class);

        foreach ([['we_owe', '10', ''], ['sideways', '10', 'why'], ['we_owe', '0', 'why']] as [$dir, $amt, $why]) {
            try {
                $adjust->execute($this->supplier, 'balance_adjustment', $dir, $amt, $why, $this->user);
                $this->fail('Invalid adjustment accepted.');
            } catch (DomainException) {
            }
        }

        $s = $adjust->execute($this->supplier, 'balance_adjustment', 'they_owe', '40', 'Discount agreed by phone', $this->user);
        $this->assertSame('-40.00', $s->balance);
        $this->assertBooksAgree();
    }

    // ---------------------------------------------------------------- the ledger itself

    public function test_the_supplier_ledger_cannot_be_edited_or_deleted_even_with_raw_sql(): void
    {
        $this->bill('1', '10');

        try {
            DB::table('supplier_ledger_entries')->update(['credit' => '1.00']);
            $this->fail('Ledger row was updated.');
        } catch (QueryException) {
        }
        try {
            DB::table('supplier_ledger_entries')->delete();
            $this->fail('Ledger row was deleted.');
        } catch (QueryException) {
        }

        $this->assertSame('10.00', SupplierLedgerEntry::query()->sole()->credit);
    }

    public function test_the_database_refuses_an_entry_that_is_both_debit_and_credit_or_neither(): void
    {
        foreach ([['debit' => 5, 'credit' => 5], ['debit' => 0, 'credit' => 0]] as $amounts) {
            try {
                DB::table('supplier_ledger_entries')->insert($amounts + [
                    'supplier_id' => $this->supplier->id, 'entry_date' => now()->toDateString(), 'entry_type' => 'balance_adjustment',
                    'description' => 'x', 'created_at' => now()->format('Y-m-d H:i:s.u'),
                ]);
                $this->fail('Invalid ledger row accepted.');
            } catch (QueryException) {
            }
        }
    }

    public function test_every_step_is_written_to_the_audit_trail(): void
    {
        $r = $this->receive($this->draft([[Product::factory()->create(), '1', '100']]), ['payment_method_id' => $this->method()->id, 'amount' => '30']);
        $payment = SupplierPayment::query()->sole();
        app(VoidSupplierPayment::class)->execute($payment, 'oops', $this->user);
        app(VoidPurchase::class)->execute($r, 'oops', $this->user);

        $events = AuditLog::query()->where('module', 'purchasing')->orderBy('id')->pluck('event')->all();
        $this->assertSame(
            ['purchase_draft_created', 'supplier_payment_posted', 'purchase_received', 'supplier_payment_voided', 'purchase_voided'],   // the audit write is always last, so the payment made on the spot is logged first
            array_values(array_diff($events, [])),
        );
    }

    public function test_the_whole_supplier_account_adds_up_after_a_busy_day(): void
    {
        $a = $this->bill('10', '100');
        $b = $this->bill('4', '250', null, ['additional_cost' => '40']);
        $this->pay('700');
        $c = $this->bill('3', '75.55');
        $this->pay('200', ['allocation' => 'manual', 'allocations' => [$c->id => '100']]);
        app(VoidPurchase::class)->execute($b, 'returned to the van', $this->user);
        $this->pay('10', ['allocation' => 'advance']);

        $this->assertBooksAgree();
        foreach ([$a, $c] as $p) {
            $this->assertSame(
                bcsub(bcsub((string) $p->grand_total, (string) DB::table('purchase_payments as pp')->join('supplier_payments as sp', 'sp.id', '=', 'pp.supplier_payment_id')
                    ->where('pp.purchase_id', $p->id)->where('sp.status', 'posted')->sum('pp.amount'), 2), '0', 2),
                $p->fresh()->due_amount,
            );
        }
    }
}
