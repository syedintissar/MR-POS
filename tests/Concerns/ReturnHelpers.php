<?php

namespace Tests\Concerns;

use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\CreateSaleReturn;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\Purchasing\SupplierAccountService;
use App\Services\Sales\SaleAccountService;
use Illuminate\Support\Str;

/** Small helpers for the tests of returns, exchanges and supplier credit: a manager sells, takes goods back and buys. */
trait ReturnHelpers
{
    use MakesStock;

    protected User $manager;

    protected Supplier $supplier;

    protected function makeManager(): void
    {
        $this->manager = User::factory()->withRole('Manager')->create();
        $this->supplier = Supplier::factory()->create();
    }

    protected function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    protected function item(string $price = '150', string $qty = '50', string $cost = '100', array $attrs = []): Product
    {
        return $this->stocked($qty, $cost, ['sale_price' => $price] + $attrs);
    }

    protected function named(): Customer
    {
        return Customer::factory()->create(['allow_credit' => true]);
    }

    /** A sale of lines [[product, qty], ...]; $pay null = paid in cash, [] = all on account, or [[method code, amount]]. */
    protected function sell(array $lines, ?array $pay = null, ?Customer $c = null, array $over = []): Sale
    {
        $payments = $pay === null
            ? [['payment_method_id' => $this->method()->id, 'amount' => '100000']]
            : array_map(fn ($x) => ['payment_method_id' => $this->method($x[0])->id, 'amount' => $x[1]], $pay);

        return app(CompleteSale::class)->execute($over + [
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $c?->id,
            'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines), 'payments' => $payments,
        ], $this->manager);
    }

    /** Takes goods back: $perItem is [position of the line on the invoice => ['quantity' => ..., ...]]. */
    protected function giveBack(Sale $sale, array $perItem, array $settle = [], string $reason = 'Faulty', ?User $by = null): SaleReturn
    {
        $items = $sale->items()->orderBy('id')->get();
        $lines = [];
        foreach ($perItem as $pos => $l) {
            $lines[] = ['sale_item_id' => $items[$pos]->id] + $l;
        }

        return app(CreateSaleReturn::class)->execute($sale->fresh(), $lines, $settle, $reason, $by ?? $this->manager);
    }

    /** A received purchase for one product; $paid pays it in full at receiving. */
    protected function bill(string $qty, string $cost, bool $paid = false, ?Product $product = null, array $over = []): Purchase
    {
        $product ??= Product::factory()->create();
        $draft = app(SavePurchaseDraft::class)->execute($over + [
            'supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost]],
        ], $this->manager);

        return app(ReceivePurchase::class)->execute($draft, $this->manager, $paid ? ['payment_method_id' => $this->method()->id, 'amount' => (string) $draft->grand_total] : null);
    }

    protected function sendBack(Purchase $p, string $qty, array $over = []): PurchaseReturn
    {
        return app(CreatePurchaseReturn::class)->execute($over + [
            'purchase_id' => $p->id, 'lines' => [['purchase_item_id' => $p->items()->orderBy('id')->first()->id, 'quantity' => $qty]], 'reason' => 'Faulty batch',
        ], $this->manager);
    }

    protected function customerBalance(Customer $c): string
    {
        return (string) $c->fresh()->getRawOriginal('balance');
    }

    protected function supplierBalance(): string
    {
        return (string) $this->supplier->fresh()->getRawOriginal('balance');
    }

    protected function assertAllBooksAgree(): void
    {
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
        $this->assertSame([], app(SaleAccountService::class)->findDrift());
        $this->assertSame([], app(SupplierAccountService::class)->findDrift());
        $this->assertSame([], $this->inventory()->findDrift());
    }
}
