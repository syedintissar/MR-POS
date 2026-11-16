<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\SettingsService;
use App\Support\Decimal;
use App\Support\Money;
use App\Support\WhatsApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Quotations: a price offer for a customer or an office. Nothing here moves stock or money. An accepted quotation is opened in
 * the till, where it is paid for or sold on credit like any other sale; the till then marks it accepted.
 * People who complete sales may write quotations; they are seen by whoever may see invoices (their own, or everybody's).
 */
class QuotationController extends Controller
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorizeView($user);
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:all,open,expired,accepted,cancelled']]);
        $status = $request->input('status', 'open');
        $today = now()->toDateString();

        $query = Quotation::query()->with('creator:id,name')
            ->when(! $user->can('sales.view_all'), fn ($q) => $q->where('created_by', $user->id))
            ->when($status === 'open', fn ($q) => $q->where('status', Quotation::OPEN)->where(fn ($w) => $w->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today)))
            ->when($status === 'expired', fn ($q) => $q->where('status', Quotation::OPEN)->whereDate('valid_until', '<', $today))
            ->when(in_array($status, [Quotation::ACCEPTED, Quotation::CANCELLED], true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string) $request->input('q'))).'%';
                $q->where(fn ($w) => $w->where('quotation_no', 'like', $like)->orWhere('customer_name', 'like', $like)->orWhere('customer_phone', 'like', $like));
            });

        return view('quotations.index', [
            'quotations' => $query->orderByDesc('quote_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'canCreate' => $user->can('sales.create'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('sales.create');

        return view('quotations.form', $this->formData(new Quotation(['quote_date' => now()->toDateString(), 'valid_until' => now()->addDays(7)->toDateString()]), $request));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('sales.create');
        $quotation = $this->save(new Quotation, $this->validated($request), $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', "Quotation {$quotation->quotation_no} saved.");
    }

    public function show(Request $request, Quotation $quotation): View
    {
        $this->authorizeSee($request->user(), $quotation);
        $quotation->load(['items.product.unit', 'customer', 'creator', 'sale:id,invoice_no']);

        return view('quotations.show', [
            'quotation' => $quotation,
            'canChange' => $request->user()->can('sales.create') && $quotation->isOpen(),
            'canSell' => $request->user()->can('sales.create') && $request->user()->can('pos.access') && $quotation->isOpen() && $quotation->items->whereNotNull('product_id')->isNotEmpty(),
            'typedLines' => $quotation->items->whereNull('product_id')->count(),
            'whatsapp' => WhatsApp::link($this->message($quotation), $quotation->customer?->whatsapp, $quotation->customer_phone, $quotation->customer?->phone),
        ]);
    }

    public function edit(Request $request, Quotation $quotation): View|RedirectResponse
    {
        $this->authorize('sales.create');
        $this->authorizeSee($request->user(), $quotation);
        if (! $quotation->isOpen()) {
            return redirect()->route('quotations.show', $quotation)->with('error', "{$quotation->quotation_no} is {$quotation->status} and can no longer be changed.");
        }

        return view('quotations.form', $this->formData($quotation->load('items.product.unit'), $request));
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorize('sales.create');
        $this->authorizeSee($request->user(), $quotation);
        if (! $quotation->isOpen()) {
            return redirect()->route('quotations.show', $quotation)->with('error', "{$quotation->quotation_no} is {$quotation->status} and can no longer be changed.");
        }
        $this->save($quotation, $this->validated($request), $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', 'Quotation saved.');
    }

    /** The customer said no (or it was written by mistake). Kept for the record, never deleted. */
    public function cancel(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorize('sales.create');
        $this->authorizeSee($request->user(), $quotation);
        if (! $quotation->isOpen()) {
            return back()->with('error', "{$quotation->quotation_no} is already {$quotation->status}.");
        }
        $quotation->forceFill(['status' => Quotation::CANCELLED, 'updated_by' => $request->user()->id])->save();
        $this->audit->log('quotation_cancelled', 'sales', "Quotation {$quotation->quotation_no} cancelled", $quotation);

        return back()->with('success', "{$quotation->quotation_no} cancelled.");
    }

    public function print(Request $request, Quotation $quotation): View
    {
        $this->authorizeSee($request->user(), $quotation);

        return view('quotations.print', ['quotation' => $quotation->load(['items', 'customer', 'creator']), 's' => $this->settings]);
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function authorizeView(User $user): void
    {
        abort_unless($user->canAny(['sales.view_all', 'sales.view_own', 'sales.create']), 403);
    }

    private function authorizeSee(User $user, Quotation $quotation): void
    {
        $this->authorizeView($user);
        abort_unless($user->can('sales.view_all') || (int) $quotation->created_by === $user->id, 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('is_walk_in', false)->whereNull('deleted_at')],
            'customer_name' => ['nullable', 'required_without:customer_id', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'quote_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quote_date'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['nullable', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ], [
            'customer_name.required_without' => 'Choose a customer or type who the quotation is for.',
            'lines.required' => 'Add at least one item.',
            'valid_until.after_or_equal' => 'The quotation cannot stop being valid before its own date.',
            'decimal' => 'Too many decimal places.',
        ]);

        foreach ($d['lines'] as $i => $l) {
            if (empty($l['product_id']) && trim((string) ($l['description'] ?? '')) === '') {
                throw ValidationException::withMessages(["lines.{$i}.description" => 'Line '.($i + 1).': choose a product or type what is offered.']);
            }
        }

        return $d;
    }

    /** @param  array<string, mixed>  $d */
    private function save(Quotation $quotation, array $d, User $by): Quotation
    {
        return DB::transaction(function () use ($quotation, $d, $by) {
            $customer = ! empty($d['customer_id']) ? Customer::query()->findOrFail((int) $d['customer_id']) : null;
            $products = Product::query()->whereIn('id', collect($d['lines'])->pluck('product_id')->filter())->get()->keyBy('id');

            $rows = [];
            $subtotal = '0.00';
            foreach (array_values($d['lines']) as $l) {
                $product = ! empty($l['product_id']) ? $products->get((int) $l['product_id']) : null;
                $qty = Decimal::round((string) $l['quantity'], 3);
                $price = Decimal::round((string) $l['unit_price'], 2);
                $total = Decimal::mul($qty, $price, 2);
                $subtotal = Decimal::add($subtotal, $total, 2);
                $typed = trim((string) ($l['description'] ?? ''));
                $rows[] = [
                    'product_id' => $product?->id, 'description' => mb_substr($typed !== '' ? $typed : (string) $product?->name, 0, 255),
                    'quantity' => $qty, 'unit_price' => $price, 'line_total' => $total,
                ];
            }

            $discount = Decimal::round((string) ($d['discount_amount'] ?? '0') ?: '0', 2);
            if (Decimal::cmp($discount, $subtotal) > 0) {
                throw ValidationException::withMessages(['discount_amount' => 'The discount cannot be more than the items come to.']);
            }

            $new = ! $quotation->exists;
            $quotation->fill([
                'customer_id' => $customer?->id,
                'customer_name' => $customer ? $customer->name : trim((string) $d['customer_name']),
                'customer_phone' => trim((string) ($d['customer_phone'] ?? '')) ?: ($customer?->phone),
                'quote_date' => $d['quote_date'], 'valid_until' => $d['valid_until'] ?? null,
                'subtotal' => $subtotal, 'discount_amount' => $discount, 'grand_total' => Decimal::sub($subtotal, $discount, 2),
                'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'updated_by' => $by->id,
            ]);
            if ($new) {
                $quotation->forceFill(['quotation_no' => $this->numbers->next('quotation'), 'status' => Quotation::OPEN, 'created_by' => $by->id]);
            }
            $quotation->save();

            $quotation->items()->delete();
            foreach ($rows as $row) {
                QuotationItem::query()->create($row + ['quotation_id' => $quotation->id]);
            }

            $this->audit->log($new ? 'quotation_created' : 'quotation_updated', 'sales',
                "Quotation {$quotation->quotation_no} for {$quotation->customer_name} ".($new ? 'created' : 'updated').': '.count($rows).' line(s), total '.$quotation->grand_total,
                $quotation, new: ['customer' => $quotation->customer_name, 'lines' => count($rows), 'grand_total' => (string) $quotation->grand_total], user: $by);

            return $quotation->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function formData(Quotation $quotation, Request $request): array
    {
        $raw = $request->old('lines');
        $rows = $raw !== null ? collect($raw) : $quotation->items->map(fn (QuotationItem $i) => [
            'product_id' => $i->product_id, 'description' => $i->description, 'quantity' => Decimal::trim((string) $i->quantity), 'unit_price' => Decimal::trim((string) $i->unit_price),
        ]);
        $products = Product::query()->withTrashed()->with('unit')->whereIn('id', $rows->pluck('product_id')->filter())->get()->keyBy('id');

        $key = 0;
        $lines = $rows->map(function ($r) use ($products, &$key) {
            $p = ! empty($r['product_id']) ? $products->get((int) $r['product_id']) : null;

            return [
                'key' => ++$key, 'product_id' => $p?->id, 'sku' => $p?->sku, 'unit' => $p?->unit->short_name,
                'description' => (string) ($r['description'] ?? $p?->name ?? ''), 'quantity' => (string) ($r['quantity'] ?? '1'), 'unit_price' => (string) ($r['unit_price'] ?? ''),
            ];
        })->values()->all();

        return [
            'quotation' => $quotation,
            'lines' => $lines,
            'customers' => Customer::query()->where('is_walk_in', false)->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $quotation->customer_id))
                ->orderBy('name')->get(['id', 'name', 'phone', 'price_tier']),
            'canSearch' => $request->user()->can('pos.access'),
        ];
    }

    /** The quotation as a short WhatsApp message. */
    private function message(Quotation $quotation): string
    {
        $shop = $this->settings->string('shop.name') ?: config('app.name');
        $lines = ["*{$shop}*", "Quotation {$quotation->quotation_no} · ".$quotation->quote_date->format('d M Y'), "For: {$quotation->customer_name}", ''];
        foreach ($quotation->items as $i) {
            $lines[] = '• '.$i->description.' × '.Decimal::trim((string) $i->quantity).' @ '.Money::format($i->unit_price).' = '.Money::format($i->line_total);
        }
        $lines[] = '';
        if (Decimal::isPositive((string) $quotation->discount_amount)) {
            $lines[] = 'Discount: '.Money::withSymbol($quotation->discount_amount);
        }
        $lines[] = 'Total: '.Money::withSymbol($quotation->grand_total);
        if ($quotation->valid_until) {
            $lines[] = 'Valid until '.$quotation->valid_until->format('d M Y');
        }
        if ($quotation->notes) {
            $lines[] = $quotation->notes;
        }
        $lines[] = '';
        $lines[] = collect([$this->settings->string('shop.phone'), $this->settings->string('shop.address')])->filter()->implode(' · ');

        return implode("\n", $lines);
    }
}
