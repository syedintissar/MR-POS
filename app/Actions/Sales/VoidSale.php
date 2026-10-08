<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SaleAccountService;
use App\Services\SettingsService;
use App\Support\Decimal;
use App\Support\PermissionCatalogue;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a completed sale by writing the opposite entries (nothing is deleted or edited): the goods go back into stock at the
 * cost they left at, and the payments taken for the sale are cancelled (the money is given back). A named customer's account gets the
 * opposite lines, and money they had paid in advance that this sale used becomes their advance again. Allowed only inside the void
 * window (an administrator may go beyond it) and only while nothing has been returned.
 *
 * Lock order: customer (named only) -> the sale -> its products -> audit (the same order as CompleteSale and the payment actions).
 */
class VoidSale
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SaleAccountService $accounts,
        private readonly CustomerAccountService $customers,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Sale $sale, string $reason, User $by): Sale
    {
        if (! $by->can('sales.void')) {
            throw new AuthorizationException('You are not allowed to void sales.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the sale.');
        }

        $customerId = Sale::query()->whereKey($sale->getKey())->value('customer_id');
        if ($customerId === null) {
            throw new DomainException('This sale no longer exists.');
        }

        $walkInId = Customer::walkIn()->id;   // read before the transaction: the customer lock must be its first read (see CompleteSale::customer())

        return DB::transaction(function () use ($sale, $customerId, $walkInId, $reason, $by) {
            // the customer first (a named customer's account is also changed by payments and new sales), then the sale
            $customer = (int) $customerId === $walkInId ? null : $this->customers->lockCustomer((int) $customerId);
            $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException("{$locked->invoice_no} has already been cancelled.");
            }

            $items = $locked->items()->with('product')->orderBy('product_id')->orderBy('id')->get();
            if (Decimal::isPositive((string) $locked->returned_amount) || $items->contains(fn ($i) => Decimal::isPositive((string) $i->returned_quantity))) {
                throw new DomainException("{$locked->invoice_no} has goods returned, so it cannot be cancelled. Cancel the return first.");
            }

            $hours = (int) $this->settings->get('sales.void_window_hours');
            $isAdmin = $by->hasRole(PermissionCatalogue::ADMIN);
            if (! $isAdmin && now()->greaterThan($locked->sale_date->copy()->addHours($hours))) {
                throw new DomainException("{$locked->invoice_no} is older than the void window ({$hours} hours). Use a sales return instead, or ask an administrator.");
            }

            // the goods come back at the cost they left at
            $this->inventory->lockProducts($items->pluck('product_id')->all());
            foreach ($items as $item) {
                if (! $item->product->isStockItem()) {
                    continue;
                }
                $this->inventory->move($item->product_id, (string) $item->quantity, 'sale_void', [
                    'unit_cost' => (string) $item->unit_cost,
                    'source' => $locked,
                    'source_line_id' => $item->id,
                    'notes' => "Cancelled {$locked->invoice_no}: {$reason}",
                    'user' => $by,
                ]);
            }

            // the invoice comes off a named customer's account
            if ($customer && Decimal::isPositive((string) $locked->grand_total)) {
                $this->customers->postEntry($customer, 'sale_void', '0', (string) $locked->grand_total, $locked, "Sale {$locked->invoice_no} cancelled: {$reason}", $by);
            }

            // the money taken at the till for THIS sale is given back: its own receipts are cancelled. (A payment collected later from the
            // customer, which settled this invoice among others, stays; the part that settled this invoice becomes their advance again.)
            $refunded = '0.00';
            foreach (CustomerPayment::query()->where('sale_id', $locked->id)->where('status', 'posted')->orderBy('id')->lockForUpdate()->get() as $payment) {
                $payment->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr("Sale {$locked->invoice_no} cancelled: {$reason}", 0, 255)])->save();
                $refunded = Decimal::add($refunded, (string) $payment->amount, 2);
                if ($customer) {
                    $this->customers->postEntry($customer, 'payment_void', (string) $payment->amount, '0', $payment, "Payment {$payment->receipt_no} cancelled with {$locked->invoice_no}", $by);
                }
            }

            $locked->forceFill(['status' => Sale::VOIDED, 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();
            $this->accounts->refreshSale($locked);

            $this->audit->log('sale_voided', 'sales', "Sale {$locked->invoice_no} cancelled: {$reason}", $locked, new: [
                'reason' => $reason, 'total' => (string) $locked->grand_total, 'money_given_back' => $refunded,
            ], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
