<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Purchasing\ApplySupplierCredit;
use App\Actions\Purchasing\VoidSupplierCreditApplication;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierCreditApplication;
use App\Support\Money;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Use supplier credit": the credit a supplier holds for the shop pays what is owed on bills (oldest first, or one bill); and taking a use back. */
class SupplierCreditController extends Controller
{
    public function __construct(private readonly ApplySupplierCredit $apply, private readonly VoidSupplierCreditApplication $void) {}

    public function applyToSupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        return $this->run($request, $supplier->id, null, route('suppliers.show', $supplier));
    }

    public function applyToBill(Request $request, Purchase $purchase): RedirectResponse
    {
        return $this->run($request, (int) $purchase->supplier_id, $purchase, route('purchases.show', $purchase));
    }

    public function voidUse(Request $request, SupplierCreditApplication $application): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->void->execute($application, $d['reason'], $request->user());
        } catch (DomainException|AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'That use of credit was cancelled: the bill owes the amount again and the credit is available again.');
    }

    private function run(Request $request, int $supplierId, ?Purchase $bill, string $back): RedirectResponse
    {
        try {
            $used = $this->apply->execute($supplierId, $bill, $request->user());
        } catch (DomainException|AuthorizationException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }
        $total = array_reduce($used, fn ($c, $r) => bcadd($c, (string) $r->amount, 2), '0.00');

        return redirect($back)->with('success', Money::withSymbol($total).' of supplier credit was used on '.count(array_unique(array_map(fn ($r) => $r->purchase_id, $used))).' bill(s).');
    }
}
