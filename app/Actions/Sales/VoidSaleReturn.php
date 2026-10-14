<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SaleAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a sales return that was made by mistake: the restocked units leave the stock again (refused when they have been sold meanwhile),
 * the customer's account, the refund and the store credit are put back, and the invoice owes what it owed before. Store credit that the
 * customer has already spent cannot be taken back, so that cancellation is refused. Nothing is deleted.
 */
class VoidSaleReturn
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly SaleAccountService $sales,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(SaleReturn $return, string $reason, User $by): SaleReturn
    {
        if (! $by->can('sales.void')) {
            throw new AuthorizationException('You are not allowed to cancel returns.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the return.');
        }

        $customerId = SaleReturn::query()->whereKey($return->getKey())->value('customer_id');
        if ($customerId === null) {
            throw new DomainException('This return no longer exists.');
        }
        $walkInId = Customer::walkIn()->id;   // read before the transaction: the customer lock must be its first read

        return DB::transaction(function () use ($return, $reason, $by, $customerId, $walkInId) {
            $customer = (int) $customerId === $walkInId ? Customer::query()->findOrFail($walkInId) : $this->accounts->lockCustomer((int) $customerId);
            $locked = SaleReturn::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException("{$locked->return_no} has already been cancelled.");
            }
            $sale = Sale::query()->whereKey($locked->sale_id)->lockForUpdate()->firstOrFail();
            $credited = Decimal::sub((string) $locked->total, (string) $locked->restocking_fee, 2);   // what had gone back to the customer
            if ($locked->exchange_sale_id && Sale::query()->whereKey($locked->exchange_sale_id)->where('status', Sale::COMPLETED)->exists()) {
                throw new DomainException("{$locked->return_no} is part of an exchange: cancel the replacement invoice first.");
            }
            $rows = $locked->items()->orderBy('id')->get();
            SaleItem::query()->whereIn('id', $rows->pluck('sale_item_id'))->orderBy('id')->lockForUpdate()->get();

            // store credit must still be there to take back
            $credit = $locked->creditPayment;
            if ($credit && $credit->status === 'posted') {
                $free = collect($this->accounts->advanceReceipts($credit->customer_id))->firstWhere('id', $credit->id)['free'] ?? '0.00';
                if (Decimal::cmp((string) $free, (string) $credit->amount) < 0) {
                    throw new DomainException("The store credit of {$credit->amount} from {$locked->return_no} has already been spent (or handed back), so the return cannot be cancelled.");
                }
            }

            $this->inventory->lockProducts($rows->where('restock', true)->pluck('product_id')->all());
            foreach ($rows->where('restock', true) as $row) {
                $this->inventory->move($row->product_id, Decimal::neg((string) $row->quantity, 3), 'sale_return_void', [
                    'unit_cost' => (string) $row->unit_cost, 'source' => $locked, 'source_line_id' => $row->id, 'notes' => "Return {$locked->return_no} cancelled", 'user' => $by,
                ]);
            }
            foreach ($rows as $row) {
                $item = SaleItem::query()->findOrFail($row->sale_item_id);
                $item->forceFill(['returned_quantity' => Decimal::nonNegative(Decimal::sub((string) $item->returned_quantity, (string) $row->quantity, 3), 3)])->save();
            }
            $sale->forceFill(['returned_amount' => Decimal::nonNegative(Decimal::sub((string) $sale->returned_amount, $credited, 2), 2)])->save();

            $locked->forceFill(['status' => SaleReturn::VOIDED, 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();
            $this->sales->refreshSale($sale);

            if (! $customer->is_walk_in) {
                $this->accounts->postEntry($customer, 'sale_return_void', $credited, '0', $locked, "Return {$locked->return_no} cancelled: {$reason}", $by);
            }
            if ($locked->refundPayment && $locked->refundPayment->status === 'posted') {
                $locked->refundPayment->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => 'Return cancelled'])->save();
                if (! $customer->is_walk_in) {
                    $this->accounts->postEntry($customer, 'refund_void', '0', (string) $locked->refundPayment->amount, $locked->refundPayment, "Refund {$locked->refundPayment->receipt_no} cancelled with {$locked->return_no}", $by);
                }
            }
            if ($credit && $credit->status === 'posted') {
                $credit->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => 'Return cancelled'])->save();
            }

            $this->audit->log('sale_return_voided', 'sales', "Return {$locked->return_no} cancelled: {$reason}", $locked, new: ['reason' => $reason, 'total' => (string) $locked->total, 'invoice' => $sale->invoice_no], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
