<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Customers\AdjustCustomerBalance;
use App\Actions\Customers\AllocateCustomerAdvance;
use App\Actions\Customers\DeleteCustomer;
use App\Actions\Customers\SaveCustomer;
use App\Exceptions\DuplicatePhoneException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\ServiceJob;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customers: the list, the profile with the account statement, and the till's customer search. Balances and everything about money
 * are shown only to people who may see customer payments; a technician who may view customers sees names and phone numbers only.
 * Credit terms (price list, credit allowed, credit limit) are a financial decision: only people who may exceed a credit limit
 * (managers, the administrator) can change them.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly SaveCustomer $save,
        private readonly DeleteCustomer $delete,
        private readonly AdjustCustomerBalance $adjust,
        private readonly AllocateCustomerAdvance $advance,
        private readonly CustomerAccountService $accounts,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('customers.view');
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive,all'], 'balance' => ['nullable', 'in:all,owing,credit']]);
        $money = $request->user()->can('customer_payments.view');
        $status = $request->input('status', 'active');

        $query = Customer::query()->where('is_walk_in', false)->search($request->input('q'))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($money && $request->input('balance') === 'owing', fn ($q) => $q->where('balance', '>', 0))
            ->when($money && $request->input('balance') === 'credit', fn ($q) => $q->where('balance', '<', 0));

        $totals = $money ? Customer::query()->where('is_walk_in', false)->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS owed, COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END), 0) AS held, COALESCE(SUM(balance > 0), 0) AS debtors')->first() : null;

        return view('customers.index', [
            'customers' => $query->orderBy('name')->paginate(25)->withQueryString(),
            'money' => $money,
            'totals' => $totals,
        ]);
    }

    public function create(): View
    {
        $this->authorize('customers.create');

        return view('customers.form', ['customer' => new Customer(['price_tier' => 'retail', 'is_active' => true]), 'canTerms' => request()->user()->can('sales.override_credit_limit')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('customers.create');

        try {
            $customer = $this->save->execute($this->payload($request, null), $request->user(), null, $request->boolean('allow_duplicate_phone'));
        } catch (DuplicatePhoneException $e) {
            return back()->withInput()->with('error', $e->getMessage())->with('duplicate_phone', true);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('customers.show', $customer)->with('success', "Customer {$customer->code} added.");
    }

    public function show(Request $request, Customer $customer): View
    {
        $this->authorize('customers.view');
        abort_if($customer->is_walk_in, 404);
        $user = $request->user();
        $money = $user->can('customer_payments.view');

        $data = ['customer' => $customer, 'money' => $money, 'canTerms' => $user->can('sales.override_credit_limit')];

        if ($money) {
            $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
            $open = collect($this->accounts->openRows($customer->id));   // unpaid invoices and delivered service jobs, oldest first

            $data += [
                'open' => $open,
                'owed' => $open->reduce(fn ($c, $s) => Decimal::add($c, $s['due'], 2), '0.00'),
                'advance' => $this->accounts->advanceFor($customer->id),
                'salesTotal' => Decimal::add((string) Sale::query()->where('customer_id', $customer->id)->where('status', Sale::COMPLETED)->sum('grand_total'), (string) ServiceJob::query()->where('customer_id', $customer->id)->where('status', ServiceJob::DELIVERED)->sum('grand_total'), 2),
                'recentJobs' => $user->can('service_jobs.view_all') ? ServiceJob::query()->with('serviceType:id,name')->where('customer_id', $customer->id)->orderByDesc('received_at')->orderByDesc('id')->limit(10)->get() : collect(),
                'paidTotal' => (string) CustomerPayment::query()->where('customer_id', $customer->id)->where('type', 'receipt')->where('status', 'posted')->sum('amount'),
                'lastPayment' => CustomerPayment::query()->where('customer_id', $customer->id)->where('type', 'receipt')->where('status', 'posted')->latest('payment_date')->first(),
                'recentSales' => Sale::query()->where('customer_id', $customer->id)->orderByDesc('sale_date')->orderByDesc('id')->limit(10)->get(),
                'recentPayments' => CustomerPayment::query()->with('method:id,name')->where('customer_id', $customer->id)->where('type', 'receipt')->orderByDesc('payment_date')->orderByDesc('id')->limit(10)->get(),
                'statement' => $this->accounts->statement($customer->id, $range['from'] ?? null, $range['to'] ?? null),
                'from' => $range['from'] ?? null,
                'to' => $range['to'] ?? null,
                'canStatement' => $user->can('customers.statement'),
            ];
        }

        return view('customers.show', $data);
    }

    public function edit(Request $request, Customer $customer): View
    {
        $this->authorize('customers.edit');
        abort_if($customer->is_walk_in, 404);

        return view('customers.form', ['customer' => $customer, 'canTerms' => $request->user()->can('sales.override_credit_limit')]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('customers.edit');
        abort_if($customer->is_walk_in, 404);

        try {
            $this->save->execute($this->payload($request, $customer), $request->user(), $customer, $request->boolean('allow_duplicate_phone'));
        } catch (DuplicatePhoneException $e) {
            return back()->withInput()->with('error', $e->getMessage())->with('duplicate_phone', true);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('customers.show', $customer)->with('success', 'Customer saved.');
    }

    public function toggle(Customer $customer): RedirectResponse
    {
        $this->authorize('customers.edit');
        abort_if($customer->is_walk_in, 404);

        $customer->forceFill(['is_active' => ! $customer->is_active])->save();
        $this->audit->log($customer->is_active ? 'customer_activated' : 'customer_deactivated', 'customers', "Customer {$customer->code} ".($customer->is_active ? 'activated' : 'deactivated'), $customer);

        return back()->with('success', $customer->is_active ? 'Customer is active again.' : 'Customer deactivated. They keep their account but cannot buy until re-activated.');
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('customers.delete');

        try {
            $this->delete->execute($customer, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('customers.index')->with('success', 'Customer deleted.');
    }

    public function adjustBalance(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('customers.adjust_balance');
        $d = $request->validate([
            'type' => ['required', Rule::in(AdjustCustomerBalance::TYPES)],
            'direction' => ['required', 'in:customer_owes,shop_owes'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'entry_date' => ['nullable', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:200'],
        ], ['decimal' => 'Too many decimal places.']);

        try {
            $this->adjust->execute($customer, $d['type'], $d['direction'], (string) $d['amount'], $d['reason'], $request->user(), $d['entry_date'] ?? null);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('customers.show', $customer)->with('success', 'The customer\'s account has been updated.');
    }

    /** Apply the customer's advance (money paid in earlier) to their oldest unpaid invoices. */
    public function applyAdvance(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('customer_payments.create');

        try {
            $applied = $this->advance->execute($customer, null, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The advance was applied to '.count($applied).' invoice(s).');
    }

    // ------------------------------------------------------------------------------------------------ statement

    public function statement(Request $request, Customer $customer): View
    {
        return view('customers.statement', $this->statementData($request, $customer, false));
    }

    public function statementPdf(Request $request, Customer $customer): Response
    {
        $data = $this->statementData($request, $customer, true);
        $this->audit->log('customer_statement_printed', 'customers', "Statement of {$customer->name} downloaded as PDF by {$request->user()->name}", $customer, user: $request->user());

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('customers.statement', $data)->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="statement-'.$customer->code.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function statementData(Request $request, Customer $customer, bool $pdf): array
    {
        $this->authorize('customers.statement');
        $this->authorize('customer_payments.view');
        abort_if($customer->is_walk_in, 404);
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $logo = null;
        $path = $this->settings->string('shop.logo_path');
        if ($pdf && $path !== '' && Storage::disk('local')->exists($path)) {
            $logo = 'data:'.(Storage::disk('local')->mimeType($path) ?: 'image/png').';base64,'.base64_encode(Storage::disk('local')->get($path));
        }

        return [
            'customer' => $customer, 'statement' => $this->accounts->statement($customer->id, $range['from'] ?? null, $range['to'] ?? null),
            'from' => $range['from'] ?? null, 'to' => $range['to'] ?? null, 'pdf' => $pdf, 's' => $this->settings, 'logo' => $logo,
            'open' => collect($this->accounts->openRows($customer->id)),
        ];
    }

    // ------------------------------------------------------------------------------------------------ the till

    /** Customer search for the till. Money figures only for people who may see customer payments. */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('pos.access');
        $this->authorize('customers.view');
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);
        $money = $request->user()->can('customer_payments.view');

        $rows = Customer::query()->where('is_walk_in', false)->where('is_active', true)->search($request->input('q'))->orderBy('name')->limit(10)->get();

        return response()->json($rows->map(fn (Customer $c) => $this->accounts->summary($c, $money))->values());
    }

    /** "Add customer" box on the till. A cashier can add a customer but never gives them credit terms. */
    public function quickStore(Request $request): JsonResponse
    {
        $this->authorize('pos.access');
        $this->authorize('customers.create');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:30'], 'company' => ['nullable', 'string', 'max:150'],
            'allow_duplicate_phone' => ['nullable', 'boolean'],
        ]);

        try {
            $customer = $this->save->execute($d + ['price_tier' => 'retail', 'allow_credit' => false], $request->user(), null, (bool) ($d['allow_duplicate_phone'] ?? false));
        } catch (DuplicatePhoneException $e) {
            return response()->json(['error' => 'duplicate_phone', 'message' => $e->getMessage(), 'names' => $e->names], 409);
        } catch (DomainException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json($this->accounts->summary($customer, $request->user()->can('customer_payments.view')), 201);
    }

    /** @return array<string, mixed> what SaveCustomer takes, with credit terms kept as they are for people who may not change them */
    private function payload(Request $request, ?Customer $existing): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'company' => ['nullable', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:150'], 'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'], 'price_tier' => ['nullable', 'in:retail,wholesale'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ], ['decimal' => 'Too many decimal places.']);

        if ($request->user()->can('sales.override_credit_limit')) {
            $d['allow_credit'] = $request->boolean('allow_credit');
        } else {
            $d['price_tier'] = $existing?->price_tier ?? 'retail';
            $d['allow_credit'] = $existing?->allow_credit ?? false;
            $d['credit_limit'] = $existing?->credit_limit;
        }
        $d['is_active'] = $existing ? $request->boolean('is_active', $existing->is_active) : true;

        return $d;
    }
}
