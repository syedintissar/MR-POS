<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierCreditApplication;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Purchasing\SupplierAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * "Use supplier credit": the credit a supplier holds for the shop (left over from goods sent back) pays what is still owed on bills,
 * oldest bill first (or one chosen bill). The credit is used up exactly once: every use is a row that names the return it came from and
 * the bill it paid, and what is left of a return is always its credit less its posted uses, read after the supplier is locked, so two
 * people pressing the button at the same moment cannot spend it twice. The supplier's balance does not change (it already said the shop
 * is owed this money); only the bills owe less.
 *
 * Lock order: supplier -> credit returns (oldest first) -> bills (oldest first) -> audit.
 */
class ApplySupplierCredit
{
    public function __construct(private readonly SupplierAccountService $accounts, private readonly AuditLogger $audit) {}

    /** @return list<SupplierCreditApplication> what was used (never empty) */
    public function execute(int $supplierId, ?Purchase $onlyBill, User $by): array
    {
        if (! $by->can('supplier_payments.create')) {
            throw new AuthorizationException('You are not allowed to use supplier credit.');
        }
        if ($onlyBill !== null) {
            $billSupplier = Purchase::query()->whereKey($onlyBill->getKey())->value('supplier_id');
            if ($billSupplier === null || (int) $billSupplier !== $supplierId) {
                throw new DomainException('That bill does not belong to this supplier.');
            }
        }

        return DB::transaction(function () use ($supplierId, $onlyBill, $by) {
            $supplier = $this->accounts->lockSupplier($supplierId);

            PurchaseReturn::query()->where('supplier_id', $supplier->id)->where('status', PurchaseReturn::POSTED)->where('credit_amount', '>', 0)
                ->orderBy('return_date')->orderBy('id')->lockForUpdate()->get();
            $sources = $this->accounts->creditSources($supplier->id);
            if ($sources === []) {
                throw new DomainException("{$supplier->name} holds no credit for the shop.");
            }

            $bills = Purchase::query()->where('supplier_id', $supplier->id)->owing()->when($onlyBill, fn ($q) => $q->whereKey($onlyBill->getKey()))->lockForUpdate()->get();
            if ($bills->isEmpty()) {
                throw new DomainException($onlyBill ? "{$onlyBill->label()} has nothing left to pay." : "There is no bill to use the credit on: nothing is owed to {$supplier->name} right now.");
            }

            $used = [];
            $total = '0.00';
            foreach ($bills as $bill) {
                $due = Decimal::round((string) $bill->due_amount, 2);
                foreach ($sources as $i => $src) {
                    if (! Decimal::isPositive($due)) {
                        break;
                    }
                    if (! Decimal::isPositive($src['left'])) {
                        continue;
                    }
                    $part = Decimal::cmp($due, $src['left']) < 0 ? $due : $src['left'];

                    $row = new SupplierCreditApplication;
                    $row->forceFill(['supplier_id' => $supplier->id, 'purchase_return_id' => $src['return_id'], 'purchase_id' => $bill->id, 'amount' => $part, 'status' => 'posted', 'created_by' => $by->id])->save();
                    $used[] = $row;
                    $sources[$i]['left'] = Decimal::sub($src['left'], $part, 2);
                    $due = Decimal::sub($due, $part, 2);
                    $total = Decimal::add($total, $part, 2);
                }
                $this->accounts->refreshPurchase($bill);
            }
            if ($used === []) {
                throw new DomainException("{$supplier->name} holds no credit for the shop.");
            }

            $this->audit->log('supplier_credit_used', 'purchasing', "{$total} of {$supplier->name}'s credit used on ".count(array_unique(array_map(fn ($r) => $r->purchase_id, $used))).' bill(s)', $supplier, new: [
                'supplier' => $supplier->name, 'total' => $total,
                'uses' => array_map(fn ($r) => ['return' => $r->purchase_return_id, 'bill' => $r->purchase_id, 'amount' => (string) $r->amount], $used),
            ], user: $by);

            return $used;
        }, 3);
    }
}
