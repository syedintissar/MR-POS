<?php

namespace Tests\Feature\Quotations;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\Sale;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** Quotations: a price offer that touches nothing until it is sold through the till. */
class QuotationTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private function payload(array $over = []): array
    {
        return $over + [
            'customer_name' => 'City School Office', 'customer_phone' => '0300-1234567',
            'quote_date' => now()->toDateString(), 'valid_until' => now()->addDays(7)->toDateString(),
        ];
    }

    public function test_a_quotation_is_written_printed_edited_and_cancelled_without_touching_stock(): void
    {
        $printer = $this->stocked('5', '20000', ['name' => 'HP LaserJet 1020', 'sale_price' => '32000']);
        $this->as('Manager');

        $this->get('/quotations')->assertOk()->assertSee('New quotation');
        $this->get('/quotations/create')->assertOk()->assertSee('What is offered');

        $this->post('/quotations', $this->payload(['discount_amount' => '500', 'notes' => "Delivery in 3 days\nWarranty 1 year", 'lines' => [
            ['product_id' => $printer->id, 'description' => '', 'quantity' => '2', 'unit_price' => '30000'],
            ['product_id' => '', 'description' => 'Installation at site', 'quantity' => '1', 'unit_price' => '1500'],
        ]]))->assertSessionHasNoErrors()->assertRedirect();

        $q = Quotation::query()->with('items')->sole();
        $this->assertSame('QTN-000001', $q->quotation_no);
        $this->assertSame('open', $q->status);
        $this->assertSame('61500.00', (string) $q->subtotal);
        $this->assertSame('61000.00', (string) $q->grand_total);
        $this->assertSame('HP LaserJet 1020', $q->items[0]->description);
        $this->assertNull($q->items[1]->product_id);
        $this->assertSame('5.000', $printer->fresh()->current_stock, 'a quotation reserves nothing');

        $html = $this->get("/quotations/{$q->id}")->assertOk()->assertSee('Customer accepted: open in the till')->assertSee('1 item(s) were typed in')->getContent();
        $this->assertStringContainsString('https://wa.me/923001234567?text=', $html);
        $this->get("/quotations/{$q->id}/print")->assertOk()->assertSee('QUOTATION')->assertSee('City School Office')->assertSee('61,000.00')->assertSee('Installation at site')->assertSee('Warranty 1 year');

        $this->get("/quotations/{$q->id}/edit")->assertOk()->assertSee('HP LaserJet 1020');

        // nobody and nothing offered: refused
        $this->post('/quotations', ['quote_date' => now()->toDateString(), 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '1']]])->assertSessionHasErrors('customer_name');
        $this->post('/quotations', $this->payload(['lines' => [['product_id' => '', 'description' => '', 'quantity' => '1', 'unit_price' => '1']]]))->assertSessionHasErrors('lines.0.description');
        $this->post('/quotations', $this->payload(['discount_amount' => '99999', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10']]]))->assertSessionHasErrors('discount_amount');

        $this->put("/quotations/{$q->id}", $this->payload(['lines' => [['product_id' => $printer->id, 'description' => 'HP LaserJet 1020', 'quantity' => '1', 'unit_price' => '31000']]]))->assertSessionHasNoErrors();
        $this->assertSame('31000.00', (string) $q->fresh()->grand_total);
        $this->assertSame(1, $q->fresh()->items()->count());

        // past its date it shows as expired, and is found under that filter
        $q->forceFill(['quote_date' => now()->subDays(20)->toDateString(), 'valid_until' => now()->subDays(10)->toDateString()])->save();
        $this->get('/quotations?status=expired')->assertOk()->assertSee($q->quotation_no)->assertSee('Expired');
        $this->get('/quotations?status=open')->assertOk()->assertDontSee($q->quotation_no);

        $this->post("/quotations/{$q->id}/cancel")->assertSessionHas('success');
        $this->assertSame('cancelled', $q->fresh()->status);
        $this->post("/quotations/{$q->id}/cancel")->assertSessionHas('error');
        $this->get("/quotations/{$q->id}/edit")->assertRedirect();
        $this->get('/pos?quotation='.$q->id)->assertOk()->assertDontSee('left_out');
    }

    public function test_an_accepted_quotation_opens_in_the_till_with_its_prices_and_is_marked_sold(): void
    {
        $toner = $this->stocked('10', '2000', ['name' => 'Toner 85A', 'sale_price' => '4500']);
        $customer = Customer::factory()->create(['name' => 'Office Omar', 'allow_credit' => true]);
        $this->as('Manager');

        $this->post('/quotations', ['customer_id' => $customer->id, 'quote_date' => now()->toDateString(), 'discount_amount' => '200', 'lines' => [
            ['product_id' => $toner->id, 'description' => 'Toner 85A', 'quantity' => '3', 'unit_price' => '4000'],
            ['product_id' => '', 'description' => 'Typed thing', 'quantity' => '1', 'unit_price' => '50'],
        ]])->assertSessionHasNoErrors();
        $q = Quotation::query()->sole();
        $this->assertSame('Office Omar', $q->customer_name);

        // the till receives the quotation as a cart
        $html = $this->get('/pos?quotation='.$q->id)->assertOk()->getContent();
        $this->assertStringContainsString('QTN-000001', $html);
        $this->assertMatchesRegularExpression('/quoted.{1,20}4000\.00/', $html);
        $this->assertMatchesRegularExpression('/left_out.{1,10}1/', $html);

        // ...and the sale it sends back marks the quotation accepted
        $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
        $r = $this->postJson('/pos/sales', [
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'quotation_id' => $q->id, 'discount_amount' => '200',
            'lines' => [['product_id' => $toner->id, 'quantity' => '3', 'unit_price' => '4000']],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => '11800']],
        ])->assertCreated();

        $sale = Sale::query()->findOrFail($r->json('sale_id'));
        $this->assertSame('11800.00', (string) $sale->grand_total);
        $q->refresh();
        $this->assertSame('accepted', $q->status);
        $this->assertSame($sale->id, $q->sale_id);
        $this->assertSame('7.000', $toner->fresh()->current_stock);

        $this->get("/quotations/{$q->id}")->assertOk()->assertSee('Sold as')->assertSee($sale->invoice_no)->assertDontSee('Customer accepted: open in the till');
        $this->get('/quotations?status=accepted')->assertOk()->assertSee($q->quotation_no);
        $this->get('/pos?quotation='.$q->id)->assertOk()->assertDontSee('left_out');
    }

    public function test_who_may_see_and_write_quotations(): void
    {
        $p = $this->stocked('5', '10', ['sale_price' => '100']);
        $lines = ['lines' => [['product_id' => $p->id, 'description' => 'Thing', 'quantity' => '1', 'unit_price' => '100']]];

        $this->as('Cashier');
        $this->post('/quotations', $this->payload($lines))->assertRedirect();
        $mine = Quotation::query()->sole();
        $this->get("/quotations/{$mine->id}")->assertOk();

        // another cashier sees only their own; a technician none at all
        $this->as('Cashier');
        $this->get("/quotations/{$mine->id}")->assertForbidden();
        $this->get('/quotations?status=all')->assertOk()->assertDontSee($mine->quotation_no);
        $this->as('Technician');
        $this->get('/quotations')->assertForbidden();
        $this->post('/quotations', $this->payload($lines))->assertForbidden();

        $this->as('Manager');
        $this->get('/quotations?status=all')->assertOk()->assertSee($mine->quotation_no);
    }
}
