<?php

namespace Tests\Feature\Returns;

use App\Actions\Purchasing\ApplySupplierCredit;
use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Actions\Purchasing\PostSupplierPayment;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Actions\Purchasing\VoidPurchase;
use App\Actions\Purchasing\VoidPurchaseReturn;
use App\Actions\Purchasing\VoidSupplierCreditApplication;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierCreditApplication;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Services\Purchasing\SupplierAccountService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\Concerns\ReturnHelpers;
use Tests\TestCase;

/** Supplier credit left over from goods sent back: how it arises, "Use supplier credit", never twice, undoing it, and who may. */
class SupplierCreditTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;
    use ReturnHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeManager();
    }

    private function useCredit(?Purchase $only = null, ?User $by = null): array
    {
        return app(ApplySupplierCredit::class)->execute($this->supplier->id, $only, $by ?? $this->manager);
    }

    private function credit(): string
    {
        return app(SupplierAccountService::class)->creditAvailable($this->supplier->id);
    }

    /** A paid bill of 1000 and 300 of it sent back: 300 of credit with the supplier. */
    private function withCredit(string $amount = '300'): void
    {
        $paid = $this->bill('10', '100', true);
        $this->sendBack($paid, (string) ((float) $amount / 100));
    }

    // ---------------------------------------------------------------- where credit comes from

    public function test_a_return_records_how_its_credit_was_split(): void
    {
        $paidBill = $this->bill('10', '100', true);
        $credit = $this->sendBack($paidBill, '3');
        $this->assertSame(['0.00', '300.00', '0.00'], [$credit->applied_to_due, $credit->credit_amount, $credit->refund_amount], 'a paid bill: all of it is credit');
        $this->assertSame('-300.00', $this->supplierBalance());

        $unpaid = $this->bill('10', '100');
        $off = $this->sendBack($unpaid, '4');
        $this->assertSame(['400.00', '0.00', '0.00'], [$off->applied_to_due, $off->credit_amount, $off->refund_amount], 'an unpaid bill: it all goes off the bill');

        $part = $this->bill('10', '100');
        app(PostSupplierPayment::class)->execute(['supplier_id' => $this->supplier->id, 'payment_method_id' => $this->method()->id, 'amount' => '800', 'allocation' => 'manual', 'allocations' => [$part->id => '800']], $this->manager);
        $mixed = $this->sendBack($part, '5', ['settlement' => 'refund', 'payment_method_id' => $this->method()->id]);
        $this->assertSame(['200.00', '0.00', '300.00'], [$mixed->applied_to_due, $mixed->credit_amount, $mixed->refund_amount], '200 off the bill, 300 paid back');

        $free = app(CreatePurchaseReturn::class)->execute(['supplier_id' => $this->supplier->id, 'reason' => 'x', 'lines' => [['product_id' => $this->stocked('5', '10')->id, 'quantity' => '2', 'unit_credit' => '60']]], $this->manager);
        $this->assertSame(['0.00', '120.00', '0.00'], [$free->applied_to_due, $free->credit_amount, $free->refund_amount], 'without a bill it is all credit');

        $this->assertSame('420.00', $this->credit(), '300 + 120');
        $this->assertAllBooksAgree();
    }

    // ---------------------------------------------------------------- using it

    public function test_the_credit_pays_a_later_bill_without_moving_the_suppliers_balance(): void
    {
        $this->withCredit('300');
        $bill = $this->bill('5', '100');   // 500 owed, the supplier's balance is -300 + 500
        $ledger = SupplierLedgerEntry::query()->count();

        $used = $this->useCredit();

        $this->assertCount(1, $used);
        $this->assertSame(['300.00', 'posted'], [(string) $used[0]->amount, $used[0]->status]);
        $this->assertSame('200.00', $bill->fresh()->due_amount);
        $this->assertSame('partial', $bill->fresh()->payment_status);
        $this->assertSame('200.00', $this->supplierBalance(), 'the balance already said it: the bill and the balance now agree');
        $this->assertSame($ledger, SupplierLedgerEntry::query()->count(), 'no ledger line: no money moved');
        $this->assertSame('0.00', $this->credit());
        $this->assertSame(1, AuditLog::query()->where('event', 'supplier_credit_used')->count());
        $this->assertAllBooksAgree();
    }

    public function test_credit_cannot_be_used_twice(): void
    {
        $this->withCredit('300');
        $this->bill('5', '100');
        $this->useCredit();

        try {
            $this->useCredit();
            $this->fail('The same credit was used a second time.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('holds no credit', $e->getMessage());
        }

        $this->bill('5', '100');   // a new bill does not bring the credit back
        $this->expectException(DomainException::class);
        $this->useCredit();
    }

    public function test_it_goes_to_the_oldest_bill_first_and_carries_on_to_the_next(): void
    {
        $this->withCredit('300');
        $first = $this->bill('2', '100', false, null, ['purchase_date' => now()->subDays(2)->toDateString()]);
        $second = $this->bill('2', '100');

        $used = $this->useCredit();

        $this->assertSame(['200.00', '100.00'], array_map(fn ($u) => (string) $u->amount, $used));
        $this->assertSame([$first->id, $second->id], array_map(fn ($u) => $u->purchase_id, $used));
        $this->assertSame(['0.00', 'paid', '100.00'], [$first->fresh()->due_amount, $first->fresh()->payment_status, $second->fresh()->due_amount]);
        $this->assertAllBooksAgree();
    }

    public function test_one_chosen_bill_gets_it_and_never_more_than_it_owes(): void
    {
        $this->withCredit('300');
        $older = $this->bill('2', '100', false, null, ['purchase_date' => now()->subDays(2)->toDateString()]);
        $chosen = $this->bill('1', '100');

        $used = $this->useCredit($chosen);

        $this->assertSame(['100.00'], array_map(fn ($u) => (string) $u->amount, $used), 'only the bill that was chosen, and only what it owed');
        $this->assertSame(['200.00', '0.00'], [$older->fresh()->due_amount, $chosen->fresh()->due_amount]);
        $this->assertSame('200.00', $this->credit());
        $this->expectException(DomainException::class);   // it has nothing left to pay
        $this->useCredit($chosen);
    }

    public function test_credit_that_went_off_a_bill_or_came_back_as_money_is_not_available(): void
    {
        $unpaid = $this->bill('10', '100');
        $this->sendBack($unpaid, '4');
        $this->assertSame('0.00', $this->credit());

        $this->expectException(DomainException::class);
        $this->useCredit();
    }

    public function test_a_bill_of_another_supplier_cannot_be_paid_with_this_suppliers_credit(): void
    {
        $this->withCredit('300');
        $other = Supplier::factory()->create();
        $draft = app(SavePurchaseDraft::class)->execute(['supplier_id' => $other->id, 'purchase_date' => now()->toDateString(), 'lines' => [['product_id' => Product::factory()->create()->id, 'quantity' => '1', 'unit_cost' => '100']]], $this->manager);
        $foreign = app(ReceivePurchase::class)->execute($draft, $this->manager);

        $this->expectException(DomainException::class);
        $this->useCredit($foreign);
    }

    // ---------------------------------------------------------------- taking a use back

    public function test_a_use_can_be_taken_back_and_the_credit_is_available_again(): void
    {
        $this->withCredit('300');
        $bill = $this->bill('5', '100');
        $use = $this->useCredit()[0];

        $voided = app(VoidSupplierCreditApplication::class)->execute($use, 'Used on the wrong bill', $this->manager);

        $this->assertTrue($voided->isVoided());
        $this->assertSame('500.00', $bill->fresh()->due_amount);
        $this->assertSame('300.00', $this->credit());
        $this->assertSame(['Used on the wrong bill'], [$voided->void_reason]);
        $this->assertSame(1, AuditLog::query()->where('event', 'supplier_credit_use_cancelled')->count());
        $this->assertAllBooksAgree();

        $again = $this->useCredit()[0];   // and it can be used again, once
        $this->assertSame('300.00', (string) $again->amount);
        $this->assertSame(2, SupplierCreditApplication::query()->count(), 'the first use stays as history');

        $this->expectException(DomainException::class);
        app(VoidSupplierCreditApplication::class)->execute($use, 'again', $this->manager);
    }

    public function test_a_return_or_a_bill_with_credit_used_cannot_be_cancelled_until_the_use_is(): void
    {
        $paid = $this->bill('10', '100', true);
        $return = $this->sendBack($paid, '3');
        $bill = $this->bill('5', '100');
        $use = $this->useCredit()[0];

        foreach ([
            'the return' => fn () => app(VoidPurchaseReturn::class)->execute($return, 'Mistake', $this->manager),
            'the bill' => fn () => app(VoidPurchase::class)->execute($bill->fresh(), 'Mistake', $this->manager),
        ] as $what => $try) {
            try {
                $try();
                $this->fail("{$what} was cancelled while its credit was in use.");
            } catch (DomainException $e) {
                $this->assertStringContainsString('credit', $e->getMessage());
            }
        }

        app(VoidSupplierCreditApplication::class)->execute($use, 'Undo', $this->manager);
        $this->assertTrue(app(VoidPurchaseReturn::class)->execute($return, 'Mistake', $this->manager)->isVoided());
        $this->assertSame('0.00', $this->credit(), 'a cancelled return leaves no credit');
        $this->assertAllBooksAgree();
    }

    // ---------------------------------------------------------------- permissions and pages

    public function test_using_and_taking_back_credit_need_their_permissions(): void
    {
        $this->withCredit('300');
        $this->bill('5', '100');

        foreach (['Cashier', 'Technician'] as $role) {
            try {
                $this->useCredit(null, User::factory()->withRole($role)->create());
                $this->fail("{$role} used supplier credit.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $use = $this->useCredit()[0];
        try {
            app(VoidSupplierCreditApplication::class)->execute($use, 'x', User::factory()->withRole('Cashier')->create());
            $this->fail('A cashier took back a use of credit.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(DomainException::class);   // a reason is needed
        app(VoidSupplierCreditApplication::class)->execute($use, '  ', $this->manager);
    }

    public function test_the_supplier_and_purchase_pages_offer_the_button_and_show_the_history(): void
    {
        $this->withCredit('300');
        $bill = $this->bill('5', '100');

        $this->as('Manager');
        $this->get("/suppliers/{$this->supplier->id}")->assertOk()->assertSee('Supplier credit')->assertSee('Use supplier credit')->assertSee('300.00');
        $this->get("/purchases/{$bill->id}")->assertOk()->assertSee('Use supplier credit (300.00)');

        $this->post("/suppliers/{$this->supplier->id}/use-credit")->assertRedirect(route('suppliers.show', $this->supplier))->assertSessionHas('success');
        $this->assertSame('200.00', $bill->fresh()->due_amount);
        $this->get("/suppliers/{$this->supplier->id}")->assertOk()->assertSee('None of the credit is left')->assertDontSee('Use supplier credit')->assertSee('Take back');
        $this->get("/purchases/{$bill->id}")->assertOk()->assertSee('Supplier credit used on this bill')->assertDontSee('Use supplier credit (');
        $this->post("/suppliers/{$this->supplier->id}/use-credit")->assertSessionHas('error');   // nothing left

        // taking it back asks for the password and a reason
        $use = SupplierCreditApplication::query()->sole();
        $this->post("/supplier-credit/{$use->id}/void", ['reason' => 'Wrong bill']);
        $this->assertFalse($use->fresh()->isVoided(), 'the password was not asked for');
        $this->withSession($this->confirmed())->post("/supplier-credit/{$use->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->withSession($this->confirmed())->post("/supplier-credit/{$use->id}/void", ['reason' => 'Wrong bill'])->assertSessionHas('success');
        $this->assertTrue($use->fresh()->isVoided());

        // one bill only
        $this->post("/purchases/{$bill->id}/use-credit")->assertRedirect(route('purchases.show', $bill))->assertSessionHas('success');
        $this->assertSame('200.00', $bill->fresh()->due_amount);

        foreach (['Cashier', 'Technician'] as $role) {
            $this->as($role);
            $this->post("/suppliers/{$this->supplier->id}/use-credit")->assertForbidden();
            $this->post("/purchases/{$bill->id}/use-credit")->assertForbidden();
            $this->withSession($this->confirmed())->post("/supplier-credit/{$use->id}/void", ['reason' => 'x'])->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- integrity

    public function test_the_integrity_check_notices_credit_that_was_used_too_much_or_a_bill_that_does_not_match(): void
    {
        $this->withCredit('300');
        $bill = $this->bill('5', '100');
        $use = $this->useCredit()[0];
        $drift = fn () => app(SupplierAccountService::class)->findDrift();
        $this->assertSame([], $drift());

        DB::table('supplier_credit_applications')->where('id', $use->id)->update(['amount' => '350.00']);
        $this->assertNotSame([], $drift(), 'more was used than the return left');
        DB::table('supplier_credit_applications')->where('id', $use->id)->update(['amount' => '300.00']);
        $this->assertSame([], $drift());

        DB::table('purchases')->where('id', $bill->id)->update(['due_amount' => '500.00']);
        $this->assertNotSame([], $drift(), 'the bill ignores the credit that was used on it');
    }
}
