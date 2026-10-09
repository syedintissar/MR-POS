<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Services\Service\ServiceJobAccess;
use App\Services\Service\ServiceJobService;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Puts a part from stock on a job (SRV-05). The stock leaves at once, at the average cost of the moment (`service_consumption`), the cost
 * is written on the line for the profit figures, and the job's totals are recomputed, all in one transaction. The line is billed at the
 * product's price (the wholesale price for a wholesale customer) unless someone allowed to set charges types another price.
 */
class AddServicePart
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ServiceJobService $jobs,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{product_id: int, quantity: string, unit_price?: string|null, discount_amount?: string|null}  $d
     */
    public function execute(ServiceJob $job, array $d, User $by): ServiceJobItem
    {
        $quantity = Decimal::round((string) ($d['quantity'] ?? '0'), 3);
        if (! Decimal::isPositive($quantity)) {
            throw new DomainException('Enter a quantity greater than zero.');
        }

        return DB::transaction(function () use ($job, $d, $by, $quantity) {
            // job first, then the product: the same order every service operation uses
            $locked = ServiceJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! ServiceJobAccess::can($by, 'service_jobs.manage_parts', $locked)) {
                throw new AuthorizationException('You are not allowed to add parts to this job.');
            }
            if ($locked->isTerminal()) {
                throw new DomainException("{$locked->job_no} is {$locked->statusLabel()} and can no longer be changed.");
            }
            if (! $locked->acceptsParts()) {
                throw new DomainException("Parts can be added once the job is being diagnosed. {$locked->job_no} is \"{$locked->statusLabel()}\".");
            }

            $products = $this->inventory->lockProducts([(int) $d['product_id']]);
            $product = $products[(int) $d['product_id']] ?? null;
            if (! $product || $product->trashed() || ! $product->is_active) {
                throw new DomainException('That product is not available.');
            }
            if (! $product->isStockItem()) {
                throw new DomainException("\"{$product->name}\" is a service item with no stock. Add it as a charge instead.");
            }

            $list = $locked->customer->price_tier === 'wholesale' && $product->wholesale_price !== null ? (string) $product->wholesale_price : (string) $product->sale_price;
            $given = $d['unit_price'] ?? null;
            $price = ($given === null || $given === '') ? $list : Decimal::round((string) $given, 2);
            if (Decimal::isNegative($price)) {
                throw new DomainException('A price cannot be negative.');
            }
            if (Decimal::cmp($price, $list) !== 0 && ! $by->can('service_jobs.set_charges')) {
                throw new AuthorizationException('You are not allowed to change the price of a part.');
            }

            $discount = Decimal::round((string) ($d['discount_amount'] ?? '0'), 2);
            $gross = Decimal::mul($quantity, $price, 2);
            if (Decimal::isNegative($discount) || Decimal::cmp($discount, $gross) > 0) {
                throw new DomainException('The discount cannot be more than the price of the part.');
            }
            if (Decimal::isPositive($discount) && ! $by->can('service_jobs.set_charges')) {
                throw new AuthorizationException('You are not allowed to give a discount.');
            }

            $taxOn = $this->settings->bool('tax.enabled');
            $rate = $taxOn ? ($product->tax_rate !== null ? (string) $product->tax_rate : (string) $this->settings->get('tax.default_rate')) : null;

            $item = new ServiceJobItem;
            $item->forceFill([
                'service_job_id' => $locked->id, 'line_type' => ServiceJobItem::PART, 'product_id' => $product->id,
                'description' => mb_substr($product->name, 0, 255), 'quantity' => $quantity, 'unit_price' => $price, 'unit_cost' => '0',
                'discount_amount' => $discount, 'tax_rate' => $rate, 'line_total' => Decimal::sub($gross, $discount, 2),
                'status' => ServiceJobItem::ACTIVE, 'added_by' => $by->id, 'added_at' => now(),
            ])->save();

            $movement = $this->inventory->move($product, Decimal::neg($quantity, 3), 'service_consumption', [
                'source' => $locked, 'source_line_id' => $item->id, 'notes' => "Part for service job {$locked->job_no}", 'user' => $by,
            ]);
            $item->forceFill(['unit_cost' => $movement->unit_cost])->save();

            $locked->forceFill(['updated_by' => $by->id])->save();
            $this->jobs->refresh($locked);

            $this->audit->log('service_part_added', 'service', "Service job {$locked->job_no}: {$product->name} × {$quantity} added", $locked, new: [
                'product' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'unit_price' => $price, 'discount' => $discount,
            ], user: $by);

            return $item->refresh();
        }, 3);
    }
}
