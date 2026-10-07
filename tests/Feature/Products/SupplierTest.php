<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    public function test_a_manager_adds_a_supplier_with_an_automatic_code_and_it_is_audited(): void
    {
        $this->as('Manager');

        $this->post('/suppliers', ['name' => 'Al-Noor Traders', 'company' => 'Al-Noor Pvt Ltd', 'phone' => '0300-1234567', 'email' => 'sales@alnoor.test'])->assertRedirect();

        $s = Supplier::query()->firstOrFail();
        $this->assertSame('SUP-000001', $s->code);
        $this->assertTrue($s->is_active);
        $this->assertSame('0.00', $s->balance);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supplier_created', 'auditable_id' => $s->id]);

        $this->post('/suppliers', ['name' => 'Second']);
        $this->assertSame('SUP-000002', Supplier::query()->where('name', 'Second')->value('code'));
    }

    public function test_validation(): void
    {
        $this->as('Manager');

        $this->post('/suppliers', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/suppliers', ['name' => 'X', 'email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->post('/suppliers', ['name' => str_repeat('a', 151)])->assertSessionHasErrors('name');
        $this->assertSame(0, Supplier::count());
    }

    public function test_balances_cannot_be_typed_in_because_they_come_from_the_ledger_later(): void
    {
        $this->as('Manager');

        $this->post('/suppliers', ['name' => 'Sneaky', 'balance' => '5000', 'opening_balance' => '5000']);

        $s = Supplier::query()->firstOrFail();
        $this->assertSame('0.00', $s->balance);
        $this->assertSame('0.00', $s->opening_balance);
    }

    public function test_editing_audits_only_what_changed(): void
    {
        $this->as('Manager');
        $s = Supplier::factory()->create(['name' => 'Old Name', 'phone' => '111']);

        $this->put("/suppliers/{$s->id}", ['name' => 'New Name', 'phone' => '111'])->assertRedirect();

        $row = DB::table('audit_logs')->where('event', 'supplier_updated')->latest('id')->first();
        $this->assertSame(['name' => 'Old Name'], json_decode($row->old_values, true));
        $this->assertSame(['name' => 'New Name'], json_decode($row->new_values, true));
    }

    public function test_search_and_status_filters(): void
    {
        $this->as('Manager');
        Supplier::factory()->create(['name' => 'Karachi Toner House', 'phone' => '021-111']);
        Supplier::factory()->create(['name' => 'Lahore Paper Mart']);
        Supplier::factory()->create(['name' => 'Retired Supplier', 'is_active' => false]);

        $this->get('/suppliers?q=toner')->assertSee('Karachi Toner House')->assertDontSee('Lahore Paper Mart');
        $this->get('/suppliers?q=021-111')->assertSee('Karachi Toner House');
        $this->get('/suppliers')->assertDontSee('Retired Supplier');
        $this->get('/suppliers?status=inactive')->assertSee('Retired Supplier')->assertDontSee('Lahore Paper Mart');
        $this->get('/suppliers?status=all')->assertSee('Retired Supplier')->assertSee('Lahore Paper Mart');
    }

    public function test_deactivating_and_reactivating(): void
    {
        $this->as('Manager');
        $s = Supplier::factory()->create();

        $this->post("/suppliers/{$s->id}/toggle");
        $this->assertFalse($s->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supplier_deactivated']);
        $this->post("/suppliers/{$s->id}/toggle");
        $this->assertTrue($s->fresh()->is_active);
    }

    public function test_a_supplier_used_by_a_product_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->as('Admin');   // deleting suppliers is an Admin permission
        $used = Supplier::factory()->create();
        $free = Supplier::factory()->create();
        Product::factory()->create(['default_supplier_id' => $used->id]);
        $this->withSession($this->confirmed());

        $this->delete("/suppliers/{$used->id}")->assertSessionHas('error');
        $this->assertNotNull(Supplier::find($used->id));

        $this->delete("/suppliers/{$free->id}")->assertRedirect('/suppliers');
        $this->assertNull(Supplier::find($free->id));
        $this->assertNotNull(Supplier::withTrashed()->find($free->id), 'A soft delete.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'supplier_deleted']);
    }

    public function test_deleting_asks_for_the_password_again(): void
    {
        $this->as('Admin');
        $s = Supplier::factory()->create();

        $this->delete("/suppliers/{$s->id}")->assertRedirect(route('password.confirm'));
        $this->assertNotNull(Supplier::find($s->id));
    }

    public function test_who_can_do_what(): void
    {
        $s = Supplier::factory()->create();

        foreach (['Cashier', 'Technician'] as $role) {
            $this->as($role);
            $this->get('/suppliers')->assertForbidden();
            $this->get('/suppliers/create')->assertForbidden();
            $this->post('/suppliers', ['name' => 'x'])->assertForbidden();
            $this->get("/suppliers/{$s->id}/edit")->assertForbidden();
            $this->put("/suppliers/{$s->id}", ['name' => 'x'])->assertForbidden();
            $this->post("/suppliers/{$s->id}/toggle")->assertForbidden();
        }

        $this->as('Manager');
        $this->get('/suppliers')->assertOk();
        $this->get("/suppliers/{$s->id}/edit")->assertOk()->assertSee($s->code);

        // managers may add and edit suppliers but not delete them
        $this->as('Manager');
        $this->withSession($this->confirmed())->delete("/suppliers/{$s->id}")->assertForbidden();
        $this->asWithOnly(['suppliers.view', 'suppliers.edit']);
        $this->withSession($this->confirmed())->delete("/suppliers/{$s->id}")->assertForbidden();
    }

    public function test_the_edit_screen_of_a_supplier_that_does_not_exist_is_a_404(): void
    {
        $this->as('Admin')->get('/suppliers/99999/edit')->assertNotFound();
    }
}
