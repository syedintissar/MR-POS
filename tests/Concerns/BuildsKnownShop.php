<?php

namespace Tests\Concerns;

use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\Actions\Inventory\PostStockAdjustment;
use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\CreateSaleReturn;
use App\Actions\Sales\VoidSale;
use App\Actions\Service\AddServiceCharge;
use App\Actions\Service\AddServicePart;
use App\Actions\Service\CancelServiceJob;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Actions\Service\DeliverServiceJob;
use App\Actions\Service\ReceiveServiceJob;
use App\Actions\Service\RemoveServiceLine;
use App\Actions\Service\UpdateServicePricing;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceJob;
use App\Models\ServiceType;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\ProfitService;
use Illuminate\Support\Str;

/** A small shop whose every figure is known by hand: sales, returns, service jobs, expenses, stock losses and a supplier return. */
trait BuildsKnownShop
{
    use MakesStock;

    protected User $manager;

    protected User $tech;

    /** Call from setUp(): the manager and the technician every scenario uses. */
    protected function makeStaff(): void
    {
        $this->manager = User::factory()->withRole('Manager')->create();
        $this->tech = User::factory()->withRole('Technician')->create();
    }

    protected function cash(): int
    {
        return PaymentMethod::query()->where('code', 'cash')->value('id');
    }

    protected function profit(): ProfitService
    {
        return app(ProfitService::class);
    }

    protected function today(): string
    {
        return now()->toDateString();
    }

    protected function sell(array $lines, array $over = []): Sale
    {
        return app(CompleteSale::class)->execute($over + [
            'idempotency_key' => (string) Str::uuid(), 'lines' => $lines, 'payments' => [['payment_method_id' => $this->cash(), 'amount' => '100000']],
        ], $this->manager);
    }

    /** A job with one charge (and optionally parts), delivered for cash; returns the delivered job. */
    protected function deliveredJob(string $charge, array $parts = [], string $discount = '0'): ServiceJob
    {
        $job = $this->openJob();
        $job = app(ChangeServiceJobStatus::class)->execute($job, 'diagnosing', $this->manager);
        $job = app(ChangeServiceJobStatus::class)->execute($job, 'in_progress', $this->manager);
        if ($charge !== '0') {
            app(AddServiceCharge::class)->execute($job, ['description' => 'Repair', 'unit_price' => $charge], $this->manager);
        }
        foreach ($parts as [$product, $qty]) {
            app(AddServicePart::class)->execute($job->fresh(), ['product_id' => $product->id, 'quantity' => $qty], $this->tech);
        }
        if ($discount !== '0') {
            app(UpdateServicePricing::class)->execute($job->fresh(), ['discount_amount' => $discount], $this->manager);
        }
        $job = app(ChangeServiceJobStatus::class)->execute($job->fresh(), 'completed', $this->manager);

        return app(DeliverServiceJob::class)->execute($job->fresh(), [['payment_method_id' => $this->cash(), 'amount' => (string) $job->fresh()->grand_total]], $this->manager);
    }

    protected function openJob(): ServiceJob
    {
        return app(ReceiveServiceJob::class)->execute([
            'contact_name' => 'Mr Ali', 'contact_phone' => '0300-0000000', 'service_type_id' => ServiceType::query()->where('name', 'Printer repair')->value('id'),
            'device_type' => 'printer', 'reported_problem' => 'Does not print', 'technician_id' => $this->tech->id,
        ], $this->manager);
    }

    protected function expense(string $amount, string $category = 'Electricity', ?string $date = null)
    {
        return app(SaveExpense::class)->execute([
            'expense_category_id' => ExpenseCategory::query()->where('name', $category)->value('id'), 'expense_date' => $date ?? $this->today(),
            'amount' => $amount, 'payment_method_id' => $this->cash(), 'description' => 'Bill',
        ], $this->manager);
    }

