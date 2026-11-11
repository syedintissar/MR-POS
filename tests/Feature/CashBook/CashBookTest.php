<?php

namespace Tests\Feature\CashBook;

use App\Actions\CashBook\CloseDay;
use App\Actions\CashBook\PostMoneyTransaction;
use App\Actions\CashBook\VoidDayClosing;
use App\Actions\CashBook\VoidMoneyTransaction;
use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\Actions\Sales\CompleteSale;
use App\Models\AuditLog;
use App\Models\DayClosing;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use App\Models\PaymentMethod;
use App\Models\User;
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

class CashBookTest extends TestCase
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

    private function method(string $code): int
    {
        return (int) PaymentMethod::query()->where('code', $code)->value('id');
    }

    private function book(): MoneyAccountService
    {
        return app(MoneyAccountService::class);
    }

    /** A cash sale of $amount (one unit of a product at that price). */
    private function cashSale(string $amount): void
    {
        $product = $this->stocked('5', '10', ['sale_price' => $amount]);
        app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $product->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => $this->method('cash'), 'amount' => $amount]],
        ], $this->manager);
    }

    private function expense(string $amount, string $method = 'cash')
    {
        return app(SaveExpense::class)->execute([
            'expense_category_id' => ExpenseCategory::query()->where('name', 'Rent')->value('id'), 'expense_date' => now()->toDateString(), 'amount' => $amount,
            'payment_method_id' => $this->method($method), 'description' => 'Test',
        ], $this->manager);
    }

    private function entry(array $over, ?User $by = null): MoneyTransaction
    {
        return app(PostMoneyTransaction::class)->execute($over + ['txn_date' => now()->toDateString()], $by ?? $this->manager);
    }

    private function close(array $d, ?User $by = null): DayClosing
    {
        return app(CloseDay::class)->execute($this->drawer(), $d, $by ?? $this->manager);
    }

    // ---------------------------------------------------------------- accounts and where the money lands

    public function test_every_shop_starts_with_a_drawer_a_bank_account_and_a_wallet_account_each(): void
    {
        $this->assertSame('cash', $this->drawer()->kind);
        $this->assertSame('Cash drawer', $this->drawer()->name);

        $map = PaymentMethod::query()->with('account')->get()->mapWithKeys(fn ($m) => [$m->code => $m->account?->name])->all();
        $this->assertSame('Cash drawer', $map['cash']);
        $this->assertSame('Bank account', $map['bank_transfer']);
        $this->assertSame('Bank account', $map['card']);
        $this->assertSame('Bank account', $map['cheque']);
        $this->assertSame('Easypaisa', $map['easypaisa']);
        $this->assertSame('JazzCash', $map['jazzcash']);
        $this->assertNull($map['customer_credit']);
        $this->assertSame([], $this->book()->unlinkedMethods());
    }

    public function test_sales_and_expenses_land_in_the_account_of_their_payment_method(): void
    {
        $this->cashSale('300');
        $this->expense('120');
        $bankExpense = $this->expense('50', 'bank_transfer');

        $balances = $this->book()->balances();
        $this->assertSame('180.00', $balances[$this->drawer()->id]);
        $this->assertSame('-50.00', $balances[$this->bank()->id]);
        $this->assertSame('180.00', $this->book()->balance($this->drawer()->id));

        // a cancelled document counts nowhere
        app(VoidExpense::class)->execute($bankExpense, 'wrong', $this->manager);
        $this->assertSame('0.00', $this->book()->balance($this->bank()->id));
    }

    public function test_the_book_of_an_account_runs_from_what_was_brought_forward(): void
    {
        $this->cashSale('300');
        $this->expense('120');
        DB::table('customer_payments')->update(['payment_date' => now()->subDay()]);   // the sale was yesterday

        $today = now()->toDateString();
        $book = $this->book()->book($this->drawer(), $today, $today);

        $this->assertSame('300.00', $book['opening']);
        $this->assertSame('0.00', $book['money_in']);
        $this->assertSame('120.00', $book['money_out']);
        $this->assertSame('180.00', $book['closing']);
        $this->assertCount(1, $book['rows']);
        $this->assertSame('expense', $book['rows'][0]['source']);
        $this->assertSame('180.00', $book['rows'][0]['balance']);
        $this->assertSame('300.00', $this->book()->balances(now()->subDay()->toDateString())[$this->drawer()->id]);
    }

    // ---------------------------------------------------------------- entries

    public function test_cash_taken_to_the_bank_moves_between_the_two_accounts(): void
    {
        $this->cashSale('500');
        $t = $this->entry(['type' => 'transfer', 'from_account_id' => $this->drawer()->id, 'to_account_id' => $this->bank()->id, 'amount' => '350', 'reference_no' => 'SLIP-1']);

        $this->assertSame('CBK-000001', $t->txn_no);
        $this->assertSame('150.00', $this->book()->balance($this->drawer()->id));
        $this->assertSame('350.00', $this->book()->balance($this->bank()->id));
        $this->assertSame(1, AuditLog::query()->where('event', 'money_transaction_posted')->count());

        app(VoidMoneyTransaction::class)->execute($t, 'never went', $this->manager);
        $this->assertSame('500.00', $this->book()->balance($this->drawer()->id));
        $this->assertSame('0.00', $this->book()->balance($this->bank()->id));

        $this->expectException(DomainException::class);
        app(VoidMoneyTransaction::class)->execute($t->fresh(), 'again', $this->manager);
    }

    public function test_owner_money_changes_the_balance_but_never_the_profit(): void
    {
        $this->cashSale('500');
        $today = now()->toDateString();
        $before = app(ProfitService::class)->summary($today, $today)['net_profit'];

        $this->entry(['type' => 'owner_out', 'from_account_id' => $this->drawer()->id, 'amount' => '200']);
        $this->entry(['type' => 'owner_in', 'to_account_id' => $this->bank()->id, 'amount' => '1000']);

        $this->assertSame('300.00', $this->book()->balance($this->drawer()->id));
        $this->assertSame('1000.00', $this->book()->balance($this->bank()->id));
        $this->assertSame($before, app(ProfitService::class)->summary($today, $today)['net_profit']);
    }

    public function test_bad_entries_are_refused(): void
    {
        $this->cashSale('100');
        $drawer = $this->drawer()->id;
        $bank = $this->bank()->id;

        foreach ([
            ['type' => 'transfer', 'from_account_id' => $drawer, 'to_account_id' => $drawer, 'amount' => '10'],
            ['type' => 'transfer', 'from_account_id' => $drawer, 'to_account_id' => null, 'amount' => '10'],
            ['type' => 'transfer', 'from_account_id' => $drawer, 'to_account_id' => $bank, 'amount' => '0'],
            ['type' => 'transfer', 'from_account_id' => $drawer, 'to_account_id' => $bank, 'amount' => '-5'],
            ['type' => 'transfer', 'from_account_id' => $drawer, 'to_account_id' => $bank, 'amount' => '100.01'],   // the drawer only holds 100
            ['type' => 'transfer', 'from_account_id' => $drawer, 'to_account_id' => 99999, 'amount' => '10'],
            ['type' => 'owner_out', 'from_account_id' => $drawer, 'amount' => '10', 'txn_date' => now()->addDay()->toDateString()],
            ['type' => 'owner_out', 'from_account_id' => null, 'amount' => '10'],
            ['type' => 'count_short', 'from_account_id' => $drawer, 'amount' => '10'],   // only a day closing writes these
            ['type' => 'nonsense', 'amount' => '10'],
        ] as $bad) {
            try {
                $this->entry($bad);
                $this->fail('Accepted: '.json_encode($bad));
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, MoneyTransaction::query()->count());

        // a bank account may be overdrawn; only cash is held to what it has
        $this->entry(['type' => 'owner_out', 'from_account_id' => $bank, 'amount' => '999']);
        $this->assertSame('-999.00', $this->book()->balance($bank));
    }

    public function test_a_switched_off_account_takes_no_new_entries(): void
    {
        $this->bank()->forceFill(['is_active' => false])->save();
        $this->expectException(DomainException::class);
        $this->entry(['type' => 'owner_in', 'to_account_id' => $this->bank()->id, 'amount' => '10']);
    }

    public function test_an_opening_balance_is_entered_once_by_an_administrator(): void
    {
        try {
            $this->entry(['type' => 'opening_balance', 'to_account_id' => $this->drawer()->id, 'amount' => '5000']);
            $this->fail('A manager entered an opening balance.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $first = $this->entry(['type' => 'opening_balance', 'to_account_id' => $this->drawer()->id, 'amount' => '5000'], $this->admin);
        $this->assertSame('5000.00', $this->book()->balance($this->drawer()->id));

        try {
            $this->entry(['type' => 'opening_balance', 'to_account_id' => $this->drawer()->id, 'amount' => '1'], $this->admin);
            $this->fail('A second opening balance was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already has an opening balance', $e->getMessage());
        }

        // a wrong one is cancelled and entered again
        app(VoidMoneyTransaction::class)->execute($first, 'typed wrongly', $this->admin);
        $this->entry(['type' => 'opening_balance', 'to_account_id' => $this->drawer()->id, 'amount' => '5500'], $this->admin);
        $this->assertSame('5500.00', $this->book()->balance($this->drawer()->id));
    }

    public function test_only_people_with_the_permission_record_or_cancel_entries(): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();
        try {
            $this->entry(['type' => 'owner_in', 'to_account_id' => $this->drawer()->id, 'amount' => '10'], $cashier);
            $this->fail('A cashier recorded an entry.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $t = $this->entry(['type' => 'owner_in', 'to_account_id' => $this->drawer()->id, 'amount' => '10']);
        $this->expectException(AuthorizationException::class);
        app(VoidMoneyTransaction::class)->execute($t, 'x', $cashier);
    }

    // ---------------------------------------------------------------- closing the day

    public function test_an_exact_count_closes_the_day_without_any_entry(): void
    {
        $this->cashSale('1570');
        $c = $this->close(['denominations' => [1000 => 1, 500 => 1, 50 => '1', 20 => 1, 'coins' => '0']]);

        $this->assertSame('DCL-000001', $c->closing_no);
        $this->assertSame('1570.00', (string) $c->expected_amount);
        $this->assertSame('1570.00', (string) $c->counted_amount);
        $this->assertSame('0.00', (string) $c->difference);
        $this->assertSame('exact', $c->result());
        $this->assertNull($c->adjustment_transaction_id);
        $this->assertSame(['1000' => 1, '500' => 1, '50' => 1, '20' => 1], $c->denominations);
        $this->assertSame(0, MoneyTransaction::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'day_closed')->count());
    }

    public function test_a_short_count_must_be_explained_and_brings_the_book_to_the_drawer(): void
    {
        $this->cashSale('1000');

        try {
            $this->close(['counted_amount' => '950']);
            $this->fail('A short count was accepted without an explanation.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('short', $e->getMessage());
        }
        $this->assertSame(0, DayClosing::query()->count());

        $c = $this->close(['counted_amount' => '950', 'notes' => 'Wrong change given']);
        $this->assertSame('-50.00', (string) $c->difference);
        $this->assertSame('short', $c->result());
        $this->assertNull($c->denominations);
        $this->assertSame('count_short', $c->adjustment->type);
        $this->assertSame('50.00', (string) $c->adjustment->amount);
        $this->assertSame($this->drawer()->id, $c->adjustment->from_account_id);
        $this->assertSame('950.00', $this->book()->balance($this->drawer()->id));

        // the next count starts from what was really there
        $next = $this->close(['counted_amount' => '950']);
        $this->assertSame('950.00', (string) $next->expected_amount);
        $this->assertSame('exact', $next->result());
    }

    public function test_a_count_that_is_over_is_recorded_too(): void
    {
        $this->cashSale('1000');
        $c = $this->close(['denominations' => [1000 => 1, 'coins' => '25.50'], 'notes' => 'Customer left without change']);

        $this->assertSame('1025.50', (string) $c->counted_amount);
        $this->assertSame('25.50', (string) $c->difference);
        $this->assertSame('count_over', $c->adjustment->type);
        $this->assertSame($this->drawer()->id, $c->adjustment->to_account_id);
        $this->assertSame('1025.50', $this->book()->balance($this->drawer()->id));
    }

    public function test_bad_counts_are_refused(): void
    {
        foreach ([[], ['counted_amount' => '-1'], ['counted_amount' => 'abc'], ['denominations' => [500 => '1.5']], ['denominations' => [500 => -1]], ['denominations' => ['coins' => '-3']]] as $bad) {
            try {
                $this->close($bad + ['notes' => 'x']);
                $this->fail('Accepted: '.json_encode($bad));
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            app(CloseDay::class)->execute($this->bank(), ['counted_amount' => '0'], $this->manager);
            $this->fail('A bank account was counted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Only a cash account', $e->getMessage());
        }

        $this->expectException(AuthorizationException::class);
        $this->close(['counted_amount' => '0'], User::factory()->withRole('Cashier')->create());
    }

    public function test_only_the_latest_closing_can_be_cancelled_and_its_difference_goes_with_it(): void
    {
        $this->cashSale('1000');
        $first = $this->close(['counted_amount' => '900', 'notes' => 'short']);
        $second = $this->close(['counted_amount' => '910', 'notes' => 'found a note']);

        try {
            app(VoidDayClosing::class)->execute($first, 'x', $this->manager);
            $this->fail('An older closing was cancelled.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('latest', $e->getMessage());
        }
        try {
            app(VoidMoneyTransaction::class)->execute($second->adjustment, 'x', $this->manager);
            $this->fail('The entry of a closing was cancelled on its own.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('day closing', $e->getMessage());
        }

        $second = app(VoidDayClosing::class)->execute($second, 'counted twice', $this->manager);
        $this->assertTrue($second->isVoided());
        $this->assertTrue($second->adjustment->isVoided());
        $this->assertSame('900.00', $this->book()->balance($this->drawer()->id));

        // now the first is the latest again
        app(VoidDayClosing::class)->execute($first, 'start over', $this->manager);
        $this->assertSame('1000.00', $this->book()->balance($this->drawer()->id));
        $this->assertSame(2, AuditLog::query()->where('event', 'day_closing_voided')->count());
    }

    // ---------------------------------------------------------------- integrity

    public function test_the_integrity_check_passes_and_notices_tampering(): void
    {
        $this->cashSale('1000');
        $this->entry(['type' => 'transfer', 'from_account_id' => $this->drawer()->id, 'to_account_id' => $this->bank()->id, 'amount' => '400']);
        $c = $this->close(['counted_amount' => '590', 'notes' => 'short ten']);
        $this->entry(['type' => 'opening_balance', 'to_account_id' => $this->bank()->id, 'amount' => '100'], $this->admin);

        $this->assertSame([], $this->book()->findDrift());
        $this->artisan('pos:integrity:check')->assertSuccessful();

        DB::table('money_transactions')->where('id', $c->adjustment_transaction_id)->update(['amount' => '5.00']);
        $this->assertCount(1, $this->book()->findDrift());
        $this->artisan('pos:integrity:check')->assertFailed();
    }

    public function test_the_database_refuses_an_entry_of_the_wrong_shape(): void
    {
        $this->expectException(QueryException::class);
        DB::table('money_transactions')->insert([
            'txn_no' => 'X-1', 'type' => 'transfer', 'txn_date' => now()->toDateString(), 'from_account_id' => $this->drawer()->id, 'to_account_id' => null,
            'amount' => '10', 'description' => 'x', 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- the screens

    public function test_a_manager_uses_every_screen(): void
    {
        $this->cashSale('1000');
        $this->expense('100');
        $drawer = $this->drawer();
        $this->actingAs($this->manager);

        $this->get('/')->assertOk()->assertSee('Cash &amp; bank', false);
        $this->get('/cash-book')->assertOk()->assertSee('Cash drawer')->assertSee('900.00')->assertSee('Close the day');
        $this->get("/cash-book/accounts/{$drawer->id}")->assertOk()->assertSee('Received from customer')->assertSee('Expense: Rent');
        $this->get("/cash-book/accounts/{$drawer->id}?from=2020-01-01&to=".now()->toDateString())->assertOk();
        $this->get("/cash-book/accounts/{$drawer->id}/print")->assertOk()->assertSee('Cash &amp; bank book: Cash drawer', false)->assertSee('Balance brought forward');
        $this->get('/cash-book/entries/create')->assertOk()->assertDontSee('Opening balance (what the account held');

        $this->post('/cash-book/entries', ['type' => 'transfer', 'txn_date' => now()->toDateString(), 'amount' => '400', 'from_account_id' => $drawer->id, 'to_account_id' => $this->bank()->id, 'reference_no' => 'SLIP-9'])
            ->assertRedirect();
        $entry = MoneyTransaction::query()->sole();
        $this->get("/cash-book/entries/{$entry->id}")->assertOk()->assertSee('SLIP-9');
        $this->get('/cash-book/entries?status=all&type=transfer')->assertOk()->assertSee($entry->txn_no);

        // more than the drawer holds: refused with the reason, nothing saved
        $this->post('/cash-book/entries', ['type' => 'owner_out', 'txn_date' => now()->toDateString(), 'amount' => '9999', 'from_account_id' => $drawer->id])->assertSessionHas('error');
        $this->assertSame(1, MoneyTransaction::query()->count());

        $this->get('/cash-book/closings/create')->assertOk()->assertSee('500.00')->assertSee('Rs. 5,000');
        $this->post('/cash-book/closings', ['money_account_id' => $drawer->id, 'denominations' => ['100' => '4', '50' => '1', 'coins' => ''], 'notes' => 'Fifty short'])->assertRedirect();
        $closing = DayClosing::query()->sole();
        $this->assertSame('-50.00', (string) $closing->difference);
        $this->get("/cash-book/closings/{$closing->id}")->assertOk()->assertSee('Fifty short')->assertSee('Short');
        $this->get('/cash-book/closings?result=short')->assertOk()->assertSee($closing->closing_no);
        $this->get('/cash-book/closings?result=over')->assertOk()->assertDontSee($closing->closing_no);

        // cancelling asks for the password first
        $this->post("/cash-book/closings/{$closing->id}/void", ['reason' => 'again'])->assertRedirect(route('password.confirm'));
        $this->withSession($this->confirmed())->post("/cash-book/closings/{$closing->id}/void", ['reason' => 'again'])->assertRedirect();
        $this->assertTrue($closing->fresh()->isVoided());
        $this->withSession($this->confirmed())->post("/cash-book/entries/{$entry->id}/void", ['reason' => 'not deposited'])->assertRedirect();
        $this->assertSame('900.00', $this->book()->balance($drawer->id));

        // accounts and opening balances are the administrator's
        $this->get('/cash-book/setup')->assertForbidden();
        $this->post('/cash-book/entries', ['type' => 'opening_balance', 'txn_date' => now()->toDateString(), 'amount' => '10', 'to_account_id' => $drawer->id])->assertForbidden();
    }

    public function test_an_administrator_sets_up_accounts_and_where_methods_pay_into(): void
    {
        $this->actingAs($this->admin);

        $this->get('/cash-book/setup')->assertOk()->assertSee('Where each payment method pays into')->assertDontSee('Customer Credit');
        $this->post('/cash-book/accounts', ['name' => 'HBL current', 'kind' => 'bank', 'account_number' => '0123'])->assertRedirect();
        $hbl = MoneyAccount::query()->where('name', 'HBL current')->sole();
        $this->post('/cash-book/accounts', ['name' => 'HBL current', 'kind' => 'bank'])->assertSessionHasErrors('name');
        $this->put("/cash-book/accounts/{$hbl->id}", ['name' => 'HBL main', 'account_number' => '0123'])->assertRedirect();
        $this->assertSame('HBL main', $hbl->fresh()->name);

        // a method pointed at another account pays into it from now on; what it already took stays where it landed
        $this->expense('70', 'cheque');
        $cheque = PaymentMethod::query()->where('code', 'cheque')->sole();
        $this->put("/cash-book/methods/{$cheque->id}", ['money_account_id' => $hbl->id])->assertRedirect(route('password.confirm'));
        $this->withSession($this->confirmed())->put("/cash-book/methods/{$cheque->id}", ['money_account_id' => $hbl->id])->assertRedirect();
        $this->expense('20', 'cheque');
        $this->assertSame('-20.00', $this->book()->balance($hbl->id));
        $this->assertSame('-70.00', $this->book()->balance($this->bank()->id));
        $this->assertSame(1, AuditLog::query()->where('event', 'payment_method_account_changed')->count());

        // an account with a method paying into it, and the main drawer, cannot be switched off
        $this->post("/cash-book/accounts/{$hbl->id}/toggle")->assertSessionHas('error');
        $this->post("/cash-book/accounts/{$this->drawer()->id}/toggle")->assertSessionHas('error');
        $this->assertTrue($hbl->fresh()->is_active);

        // a method without an account is pointed out
        $this->withSession($this->confirmed())->put("/cash-book/methods/{$cheque->id}", ['money_account_id' => ''])->assertRedirect();
        $this->assertSame([['id' => $cheque->id, 'name' => 'Cheque', 'used' => false]], $this->book()->unlinkedMethods());
        $this->get('/cash-book')->assertOk()->assertSee('Not in any account');
        $this->post("/cash-book/accounts/{$hbl->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($hbl->fresh()->is_active);

        // customer credit can never be given an account
        $credit = PaymentMethod::query()->where('kind', 'customer_credit')->sole();
        $this->withSession($this->confirmed())->put("/cash-book/methods/{$credit->id}", ['money_account_id' => $this->drawer()->id])->assertStatus(422);

        $this->get('/cash-book/entries/create?type=opening_balance')->assertOk()->assertSee('Opening balance (what the account held');
        $this->post('/cash-book/entries', ['type' => 'opening_balance', 'txn_date' => now()->toDateString(), 'amount' => '2500', 'to_account_id' => $this->drawer()->id])->assertRedirect();
        $this->assertSame('2500.00', $this->book()->balance($this->drawer()->id));
    }

    public function test_a_new_cash_payment_method_pays_into_the_drawer_at_once(): void
    {
        $this->actingAs($this->admin)->post('/admin/payment-methods', ['name' => 'Petty cash', 'kind' => 'cash'])->assertRedirect();
        $this->assertSame($this->drawer()->id, PaymentMethod::query()->where('name', 'Petty cash')->value('money_account_id'));

        $this->post('/admin/payment-methods', ['name' => 'Voucher', 'kind' => 'other'])->assertRedirect();
        $this->assertNull(PaymentMethod::query()->where('name', 'Voucher')->value('money_account_id'));
        $this->assertSame('Voucher', $this->book()->unlinkedMethods()[0]['name']);
    }

    public function test_cashiers_and_technicians_have_no_cash_and_bank_menu(): void
    {
        foreach (['Cashier', 'Technician'] as $role) {
            $this->as($role)->get('/')->assertOk()->assertDontSee('Cash &amp; bank', false);
            $this->get('/cash-book')->assertForbidden();
        }
    }
}
