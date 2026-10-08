<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Sales\VoidSale;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

class SaleController extends Controller
{
    public const FORMATS = ['thermal', 'a4'];

    public function __construct(
        private readonly VoidSale $void,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorizeList($user);
        $request->validate([
            'q' => ['nullable', 'string', 'max:60'], 'status' => ['nullable', 'in:all,completed,voided'], 'cashier' => ['nullable', 'integer'],
            'method' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);

        $query = $this->visibleTo($user)
            ->search($request->input('q'))
            ->when(in_array($request->input('status'), ['completed', 'voided'], true), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('cashier') && $user->can('sales.view_all'), fn ($q) => $q->where('user_id', $request->integer('cashier')))
            ->when($request->filled('method'), fn ($q) => $q->whereIn('id', fn ($s) => $s->select('sp.sale_id')->from('sale_payments as sp')
                ->join('customer_payments as cp', 'cp.id', '=', 'sp.customer_payment_id')->where('cp.payment_method_id', $request->integer('method'))))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('sale_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('sale_date', '<=', $request->input('to')));

        $totals = (clone $query)->reorder()->where('status', Sale::COMPLETED)->toBase()
            ->selectRaw('COUNT(*) AS sales, COALESCE(SUM(grand_total), 0) AS total, COALESCE(SUM(tax_total), 0) AS tax')->first();

        return view('sales.index', [
            'sales' => $query->withCount('items')->with('cashier:id,name', 'customer:id,name,is_walk_in')->orderByDesc('sale_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'cashiers' => $user->can('sales.view_all') ? User::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'methods' => PaymentMethod::query()->orderBy('sort_order')->get(['id', 'name']),
            'totals' => $totals,
            'ownOnly' => ! $user->can('sales.view_all'),
        ]);
    }

    public function show(Request $request, Sale $sale): View
    {
        $this->authorizeSale($request->user(), $sale);
        $sale->load(['items.product', 'customer', 'cashier', 'approver', 'voider', 'allocations.payment.method']);
        $showCost = $request->user()->can('products.view_cost');

        return view('sales.show', [
            'sale' => $sale,
            'showCost' => $showCost,
            'cost' => $showCost ? $sale->items->reduce(fn ($c, $i) => bcadd($c, bcmul((string) $i->quantity, (string) $i->unit_cost, 4), 4), '0.0000') : null,
            'canVoid' => $request->user()->can('sales.void'),
            'canReprint' => $request->user()->can('sales.reprint'),
            'voidWindowHours' => (int) $this->settings->get('sales.void_window_hours'),
        ]);
    }

    /**
     * The original print, opened by the POS right after the sale. It is a signed link that works for ten minutes, so a copy printed
     * later cannot pass for the original: later prints go through reprint() and are marked DUPLICATE.
     */
    public function print(Request $request, Sale $sale, string $format): View
    {
        abort_unless(in_array($format, self::FORMATS, true), 404);

        return $this->document($sale, $format, duplicate: false, autoprint: $request->boolean('autoprint'));
    }

    public function reprint(Request $request, Sale $sale, string $format): View
    {
        $user = $request->user();
        $this->authorizeSale($user, $sale);
        abort_unless($user->can('sales.reprint') && in_array($format, self::FORMATS, true), 403);

        $this->audit->log('sale_reprinted', 'sales', "Invoice {$sale->invoice_no} reprinted ({$format}) by {$user->name}", $sale, user: $user);

        return $this->document($sale, $format, duplicate: true, autoprint: false);
    }

    /** The A4 invoice as a PDF file, for saving or e-mailing. It is a copy of an invoice already issued, so it is marked DUPLICATE and audited. */
    public function pdf(Request $request, Sale $sale): Response
    {
        $user = $request->user();
        $this->authorizeSale($user, $sale);
        abort_unless($user->can('sales.reprint'), 403);

        $this->audit->log('sale_reprinted', 'sales', "Invoice {$sale->invoice_no} downloaded as PDF by {$user->name}", $sale, user: $user);

        $html = $this->document($sale, 'a4', duplicate: true, autoprint: false, pdf: true)->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);          // nothing is ever fetched from the internet
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [base_path('storage'), base_path('resources')]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$sale->invoice_no.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        $this->authorize('sales.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->void->execute($sale, $d['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)->with('success', "Invoice {$sale->invoice_no} cancelled. The goods are back in stock and the payments were cancelled.");
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function document(Sale $sale, string $format, bool $duplicate, bool $autoprint, bool $pdf = false): View
    {
        $sale->load(['items', 'customer', 'cashier', 'allocations.payment.method']);

        $barcode = null;
        try {
            $barcode = (new BarcodeGeneratorSVG)->getBarcode($sale->invoice_no, BarcodeGenerator::TYPE_CODE_128, 1.4, 34);
        } catch (Throwable) {
            // the receipt is still valid without a barcode
        }

        $logo = null;
        $path = $this->settings->string('shop.logo_path');
        if ($this->settings->bool('receipt.show_logo') && $path !== '' && Storage::disk('local')->exists($path)) {
            // a PDF is built on the server, so the logo is embedded in it instead of being fetched by address
            $logo = $pdf ? 'data:'.(Storage::disk('local')->mimeType($path) ?: 'image/png').';base64,'.base64_encode(Storage::disk('local')->get($path)) : route('branding.logo');
        }

        return view($format === 'thermal' ? 'sales.print.thermal' : 'sales.print.a4', [
            'sale' => $sale, 'duplicate' => $duplicate, 'autoprint' => $autoprint, 'barcode' => $barcode, 'logo' => $logo, 'pdf' => $pdf,
            // dompdf does not draw inline SVG, but it does draw an SVG that arrives as an image
            'barcodeImage' => ($pdf && $barcode) ? 'data:image/svg+xml;base64,'.base64_encode($barcode) : null,
            's' => $this->settings,
            'copies' => $format === 'thermal' ? max(1, (int) $this->settings->get('printing.copies')) : 1,
        ]);
    }

    private function authorizeList(User $user): void
    {
        abort_unless($user->can('sales.view_all') || $user->can('sales.view_own'), 403);
    }

    private function authorizeSale(User $user, Sale $sale): void
    {
        $this->authorizeList($user);
        abort_unless($user->can('sales.view_all') || $sale->user_id === $user->id, 403);
    }

    /** @return Builder<Sale> */
    private function visibleTo(User $user)
    {
        return Sale::query()->when(! $user->can('sales.view_all'), fn ($q) => $q->where('user_id', $user->id));
    }
}
