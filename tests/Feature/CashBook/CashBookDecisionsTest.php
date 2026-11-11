<?php

namespace Tests\Feature\CashBook;

use App\Actions\CashBook\CloseDay;
use App\Actions\CashBook\PostMoneyTransaction;
use App\Actions\CashBook\VoidDayClosing;
use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\Actions\Sales\CompleteSale;
use App\Models\AuditLog;
use App\Models\BankReconciliation;
use App\Models\DayClosing;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\CashBook\BankReconciliationService;
use App\Services\CashBook\MoneyAccountService;
use App\Services\Reports\ProfitService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The owner's decisions after Phase 11: one drawer per counter, the reminder, cash short or over in net profit, bank statement matching. */
class CashBookDecisionsTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $manager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->withRole('Manager')->create();
        $this->admin = User::factory()->withRole('Admin')->create();
    }

    private function drawer(): MoneyAccount
    {
        return MoneyAccount::query()->where('is_system', true)->sole();
    }

    private function bank(): MoneyAccount
    {
        return MoneyAccount::query()->where('name', 'Bank account')->sole();
    }

    private function account(string $name, string $kind): MoneyAccount
    {
        $a = new MoneyAccount;
        $a->forceFill(['name' => $name, 'kind' => $kind, 'is_active' => true, 'is_system' => false, 'sort_order' => 99])->save();

        return $a;
    }

    private function method(string $code): int
    {
        return (int) PaymentMethod::query()->where('code', $code)->value('id');
    }

    private function book(): MoneyAccountService
    {
        return app(MoneyAccountService::class);
    }

    private function statements(): BankReconciliationService
    {
        return app(BankReconciliationService::class);
    }

    private function cashSale(string $amount, ?User $by = null): void
    {
        $product = $this->stocked('5', '10', ['sale_price' => $amount]);
        app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $product->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => $this->method('cash'), 'amount' => $amount]],
        ], $by ?? $this->manager);
    }

    private function expense(string $amount, string $method = 'cash', ?string $date = null, ?User $by = null): Expense
    {
        return app(SaveExpense::class)->execute([
            'expense_category_id' => ExpenseCategory::query()->where('name', 'Rent')->value('id'), 'expense_date' => $date ?? now()->toDateString(), 'amount' => $amount,
            'payment_method_id' => $this->method($method), 'description' => 'Test',
        ], $by ?? $this->manager);
    }

    private function entry(array $d, ?User $by = null): MoneyTransaction
    {
        return app(PostMoneyTransaction::class)->execute($d + ['txn_date' => now()->toDateString()], $by ?? $this->manager);
    }

    // ---------------------------------------------------------------- one drawer per counter

    public function test_cash_lands_in_the_drawer_of_the_person_who_took_it(): void
    {
        $counter2 = $this->account('Counter 2 drawer', 'cash');
        $cashier = User::factory()->withRole('Cashier')->create();
        $cashier->forceFill(['money_account_id' => $counter2->id])->save();

        $this->cashSale('300', $cashier);
        $this->cashSale('200');                                   // the manager has no drawer of their own
        $this->expense('40', 'cash', null, $this->manager);

        $this->assertSame('300.00', $this->book()->balance($counter2->id));
        $this->assertSame('160.00', $this->book()->balance($this->drawer()->id));

        // giving the cashier another drawer changes the future, never the past
        $cashier->forceFill(['money_account_id' => null])->save();
        $this->cashSale('100', $cashier);
        $this->assertSame('300.00', $this->book()->balance($counter2->id));
        $this->assertSame('260.00', $this->book()->balance($this->drawer()->id));

        // each drawer is counted on its own
        $c = app(CloseDay::class)->execute($counter2, ['counted_amount' => '300'], $this->manager);
        $this->assertSame('exact', $c->result());
    }

    public function test_money_that_is_not_cash_ignores_the_drawer_and_a_corrected_expense_follows_its_method(): void
    {
        $counter2 = $this->account('Counter 2 drawer', 'cash');
        $this->manager->forceFill(['money_account_id' => $counter2->id])->save();

        $e = $this->expense('70', 'bank_transfer');
        $this->assertSame($this->bank()->id, $e->fresh()->money_account_id);

        // corrected on the same day to "paid in cash": it moves to the drawer of the person who entered it
        app(SaveExpense::class)->execute([
            'expense_category_id' => $e->expense_category_id, 'expense_date' => now()->toDateString(), 'amount' => '70',
            'payment_method_id' => $this->method('cash'), 'description' => 'Test',
        ], $this->manager, $e);
        $this->assertSame($counter2->id, $e->fresh()->money_account_id);
        $this->assertSame('0.00', $this->book()->balance($this->bank()->id));
        $this->assertSame('-70.00', $this->book()->balance($counter2->id));
    }

    public function test_an_administrator_gives_a_person_a_drawer(): void
    {
        $counter2 = $this->account('Counter 2 drawer', 'cash');
        $cashier = User::factory()->withRole('Cashier')->create();
        $this->actingAs($this->admin);

        $this->get('/cash-book/setup')->assertOk()->assertSee('One drawer per counter')->assertSee($cashier->name);
        $this->put("/cash-book/people/{$cashier->id}/drawer", ['money_account_id' => $counter2->id])->assertRedirect()->assertSessionHas('success');
        $this->assertSame($counter2->id, $cashier->fresh()->money_account_id);
        $this->assertSame(1, AuditLog::query()->where('event', 'user_drawer_changed')->count());

        // a bank account is not a drawer; a drawer in use cannot be switched off
        $this->put("/cash-book/people/{$cashier->id}/drawer", ['money_account_id' => $this->bank()->id])->assertSessionHasErrors('money_account_id');
        $this->post("/cash-book/accounts/{$counter2->id}/toggle")->assertSessionHas('error');

        // choosing the main drawer is the same as having none
        $this->put("/cash-book/people/{$cashier->id}/drawer", ['money_account_id' => $this->drawer()->id])->assertRedirect();
        $this->assertNull($cashier->fresh()->money_account_id);

        $this->actingAs($this->manager)->put("/cash-book/people/{$cashier->id}/drawer", ['money_account_id' => ''])->assertForbidden();
    }

    public function test_a_method_given_an_account_later_takes_its_earlier_money_with_it_once(): void
    {
        $this->actingAs($this->admin)->post('/admin/payment-methods', ['name' => 'Voucher', 'kind' => 'other'])->assertRedirect();
        $voucher = PaymentMethod::query()->where('name', 'Voucher')->sole();
        $this->expense('30', $voucher->code);

        $this->assertSame('0.00', $this->book()->balance($this->bank()->id));
        $this->assertSame([['id' => $voucher->id, 'name' => 'Voucher', 'used' => true]], $this->book()->unlinkedMethods());

        $this->withSession($this->confirmed())->put("/cash-book/methods/{$voucher->id}", ['money_account_id' => $this->bank()->id])->assertRedirect();
        $this->assertSame('-30.00', $this->book()->balance($this->bank()->id));
        $this->assertSame([], $this->book()->unlinkedMethods());

        // pointed at another account afterwards: what already landed stays where it is
        $other = $this->account('Second bank', 'bank');
        $this->withSession($this->confirmed())->put("/cash-book/methods/{$voucher->id}", ['money_account_id' => $other->id])->assertRedirect();
        $this->expense('5', $voucher->code);
        $this->assertSame('-30.00', $this->book()->balance($this->bank()->id));
        $this->assertSame('-5.00', $this->book()->balance($other->id));
    }

    // ---------------------------------------------------------------- the reminder

    public function test_a_drawer_not_counted_since_an_earlier_day_is_pointed_out_until_it_is_closed(): void
    {
        $this->cashSale('500');
        $this->book()->forgetReminder();
        $this->assertSame([], $this->book()->unclosedDrawers());   // today's money alone is no reminder

        DB::table('customer_payments')->update(['payment_date' => now()->subDay()->setTime(15, 0)]);
        $this->book()->forgetReminder();
        $unclosed = $this->book()->unclosedDrawers();
        $this->assertCount(1, $unclosed);
        $this->assertSame('Cash drawer', $unclosed[0]['name']);
        $this->assertSame(now()->subDay()->toDateString(), $unclosed[0]['since']);

        // the manager is offered the closing, the cashier is told to tell the manager, selling is not stopped
        $this->actingAs($this->manager)->get('/')->assertOk()->assertSee('has not been counted since')->assertSee('Close the day');
        $this->as('Cashier')->get('/pos')->assertOk()->assertSee('has not been counted since')->assertSee('Please tell the manager');
        $this->as('Technician')->get('/')->assertOk()->assertDontSee('has not been counted since');

        $closing = app(CloseDay::class)->execute($this->drawer(), ['counted_amount' => '500'], $this->manager);
        $this->assertSame([], $this->book()->unclosedDrawers());
        $this->actingAs($this->manager)->get('/')->assertOk()->assertDontSee('has not been counted since');

        app(VoidDayClosing::class)->execute($closing, 'counted the wrong drawer', $this->manager);
        $this->assertCount(1, $this->book()->unclosedDrawers());
    }

    // ---------------------------------------------------------------- cash short or over in net profit

    public function test_cash_short_or_over_counts_in_net_profit_only(): void
    {
        $this->cashSale('1000');
        $today = now()->toDateString();
        $before = app(ProfitService::class)->summary($today, $today);

        $closing = app(CloseDay::class)->execute($this->drawer(), ['counted_amount' => '950', 'notes' => 'short'], $this->manager);
        $after = app(ProfitService::class)->summary($today, $today);
        $this->assertSame('-50.00', $after['cash_difference']);
        $this->assertSame($before['gross_profit'], $after['gross_profit']);
        $this->assertSame(bcsub($before['net_profit'], '50', 2), $after['net_profit']);

        app(CloseDay::class)->execute($this->drawer(), ['counted_amount' => '970', 'notes' => 'found a note'], $this->manager);
        $this->assertSame('-30.00', app(ProfitService::class)->summary($today, $today)['cash_difference']);
        $this->assertSame('-30.00', app(ProfitService::class)->grouped($today, $today, 'day')[0]['cash_difference']);

        $this->actingAs($this->manager)->get('/reports/profit')->assertOk()->assertSee('Cash short or over at day closing');

        // a cancelled closing takes its loss with it
        app(VoidDayClosing::class)->execute(DayClosing::query()->latest('id')->first(), 'x', $this->manager);
        app(VoidDayClosing::class)->execute($closing->fresh(), 'x', $this->manager);
        $this->assertSame($before['net_profit'], app(ProfitService::class)->summary($today, $today)['net_profit']);
    }

    // ---------------------------------------------------------------- bank statement matching

    /** The bank book: 1,000 opening and 500 put in yesterday, a 200 expense yesterday, 50 put in today. */
    private function bankBook(): array
    {
        $yesterday = now()->subDay()->toDateString();
        $bank = $this->bank()->id;
        $opening = $this->entry(['type' => 'opening_balance', 'to_account_id' => $bank, 'amount' => '1000', 'txn_date' => $yesterday], $this->admin);
        $in = $this->entry(['type' => 'owner_in', 'to_account_id' => $bank, 'amount' => '500', 'txn_date' => $yesterday]);
        $expense = $this->expense('200', 'bank_transfer', $yesterday);
        $today = $this->entry(['type' => 'owner_in', 'to_account_id' => $bank, 'amount' => '50']);

        return ['yesterday' => $yesterday, 'opening' => "money_transaction:{$opening->id}", 'in' => "money_transaction:{$in->id}", 'expense' => "expense:{$expense->id}", 'today' => "money_transaction:{$today->id}", 'expenseModel' => $expense];
    }

    public function test_a_statement_is_closed_only_when_the_ticked_lines_add_up_to_it(): void
    {
        $b = $this->bankBook();
        $r = $this->statements()->start($this->bank(), $b['yesterday'], '1300', 'October', $this->manager);

        $this->assertSame('REC-000001', $r->reconciliation_no);
        $this->assertSame('0.00', (string) $r->opening_balance);
        $this->assertSame([$b['opening'], $b['in'], $b['expense']], array_column($this->statements()->lines($r), 'key'));   // today's line is after the statement

        // saved ticks are kept; a statement that does not add up is not closed and nothing of that step is kept
        $r = $this->statements()->save($r, [$b['opening']], false, $this->manager);
        $this->assertTrue($r->isOpen());
        $this->assertSame('300.00', $this->statements()->totals($r)['difference']);
        try {
            $this->statements()->save($r, [$b['opening'], $b['in']], true, $this->manager);
            $this->fail('A statement that does not add up was closed.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('difference of -200.00', $e->getMessage());
        }
        $this->assertSame(1, $this->statements()->totals($r->fresh())['count']);

        $r = $this->statements()->save($r, [$b['opening'], $b['in'], $b['expense'], 'expense:999999', 'nonsense'], true, $this->manager);
        $this->assertTrue($r->isCompleted());
        $t = $this->statements()->totals($r);
        $this->assertSame(['1500.00', '200.00', '1300.00', '0.00', 3], [$t['money_in'], $t['money_out'], $t['matched'], $t['difference'], $t['count']]);
        $this->assertSame(1, AuditLog::query()->where('event', 'bank_reconciliation_completed')->count());

        // closed is closed
        $this->expectException(DomainException::class);
        $this->statements()->save($r, [], false, $this->manager);
    }

    public function test_the_next_statement_starts_where_the_last_one_ended_and_a_line_is_matched_once(): void
    {
        $b = $this->bankBook();
        $first = $this->statements()->save($this->statements()->start($this->bank(), $b['yesterday'], '1300', null, $this->manager), [$b['opening'], $b['in'], $b['expense']], true, $this->manager);

        // a closed statement is not reopened by a back-dated entry, and the next one must be later
        foreach ([fn () => $this->entry(['type' => 'owner_in', 'to_account_id' => $this->bank()->id, 'amount' => '1', 'txn_date' => $b['yesterday']]),
            fn () => $this->statements()->start($this->bank(), $b['yesterday'], '0', null, $this->manager)] as $refused) {
            try {
                $refused();
                $this->fail('Accepted something dated inside a closed statement.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $second = $this->statements()->start($this->bank(), now()->toDateString(), '1350', null, $this->manager);
        $this->assertSame('1300.00', (string) $second->opening_balance);
        $this->assertSame([$b['today']], array_column($this->statements()->lines($second), 'key'));

        // one statement of an account at a time
        try {
            $this->statements()->start($this->bank(), now()->toDateString(), '1350', null, $this->manager);
            $this->fail('Two statements of one account were open together.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already being matched', $e->getMessage());
        }

        // the database itself refuses a line matched twice
        try {
            DB::table('bank_reconciliation_items')->insert(['bank_reconciliation_id' => $second->id, 'money_account_id' => $this->bank()->id, 'source' => 'expense', 'source_id' => $b['expenseModel']->id, 'doc_no' => 'X', 'line_date' => $b['yesterday'], 'money_out' => '200']);
            $this->fail('A line was matched on two statements.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // only the latest can be cancelled; then its lines are open again
        try {
            $this->statements()->void($first, 'x', $this->manager);
            $this->fail('An older statement was cancelled.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('latest', $e->getMessage());
        }
        $this->statements()->void($second, 'wrong balance', $this->manager);
        $first = $this->statements()->void($first, 'start again', $this->manager);
        $this->assertTrue($first->isVoided());
        $this->assertSame(0, DB::table('bank_reconciliation_items')->count());
        $again = $this->statements()->start($this->bank(), now()->toDateString(), '1350', null, $this->manager);
        $this->assertCount(4, $this->statements()->lines($again));
    }

    public function test_cash_is_not_matched_and_the_permissions_hold(): void
    {
        try {
            $this->statements()->start($this->drawer(), now()->toDateString(), '0', null, $this->manager);
            $this->fail('The cash drawer was matched against a statement.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('is cash', $e->getMessage());
        }
        foreach (['abc', ''] as $bad) {
            try {
                $this->statements()->start($this->bank(), now()->toDateString(), $bad, null, $this->manager);
                $this->fail('Accepted a balance of ['.$bad.'].');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(AuthorizationException::class);
        $this->statements()->start($this->bank(), now()->toDateString(), '0', null, User::factory()->withRole('Cashier')->create());
    }

    public function test_the_integrity_check_notices_a_matched_line_cancelled_afterwards(): void
    {
        $b = $this->bankBook();
        $this->statements()->save($this->statements()->start($this->bank(), $b['yesterday'], '1300', null, $this->manager), [$b['opening'], $b['in'], $b['expense']], true, $this->manager);

        $this->assertSame([], $this->statements()->findDrift());
        $this->artisan('pos:integrity:check')->assertSuccessful();

        app(VoidExpense::class)->execute($b['expenseModel'], 'wrong', $this->manager);
        $problems = $this->statements()->findDrift();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString($b['expenseModel']->expense_no, $problems[0]);
        $this->artisan('pos:integrity:check')->assertFailed();
    }

    public function test_a_manager_matches_a_statement_on_the_screens(): void
    {
        $b = $this->bankBook();
        $this->actingAs($this->manager);

        $this->get('/cash-book')->assertOk()->assertSee('Bank statements');
        $this->get('/cash-book/statements')->assertOk()->assertSee('Match a new statement')->assertDontSee('Cash drawer (book');
        $this->post('/cash-book/statements', ['money_account_id' => $this->drawer()->id, 'statement_date' => $b['yesterday'], 'statement_balance' => '0'])->assertSessionHas('error');
        $this->post('/cash-book/statements', ['money_account_id' => $this->bank()->id, 'statement_date' => $b['yesterday'], 'statement_balance' => '1300'])->assertRedirect();
        $r = BankReconciliation::query()->sole();

        $this->get("/cash-book/statements/{$r->id}")->assertOk()->assertSee('Opening balance')->assertSee('Expense: Rent')->assertSee('Close the statement');
        $this->put("/cash-book/statements/{$r->id}", ['lines' => [$b['opening']]])->assertRedirect()->assertSessionHas('success', 'Ticks saved.');
        $this->put("/cash-book/statements/{$r->id}", ['lines' => [$b['opening'], $b['in']], 'complete' => '1'])->assertSessionHas('error');
        $this->put("/cash-book/statements/{$r->id}", ['lines' => [$b['opening'], $b['in'], $b['expense']], 'complete' => '1'])->assertRedirect();
        $this->assertTrue($r->fresh()->isCompleted());
        $this->get("/cash-book/statements/{$r->id}")->assertOk()->assertSee('Closed by')->assertSee($b['expenseModel']->expense_no);
        $this->get('/cash-book/statements')->assertOk()->assertSee($r->reconciliation_no);

        $this->post("/cash-book/statements/{$r->id}/void", ['reason' => 'x'])->assertRedirect(route('password.confirm'));
        $this->withSession($this->confirmed())->post("/cash-book/statements/{$r->id}/void", ['reason' => 'wrong month'])->assertRedirect();
        $this->assertTrue($r->fresh()->isVoided());
        $this->get("/cash-book/statements/{$r->id}")->assertOk()->assertSee('wrong month');
    }
}
