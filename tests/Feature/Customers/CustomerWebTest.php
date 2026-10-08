<?php

namespace Tests\Feature\Customers;

use App\Actions\Sales\CompleteSale;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The customer pages and the customer parts of the till, driven the way the browser drives them. */
class CustomerWebTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private function method(): PaymentMethod
    {
        return PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    }

    private function named(array $attrs = []): Customer
    {
        return Customer::factory()->create($attrs);
    }

    /** A credit sale to the customer, so that the account has a history (and something owed). */
    private function credit(Customer $c, string $amount = '500'): Sale
    {
        $p = $this->stocked('50', '10', ['sale_price' => $amount]);

        return app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $c->id, 'lines' => [['product_id' => $p->id, 'quantity' => '1']],
        ], User::factory()->withRole('Cashier')->create());
    }

    private function realCustomers(): int
    {
        return Customer::query()->where('is_walk_in', false)->count();
    }

    // ---------------------------------------------------------------- the pages

    public function test_the_customer_pages_open(): void
    {
        $c = $this->named(['name' => 'Zed Traders']);
        $this->as('Manager');

        $this->get('/customers')->assertOk()->assertSee('Zed Traders');
        $this->get('/customers?q=Zed&status=all&balance=owing')->assertOk();
        $this->get('/customers?status=inactive&balance=credit')->assertOk();
        $this->get('/customers/create')->assertOk();
        $this->get("/customers/{$c->id}")->assertOk()->assertSee('Zed Traders');
        $this->get("/customers/{$c->id}/edit")->assertOk();
    }

    public function test_the_walk_in_customer_has_no_page_and_cannot_be_changed(): void
    {
        $walk = Customer::walkIn();
        $this->as('Admin');

        $this->get("/customers/{$walk->id}")->assertNotFound();
        $this->get("/customers/{$walk->id}/edit")->assertNotFound();
        $this->post("/customers/{$walk->id}/toggle")->assertNotFound();
        $this->put("/customers/{$walk->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->assertNotSame('Hacked', $walk->fresh()->name);
    }

    // ---------------------------------------------------------------- creating and editing

    public function test_a_cashier_adds_a_customer_but_cannot_give_credit_terms_or_wholesale(): void
    {
        $this->as('Cashier');

        $this->post('/customers', ['name' => 'Cash Only Co', 'phone' => '0300-5550001', 'allow_credit' => 1, 'credit_limit' => '5000', 'price_tier' => 'wholesale'])
            ->assertRedirect();

        $c = Customer::query()->where('name', 'Cash Only Co')->firstOrFail();
        $this->assertFalse($c->allow_credit);
        $this->assertNull($c->credit_limit);
        $this->assertSame('retail', $c->price_tier);
        $this->assertSame(1, AuditLog::query()->where('event', 'customer_created')->count());
    }

    public function test_a_manager_can_give_credit_terms_and_the_wholesale_list(): void
    {
        $this->as('Manager');

        $this->post('/customers', ['name' => 'Trusted Ltd', 'allow_credit' => 1, 'credit_limit' => '5000', 'price_tier' => 'wholesale'])->assertRedirect();

        $c = Customer::query()->where('name', 'Trusted Ltd')->firstOrFail();
        $this->assertTrue($c->allow_credit);
        $this->assertSame('5000.00', $c->credit_limit);
        $this->assertSame('wholesale', $c->price_tier);
    }

    public function test_editing_as_a_cashier_keeps_the_credit_terms_a_manager_set(): void
    {
        $c = $this->named(['allow_credit' => true, 'credit_limit' => '1000', 'price_tier' => 'wholesale']);
        $this->as('Cashier');

        $this->put("/customers/{$c->id}", ['name' => 'Renamed', 'phone' => $c->phone])->assertRedirect(route('customers.show', $c));

        $c->refresh();
        $this->assertSame('Renamed', $c->name);
        $this->assertTrue($c->allow_credit);
        $this->assertSame('1000.00', $c->credit_limit);
        $this->assertSame('wholesale', $c->price_tier);
    }

    public function test_a_phone_number_that_belongs_to_someone_needs_a_confirmation(): void
    {
        $this->named(['name' => 'First Owner', 'phone' => '0300-1112223']);
        $before = $this->realCustomers();
        $this->as('Cashier');

        $this->from('/customers/create')->post('/customers', ['name' => 'Second', 'phone' => '0300 1112223'])
            ->assertRedirect('/customers/create')->assertSessionHas('duplicate_phone')->assertSessionHas('error');
        $this->assertSame($before, $this->realCustomers());

        $this->post('/customers', ['name' => 'Second', 'phone' => '0300 1112223', 'allow_duplicate_phone' => 1])->assertRedirect();
        $this->assertSame($before + 1, $this->realCustomers());
    }

    public function test_a_customer_keeps_their_own_phone_number_when_edited(): void
    {
        $c = $this->named(['phone' => '0300-7770000']);
        $this->as('Cashier');

        $this->put("/customers/{$c->id}", ['name' => 'Same Phone', 'phone' => '0300-7770000'])->assertRedirect(route('customers.show', $c))->assertSessionMissing('error');
        $this->assertSame('Same Phone', $c->fresh()->name);
    }

    public function test_a_name_is_required_and_the_credit_limit_is_checked(): void
    {
        $this->as('Manager');

        $this->post('/customers', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/customers', ['name' => 'X', 'credit_limit' => '-5'])->assertSessionHasErrors('credit_limit');
        $this->post('/customers', ['name' => 'X', 'credit_limit' => '10.123'])->assertSessionHasErrors('credit_limit');
        $this->assertSame(0, $this->realCustomers());
    }

    public function test_deactivating_a_customer_is_audited_and_hides_them_from_the_till(): void
    {
        $c = $this->named(['name' => 'Hidden Harry']);
        $this->as('Manager');

        $this->post("/customers/{$c->id}/toggle")->assertRedirect();
        $this->assertFalse($c->fresh()->is_active);
        $this->assertSame(1, AuditLog::query()->where('event', 'customer_deactivated')->count());

        $this->getJson('/pos/customers/search?q=Harry')->assertOk()->assertJsonCount(0);
        $this->get('/customers')->assertOk()->assertDontSee('Hidden Harry');
        $this->get('/customers?status=inactive')->assertOk()->assertSee('Hidden Harry');

        $this->post("/customers/{$c->id}/toggle")->assertRedirect();
        $this->assertTrue($c->fresh()->is_active);
    }

    // ---------------------------------------------------------------- deleting

    public function test_deleting_a_customer_needs_the_password_and_is_refused_once_there_is_history(): void
    {
        $unused = $this->named(['name' => 'Never Used']);
        $used = $this->named(['name' => 'Has History', 'allow_credit' => true]);
        $this->credit($used);

        $this->as('Cashier')->delete("/customers/{$unused->id}")->assertForbidden();

        $this->as('Manager');
        $this->delete("/customers/{$unused->id}");
        $this->assertNotSoftDeleted($unused);

        $this->withSession($this->confirmed())->delete("/customers/{$used->id}")->assertRedirect()->assertSessionHas('error');
        $this->assertNotSoftDeleted($used);

        $this->withSession($this->confirmed())->delete("/customers/{$unused->id}")->assertRedirect(route('customers.index'));
        $this->assertSoftDeleted($unused);
        $this->assertSame(1, AuditLog::query()->where('event', 'customer_deleted')->count());
    }

    // ---------------------------------------------------------------- opening balances

    public function test_only_the_administrator_can_set_an_opening_balance_and_only_with_the_password(): void
    {
        $c = $this->named();
        $data = ['type' => 'opening_balance', 'direction' => 'customer_owes', 'amount' => '1500', 'reason' => 'Taken from the old register'];

        $this->as('Manager')->post("/customers/{$c->id}/adjust-balance", $data)->assertForbidden();
        $this->as('Cashier')->post("/customers/{$c->id}/adjust-balance", $data)->assertForbidden();

        $admin = $this->as('Admin');
        $admin->post("/customers/{$c->id}/adjust-balance", $data);
        $this->assertSame('0.00', (string) $c->fresh()->getRawOriginal('balance'), 'the password was not asked for');

        $this->withSession($this->confirmed())->post("/customers/{$c->id}/adjust-balance", $data)->assertRedirect(route('customers.show', $c));
        $this->assertSame('1500.00', (string) $c->fresh()->getRawOriginal('balance'));
        $this->assertSame(1, CustomerLedgerEntry::query()->where('customer_id', $c->id)->where('entry_type', 'opening_balance')->count());

        $this->withSession($this->confirmed())->post("/customers/{$c->id}/adjust-balance", ['direction' => 'shop_owes', 'amount' => '0', 'type' => 'balance_adjustment', 'reason' => 'x'])
            ->assertSessionHasErrors('amount');
        $this->withSession($this->confirmed())->post("/customers/{$c->id}/adjust-balance", ['direction' => 'shop_owes', 'amount' => '500', 'type' => 'balance_adjustment'])
            ->assertSessionHasErrors('reason');

        $this->withSession($this->confirmed())->post("/customers/{$c->id}/adjust-balance", ['direction' => 'shop_owes', 'amount' => '200', 'type' => 'balance_adjustment', 'reason' => 'Customer overpaid']);
        $this->assertSame('1300.00', (string) $c->fresh()->getRawOriginal('balance'));
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
    }

    // ---------------------------------------------------------------- who sees the money

    public function test_a_technician_sees_customers_but_never_what_they_owe(): void
    {
        $c = $this->named(['name' => 'Owing Omar']);
        $this->as('Admin');
        $this->withSession($this->confirmed())->post("/customers/{$c->id}/adjust-balance", ['type' => 'opening_balance', 'direction' => 'customer_owes', 'amount' => '12345.67', 'reason' => 'old debt']);

        $this->as('Manager');
        $this->get('/customers')->assertOk()->assertSee('12,345.67');
        $this->get("/customers/{$c->id}")->assertOk()->assertSee('12,345.67');

        $this->as('Technician');
        $this->get('/customers')->assertOk()->assertSee('Owing Omar')->assertDontSee('12,345.67');
        $this->get("/customers/{$c->id}")->assertOk()->assertSee('Owing Omar')->assertDontSee('12,345.67');
        $this->get("/customers/{$c->id}/statement")->assertForbidden();
        $this->get('/customer-payments')->assertForbidden();
        $this->get('/customer-payments/create')->assertForbidden();
        $this->get('/receivables')->assertForbidden();
        $this->get('/customers/create')->assertForbidden();
        $this->get("/customers/{$c->id}/edit")->assertForbidden();
        $this->getJson('/pos/customers/search?q=Omar')->assertForbidden();   // no pos.access
    }

    public function test_the_dashboard_tile_shows_what_customers_owe_to_those_who_may_see_it(): void
    {
        $c = $this->named(['allow_credit' => true]);
        $this->credit($c, '750');

        $this->as('Manager')->get('/')->assertOk()->assertSee('Owed by customers')->assertSee('750.00');
        $this->as('Cashier')->get('/')->assertOk()->assertDontSee('Owed by customers');
        $this->as('Technician')->get('/')->assertOk()->assertDontSee('Owed by customers');
    }

    // ---------------------------------------------------------------- the till

    public function test_till_customer_search_hides_money_from_people_who_may_not_see_it(): void
    {
        $c = $this->named(['name' => 'Searchable Sana', 'allow_credit' => true, 'credit_limit' => '9000']);
        $this->credit($c, '400');
        $this->named(['name' => 'Searchable Inactive', 'is_active' => false]);

        $this->as('Cashier');
        $rows = $this->getJson('/pos/customers/search?q=Searchable')->assertOk()->assertJsonCount(1)->json();
        $this->assertSame('Searchable Sana', $rows[0]['name']);
        $this->assertSame('400.00', $rows[0]['balance']);
        $this->assertSame('9000.00', $rows[0]['credit_limit']);
        $this->assertSame('0.00', $rows[0]['advance']);
        $this->assertTrue($rows[0]['allow_credit']);

        $this->asWithOnly(['pos.access', 'customers.view']);
        $rows = $this->getJson('/pos/customers/search?q=Searchable')->assertOk()->json();
        $this->assertSame('Searchable Sana', $rows[0]['name']);
        $this->assertNull($rows[0]['balance']);
        $this->assertNull($rows[0]['credit_limit']);
        $this->assertNull($rows[0]['advance']);

        $this->getJson('/pos/customers/search')->assertStatus(422);
        $this->asWithOnly(['pos.access'])->getJson('/pos/customers/search?q=Sana')->assertForbidden();
        $this->asWithOnly(['customers.view'])->getJson('/pos/customers/search?q=Sana')->assertForbidden();
    }

    public function test_till_search_finds_by_phone_and_code_and_never_lists_the_walk_in_customer(): void
    {
        $c = $this->named(['name' => 'Find Me', 'phone' => '0345-9998887']);
        $this->as('Cashier');

        $this->getJson('/pos/customers/search?q=9998887')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $c->id);
        $this->getJson('/pos/customers/search?q='.$c->code)->assertOk()->assertJsonPath('0.id', $c->id);
        $this->getJson('/pos/customers/search?q=Walk')->assertOk()->assertJsonCount(0);
    }

    public function test_adding_a_customer_at_the_till_never_gives_credit_or_wholesale(): void
    {
        $this->as('Cashier');

        $r = $this->postJson('/pos/customers', ['name' => 'Till Tariq', 'phone' => '0302-4445556', 'allow_credit' => true, 'price_tier' => 'wholesale'])->assertCreated();
        $this->assertSame('Till Tariq', $r->json('name'));
        $this->assertFalse($r->json('allow_credit'));
        $this->assertSame('retail', $r->json('tier'));
        $this->assertFalse(Customer::query()->findOrFail($r->json('id'))->allow_credit);

        $this->postJson('/pos/customers', ['name' => 'Other Tariq', 'phone' => '0302 4445556'])
            ->assertStatus(409)->assertJsonPath('error', 'duplicate_phone');
        $this->postJson('/pos/customers', ['name' => 'Other Tariq', 'phone' => '0302 4445556', 'allow_duplicate_phone' => true])->assertCreated();
        $this->postJson('/pos/customers', ['name' => ''])->assertStatus(422);

        $this->asWithOnly(['pos.access', 'customers.view'])->postJson('/pos/customers', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_the_till_opens_with_a_customer_chosen_on_their_page(): void
    {
        $c = $this->named(['name' => 'Preselected Pari']);
        $gone = $this->named(['name' => 'Inactive Ian', 'is_active' => false]);
        $this->as('Cashier');

        $this->get('/pos?customer='.$c->id)->assertOk()->assertSee('Preselected Pari')->assertSee('creditMethodId')->assertSee('Choose customer');
        $this->get('/pos?customer='.$gone->id)->assertOk()->assertDontSee('Inactive Ian');
        $this->get('/pos?customer='.Customer::walkIn()->id)->assertOk();
        $this->get('/pos')->assertOk()->assertSee('Choose customer');
    }

    public function test_a_credit_sale_through_the_till_reports_what_is_left_on_account(): void
    {
        $c = $this->named(['name' => 'On Account Aslam', 'allow_credit' => true, 'credit_limit' => '10000']);
        $p = $this->stocked('10', '100', ['sale_price' => '500']);
        $this->as('Cashier');

        $r = $this->postJson('/pos/sales', [
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $c->id,
            'lines' => [['product_id' => $p->id, 'quantity' => '2']],
            'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '300']],
        ])->assertCreated();

        $this->assertSame('700.00', $r->json('due'));
        $this->assertSame('On Account Aslam', $r->json('customer'));
        $this->assertSame('700.00', (string) $c->fresh()->getRawOriginal('balance'));
    }

    public function test_the_walk_in_customer_and_a_cash_only_customer_cannot_leave_a_balance(): void
    {
        $cashOnly = $this->named(['name' => 'Cash Only Carl', 'allow_credit' => false]);
        $p = $this->stocked('10', '100', ['sale_price' => '500']);
        $this->as('Cashier');

        $short = fn (array $over) => $this->postJson('/pos/sales', $over + [
            'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $p->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '100']],
        ]);

        $this->assertStringContainsString('walk-in customer must pay in full', $short([])->assertStatus(422)->json('message'));
        $this->assertStringContainsString('is not allowed credit', $short(['customer_id' => $cashOnly->id])->assertStatus(422)->json('message'));
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_cashier_needs_a_manager_to_go_over_the_credit_limit(): void
    {
        $c = $this->named(['allow_credit' => true, 'credit_limit' => '400']);
        $p = $this->stocked('10', '100', ['sale_price' => '500']);
        $this->as('Cashier');

        $r = $this->postJson('/pos/sales', [
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $c->id,
            'lines' => [['product_id' => $p->id, 'quantity' => '1']], 'payments' => [],
        ])->assertStatus(409);

        $this->assertSame('approval_required', $r->json('error'));
        $this->assertContains('credit_limit', collect($r->json('reasons'))->pluck('code')->all());
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_sale_pages_link_to_the_customer_and_offer_to_collect_what_is_due(): void
    {
        $c = $this->named(['name' => 'Linked Lina', 'allow_credit' => true]);
        $owing = $this->credit($c, '600');
        $this->as('Manager');

        $this->get('/sales')->assertOk()->assertSee(route('customers.show', $c), false);
        $this->get("/sales/{$owing->id}")->assertOk()->assertSee('Collect payment')->assertSee(route('customers.show', $c), false);
    }
}
