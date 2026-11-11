<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MoneyAccount;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The ways money is paid or received (Cash, Bank Transfer, Easypaisa ...). Managed from Settings → Payment methods.
 * The system methods (Cash, Customer Credit) can be renamed but never removed or switched off, because the till and
 * customer credit depend on them. A method that has been used can be switched off but never deleted.
 */
class PaymentMethodController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        $d = $this->validated($request);

        $method = PaymentMethod::query()->create($d + [
            'code' => $this->uniqueCode($d['name']),
            'is_system' => false,
            'sort_order' => ((int) PaymentMethod::query()->max('sort_order')) + 10,
        ]);
        // a new cash method pays into the cash drawer at once; any other kind is linked to its account under Cash & bank → Accounts
        if ($method->kind === 'cash' && ($drawer = MoneyAccount::query()->where('is_system', true)->where('kind', 'cash')->value('id'))) {
            $method->forceFill(['money_account_id' => $drawer])->save();
        }
        $this->audit->log('payment_method_created', 'settings', "Payment method “{$method->name}” added", $method, new: $d);

        return redirect()->route('settings.index', 'payment_methods')->with('success', "“{$method->name}” added.");
    }

    public function update(Request $request, PaymentMethod $method): RedirectResponse
    {
        $this->authorize('settings.manage');
        $d = $this->validated($request, $method);

        if ($method->is_system) {
            unset($d['kind']);   // what a system method is cannot change
        }
        $old = $method->only(array_keys($d));
        $method->fill($d)->save();

        $changed = array_filter($d, fn ($v, $k) => (string) ($old[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            $this->audit->log('payment_method_updated', 'settings', "Payment method “{$method->name}” updated", $method, array_intersect_key($old, $changed), $changed);
        }

        return redirect()->route('settings.index', 'payment_methods')->with('success', 'Saved.');
    }

    public function toggle(PaymentMethod $method): RedirectResponse
    {
        $this->authorize('settings.manage');
        if ($method->is_system) {
            return back()->with('error', "“{$method->name}” is needed by the system and cannot be switched off.");
        }

        $method->forceFill(['is_active' => ! $method->is_active])->save();
        $this->audit->log($method->is_active ? 'payment_method_activated' : 'payment_method_deactivated', 'settings', "Payment method “{$method->name}” ".($method->is_active ? 'switched on' : 'switched off'), $method);

        return back()->with('success', $method->is_active ? 'Switched on.' : 'Switched off. Old payments keep their method.');
    }

    public function destroy(PaymentMethod $method): RedirectResponse
    {
        $this->authorize('settings.manage');
        if ($method->is_system) {
            return back()->with('error', "“{$method->name}” is needed by the system and cannot be deleted.");
        }
        if ($method->isUsed()) {
            return back()->with('error', "“{$method->name}” has been used on payments, so it cannot be deleted. Switch it off instead.");
        }

        $this->audit->log('payment_method_deleted', 'settings', "Payment method “{$method->name}” deleted", $method, $method->only(['name', 'kind']));
        $method->delete();

        return back()->with('success', 'Deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PaymentMethod $editing = null): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('payment_methods', 'name')->ignore($editing?->id)],
            // Customer credit is reserved for the system method of that name; an existing method may always keep its own kind.
            'kind' => ['required', Rule::in([...array_keys(array_diff_key(PaymentMethod::KINDS, ['customer_credit' => 1])), $editing?->kind])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        $d['requires_reference'] = $request->boolean('requires_reference');
        if ($editing) {
            $d['sort_order'] ??= $editing->sort_order;
        } else {
            unset($d['sort_order']);
        }

        return $d;
    }

    private function uniqueCode(string $name): string
    {
        $base = mb_substr(Str::slug($name, '_') ?: 'method', 0, 24);   // the code column holds 30 characters; room is left for a _2, _3 ... suffix
        $code = $base;
        for ($i = 2; PaymentMethod::query()->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }
}