    /** The whole shop, with every number known (see the test below for the arithmetic). */
    protected function dataset(): void
    {
        $a = $this->stocked('50', '100', ['sale_price' => '150']);
        $b = $this->stocked('10', '400', ['sale_price' => '600']);
        $c = $this->stocked('10', '100', ['sale_price' => '250']);        // a part used on a job
        $f = $this->stocked('10', '40', ['sale_price' => '100']);         // a part that gets written off

        // goods sold: 300 (cost 200) and 600 less a 60 discount = 540 (cost 400); a third sale is cancelled
        $s1 = $this->sell([['product_id' => $a->id, 'quantity' => '2']]);
        $this->sell([['product_id' => $b->id, 'quantity' => '1']], ['discount_amount' => '60']);
        app(VoidSale::class)->execute($this->sell([['product_id' => $a->id, 'quantity' => '5']]), 'Mistake', $this->manager);

        // returns of the first sale: one unit goes back on the shelf, one is damaged
        $items = $s1->items()->orderBy('id')->get();
        app(CreateSaleReturn::class)->execute($s1, [['sale_item_id' => $items[0]->id, 'quantity' => '1', 'condition' => 'resellable', 'restock' => true]], [], 'Changed mind', $this->manager);
        app(CreateSaleReturn::class)->execute($s1->fresh(), [['sale_item_id' => $items[0]->id, 'quantity' => '1', 'condition' => 'damaged', 'restock' => false]], [], 'Broken', $this->manager);

        // service: one job delivered (charge 500, part 250 costing 100, 50 off = 700), one cancelled, one with a part written off
        $this->deliveredJob('500', [[$c, '1']], '50');
        $cancelled = $this->openJob();
        app(AddServiceCharge::class)->execute(app(ChangeServiceJobStatus::class)->execute(app(ChangeServiceJobStatus::class)->execute($cancelled, 'diagnosing', $this->manager), 'in_progress', $this->manager), ['description' => 'Repair', 'unit_price' => '900'], $this->manager);
        app(CancelServiceJob::class)->execute($cancelled->fresh(), 'Declined', $this->manager);
        $open = app(ChangeServiceJobStatus::class)->execute($this->openJob(), 'diagnosing', $this->manager);
        $line = app(AddServicePart::class)->execute($open, ['product_id' => $f->id, 'quantity' => '1'], $this->tech);
        app(RemoveServiceLine::class)->execute($line, 'written_off', 'Burnt out', $this->manager);

        // expenses: 300 counts, the 200 one is cancelled
        $this->expense('300');
        app(VoidExpense::class)->execute($this->expense('200', 'Rent'), 'Twice', $this->manager);

        // stock: the opening stock of a new product is not a loss; two units of A are lost (cost 100 each)
        $d = Product::factory()->create();
        app(PostStockAdjustment::class)->execute(['reason' => 'opening', 'lines' => [['product_id' => $d->id, 'quantity' => '10', 'unit_cost' => '50']]], $this->manager);
        app(PostStockAdjustment::class)->execute(['reason' => 'loss', 'lines' => [['product_id' => $a->id, 'quantity' => '-2']]], $this->manager);

        // goods sent back to a supplier: credit 2 x 100 = 200, but the stock left at its landed cost of 110 = 220
        $e = Product::factory()->create();
        $supplier = Supplier::factory()->create();
        $bill = app(ReceivePurchase::class)->execute(app(SavePurchaseDraft::class)->execute([
            'supplier_id' => $supplier->id, 'purchase_date' => $this->today(), 'additional_cost' => '100', 'lines' => [['product_id' => $e->id, 'quantity' => '10', 'unit_cost' => '100']],
        ], $this->manager), $this->manager);
        app(CreatePurchaseReturn::class)->execute(['purchase_id' => $bill->id, 'lines' => [['purchase_item_id' => $bill->items->first()->id, 'quantity' => '2']], 'reason' => 'Faulty'], $this->manager);
    }
}
