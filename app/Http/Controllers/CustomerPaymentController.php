<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Customers\PostCustomerPayment;
use App\Actions\Customers\VoidCustomerPayment;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\ServiceJob;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

/** Money collected from customers (not the till: till payments belong to their sale), and its receipts. */
class CustomerPaymentController extends Controller
{
    public function __construct(
        private readonly PostCustomerPayment $post,
        private readonly VoidCustomerPayment $void,
        private readonly CustomerAccountService $accounts,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('customer_payments.view');
        $request->validate([
            'customer_id' => ['nullable', 'integer'], 'method' => ['nullable', 'integer'], 'status' => ['nullable', 'in:all,posted,voided'],
            'source' => ['nullable', 'in:collected,till,all'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $source = $request->input('source', 'collected');

        $query = CustomerPayment::query()->where('type', 'receipt')->whereHas('customer', fn ($q) => $q->where('is_walk_in', false))
            ->when($source === 'collected', fn ($q) => $q->whereNull('sale_id'))
            ->when($source === 'till', fn ($q) => $q->whereNotNull('sale_id'))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('method'), fn ($q) => $q->where('payment_method_id', $request->integer('method')))
            ->when(in_array($request->input('status'), ['posted', 'voided'], true), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('payment_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('payment_date', '<=', $request->input('to')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string) $request->input('q'))).'%';
                $q->where(fn ($w) => $w->where('receipt_no', 'like', $like)->orWhere('reference_no', 'like', $like));
            });

        $total = (clone $query)->reorder()->where('status', 'posted')->sum('amount');

        return view('customer-payments.index', [
            'payments' => $query->with(['customer:id,name,code', 'method:id,name'])->orderByDesc('payment_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'customers' => Customer::query()->where('is_walk_in', false)->orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::query()->orderBy('sort_order')->get(['id', 'name']),
            'total' => (string) $total,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('customer_payments.create');
        $request->validate(['customer' => ['nullable', 'integer'], 'sale' => ['nullable', 'integer'], 'job' => ['nullable', 'integer']]);

        $customer = $request->filled('customer') ? Customer::query()->withTrashed()->where('is_walk_in', false)->find($request->integer('customer')) : null;
        if (! $customer && $request->filled('sale')) {
            $customer = Sale::query()->find($request->integer('sale'))?->customer;
            $customer = $customer && ! $customer->is_walk_in ? $customer : null;
        }

        if (! $customer && $request->filled('job')) {
            $customer = ServiceJob::query()->find($request->integer('job'))?->customer;
            $customer = $customer && ! $customer->is_walk_in ? $customer : null;
        }

        $bills = $customer ? collect($this->accounts->openRows($customer->id)) : collect();   // unpaid invoices and delivered service jobs

        return view('customer-payments.create', [
            'customer' => $customer,
            'customers' => Customer::query()->where('is_walk_in', false)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'balance']),
            'methods' => PaymentMethod::query()->forTill()->get(),
            'bills' => $bills,
            'owed' => $bills->reduce(fn ($c, $b) => Decimal::add($c, $b['due'], 2), '0.00'),
            'advance' => $customer ? $this->accounts->advanceFor($customer->id) : '0.00',
            'focus' => $request->filled('job') ? 'j'.$request->integer('job') : ($request->integer('sale') ?: null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('customer_payments.create');
        $d = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            'allocation' => ['required', 'in:auto,manual,advance'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ], ['decimal' => 'Too many decimal places.']);

        try {
            $payment = $this->post->execute([
                'customer_id' => (int) $d['customer_id'], 'payment_method_id' => (int) $d['payment_method_id'], 'amount' => (string) $d['amount'],
                'reference_no' => $d['reference_no'] ?? null, 'notes' => $d['notes'] ?? null, 'allocation' => $d['allocation'],
                'allocations' => array_filter($d['allocations'] ?? [], fn ($v) => $v !== null && $v !== ''),
            ], $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('customer-payments.show', $payment)
            ->with('success', "Payment {$payment->receipt_no} recorded.")
            ->with('receipt_url', URL::temporarySignedRoute('customer-payments.receipt', now()->addMinutes(10), ['payment' => $payment->id, 'autoprint' => 1]));
    }

    public function show(Request $request, CustomerPayment $payment): View
    {
        $this->authorize('customer_payments.view');
        $payment->load(['customer', 'method', 'creator', 'voider', 'allocations.sale']);

        $free = '0.00';
        if (! $payment->isVoided() && ! $payment->customer->is_walk_in) {
            $free = collect($this->accounts->advanceReceipts($payment->customer_id))->firstWhere('id', $payment->id)['free'] ?? '0.00';
        }

        return view('customer-payments.show', [
            'payment' => $payment, 'free' => $free,
            'receiptUrl' => session('receipt_url'),
            'autoPrint' => session('receipt_url') && $this->settings->bool('printing.auto_print_payment'),
        ]);
    }

    public function void(Request $request, CustomerPayment $payment): RedirectResponse
    {
        $this->authorize('customer_payments.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->void->execute($payment, $d['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('customer-payments.show', $payment)->with('success', "Payment {$payment->receipt_no} cancelled; the invoices it settled are open again.");
    }

    /** The original receipt, through a signed link that lasts ten minutes. */
    public function receipt(Request $request, CustomerPayment $payment): View
    {
        return $this->document($payment, false, $request->boolean('autoprint'));
    }

    public function reprint(Request $request, CustomerPayment $payment): View
    {
        $this->authorize('customer_payments.view');
        abort_unless($request->user()->can('sales.reprint'), 403);
        $this->audit->log('customer_payment_reprinted', 'customers', "Receipt {$payment->receipt_no} reprinted by {$request->user()->name}", $payment, user: $request->user());

        return $this->document($payment, true, false);
    }

    private function document(CustomerPayment $payment, bool $duplicate, bool $autoprint): View
    {
        $payment->load(['customer', 'method', 'creator', 'allocations.sale']);

        $barcode = null;
        try {
            $barcode = (new BarcodeGeneratorSVG)->getBarcode($payment->receipt_no, BarcodeGenerator::TYPE_CODE_128, 1.4, 34);
        } catch (Throwable) {
            // the receipt is still valid without a barcode
        }
        $logo = null;
        $path = $this->settings->string('shop.logo_path');
        if ($this->settings->bool('receipt.show_logo') && $path !== '' && Storage::disk('local')->exists($path)) {
            $logo = route('branding.logo');
        }

        return view('customer-payments.receipt', [
            'payment' => $payment, 'duplicate' => $duplicate, 'autoprint' => $autoprint, 'barcode' => $barcode, 'logo' => $logo, 's' => $this->settings,
            'copies' => max(1, (int) $this->settings->get('printing.copies')),
            'balance' => $payment->customer->is_walk_in ? null : (string) $payment->customer->getRawOriginal('balance'),
        ]);
    }
}
