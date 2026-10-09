<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Service\AssignServiceJob;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Actions\Service\ReceiveServiceJob;
use App\Actions\Service\TakeServiceAdvance;
use App\Actions\Service\UpdateServiceJob;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\Service\ServiceJobAccess;
use App\Services\SettingsService;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

/** Service jobs: the list, the job card, intake, status and the printed slip and invoice. Money and stock actions are in ServiceJobWorkController. */
class ServiceJobController extends Controller
{
    public function __construct(
        private readonly ReceiveServiceJob $receive,
        private readonly UpdateServiceJob $update,
        private readonly AssignServiceJob $assign,
        private readonly ChangeServiceJobStatus $status,
        private readonly TakeServiceAdvance $advance,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------------------------------------ list

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['service_jobs.view_all', 'service_jobs.view_assigned']), 403);
        $d = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'view' => ['nullable', 'in:open,ready,overdue,delivered,cancelled,all'], 'status' => ['nullable', Rule::in(array_keys(ServiceJob::STATUSES))],
            'technician' => ['nullable', 'string', 'max:10'], 'type' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);

        $visible = fn () => ServiceJobAccess::visibleTo($user);

        // a scanner "types" the job number from the slip's barcode and presses Enter: open that job
        $q = trim((string) ($d['q'] ?? ''));
        if ($q !== '' && ($hit = $visible()->where('job_no', strtoupper($q))->first())) {
            return redirect()->route('service.jobs.show', $hit);
        }

        $view = $d['view'] ?? 'open';
        $query = $visible()->with(['customer:id,name,phone,is_walk_in', 'serviceType:id,name', 'technician:id,name'])->search($q)
            ->when($view === 'open', fn ($w) => $w->open())
            ->when($view === 'ready', fn ($w) => $w->where('status', ServiceJob::COMPLETED))
            ->when($view === 'overdue', fn ($w) => $w->open()->whereNotIn('status', [ServiceJob::COMPLETED])->whereNotNull('expected_delivery_at')->where('expected_delivery_at', '<', now()))
            ->when($view === 'delivered', fn ($w) => $w->where('status', ServiceJob::DELIVERED))
            ->when($view === 'cancelled', fn ($w) => $w->where('status', ServiceJob::CANCELLED))
            ->when(! empty($d['status']), fn ($w) => $w->where('status', $d['status']))
            ->when(($d['technician'] ?? '') === 'mine', fn ($w) => $w->where('technician_id', $user->id))
            ->when(($d['technician'] ?? '') === 'none', fn ($w) => $w->whereNull('technician_id'))
            ->when(ctype_digit((string) ($d['technician'] ?? '')), fn ($w) => $w->where('technician_id', (int) $d['technician']))
            ->when(! empty($d['type']), fn ($w) => $w->where('service_type_id', (int) $d['type']))
            ->when(! empty($d['from']), fn ($w) => $w->whereDate('received_at', '>=', $d['from']))
            ->when(! empty($d['to']), fn ($w) => $w->whereDate('received_at', '<=', $d['to']));

        $counts = [
            'open' => $visible()->open()->count(),
            'ready' => $visible()->where('status', ServiceJob::COMPLETED)->count(),
            'overdue' => $visible()->open()->where('status', '<>', ServiceJob::COMPLETED)->whereNotNull('expected_delivery_at')->where('expected_delivery_at', '<', now())->count(),
        ];

        return view('service.jobs.index', [
            'jobs' => $query->orderByRaw("status = 'completed' DESC")->orderByDesc('priority')->orderBy('expected_delivery_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'counts' => $counts, 'view' => $view, 'types' => ServiceType::query()->orderBy('sort_order')->get(['id', 'name']),
            'technicians' => $this->technicians(), 'seeAll' => $user->can('service_jobs.view_all'),
            'seeMoney' => $user->can('customer_payments.view'),
        ]);
    }

    // ------------------------------------------------------------------------------------------------ intake

    public function create(Request $request): View
    {
        $this->authorize('service_jobs.create');
        $user = $request->user();

        $customer = $request->filled('customer') ? Customer::query()->where('is_walk_in', false)->where('is_active', true)->find($request->integer('customer')) : null;

        return view('service.jobs.form', [
            'job' => (new ServiceJob)->forceFill(['device_type' => 'printer', 'priority' => 'normal']), 'customer' => $customer, 'types' => $this->activeTypes(), 'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'technicians' => $this->technicians(), 'canAssign' => $user->can('service_jobs.assign'), 'canEstimate' => $user->can('service_jobs.set_charges'),
            'canAdvance' => $user->can('customer_payments.create'), 'methods' => PaymentMethod::query()->forTill()->get(),
            'canWorkshop' => $user->can('service_jobs.manage_parts'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('service_jobs.create');
        $d = $request->validate($this->rules(true));

        try {
            $job = $this->receive->execute($d + ['add_default_charge' => $request->boolean('add_default_charge')], $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $notes = [];
        if ($request->filled('advance_amount') && (float) $request->input('advance_amount') > 0) {
            try {
                $this->advance->execute($job, ['payment_method_id' => (int) $request->input('advance_method'), 'amount' => (string) $request->input('advance_amount'), 'reference_no' => $request->input('advance_reference')], $request->user());
            } catch (DomainException|AuthorizationException $e) {
                $notes[] = 'The job was received, but the advance was not taken: '.$e->getMessage();
            }
        }

        $redirect = redirect()->route('service.jobs.show', $job)->with('success', "Job {$job->job_no} received.")
            ->with('slip_url', URL::temporarySignedRoute('service.jobs.slip', now()->addMinutes(10), ['job' => $job->id, 'format' => $this->settings->string('printing.default_format') === 'a4' ? 'a4' : 'thermal', 'autoprint' => 1]));

        return $notes ? $redirect->with('error', implode(' ', $notes)) : $redirect;
    }

    // ------------------------------------------------------------------------------------------------ the job card

    public function show(Request $request, ServiceJob $job): View
    {
        $user = $request->user();
        $this->authorizeSee($user, $job);
        $job->load(['customer', 'serviceType', 'brand', 'technician', 'creator', 'deliverer', 'canceller', 'approver', 'history.user', 'allocations.payment.method']);

        $items = $job->items()->with(['product:id,name,sku,unit_id', 'adder:id,name', 'remover:id,name'])->get();
        $canCost = $user->can('products.view_cost');

        $next = collect(ServiceJob::TRANSITIONS[$job->status] ?? [])
            ->reject(fn ($s) => in_array($s, [ServiceJob::DELIVERED, ServiceJob::CANCELLED], true))
            ->filter(fn () => ServiceJobAccess::can($user, 'service_jobs.change_status', $job))->values();

        return view('service.jobs.show', [
            'job' => $job, 'active' => $items->where('status', 'active')->values(), 'removed' => $items->where('status', 'removed')->values(),
            'next' => $next,
            'can' => [
                'edit' => ServiceJobAccess::can($user, 'service_jobs.edit', $job) && ! $job->isTerminal(),
                'assign' => ServiceJobAccess::can($user, 'service_jobs.assign', $job) && ! $job->isTerminal(),
                'parts' => ServiceJobAccess::can($user, 'service_jobs.manage_parts', $job) && ! $job->isTerminal(),
                'charges' => ServiceJobAccess::can($user, 'service_jobs.set_charges', $job) && ! $job->isTerminal(),
                'deliver' => ServiceJobAccess::can($user, 'service_jobs.deliver', $job) && $job->status === ServiceJob::COMPLETED,
                'cancel' => ServiceJobAccess::can($user, 'service_jobs.cancel', $job) && $job->canTransitionTo(ServiceJob::CANCELLED),
                'advance' => $user->can('customer_payments.create') && ! $job->isTerminal(),
                'money' => $user->can('customer_payments.view'),
                'cost' => $canCost, 'reprint' => $user->can('sales.reprint'),
            ],
            'technicians' => $this->technicians(), 'methods' => PaymentMethod::query()->forTill()->get(), 'creditMethodId' => PaymentMethod::query()->where('kind', 'customer_credit')->value('id'),
            'advanceLeft' => $job->customer->is_walk_in || ! $user->can('customer_payments.view') ? '0.00' : app(CustomerAccountService::class)->advanceFor($job->customer_id),
            'slipUrl' => session('slip_url'), 'invoiceUrl' => session('invoice_url'), 'receiptUrl' => session('receipt_url'), 'change' => session('change'),
            'autoSlip' => session('slip_url') && $this->settings->bool('printing.auto_print_job_slip'),
            'autoInvoice' => session('invoice_url') && $this->settings->bool('printing.auto_print_sale'),
            'whatsapp' => $this->whatsappLink($job),
        ]);
    }

    public function edit(Request $request, ServiceJob $job): View
    {
        $user = $request->user();
        abort_unless(ServiceJobAccess::can($user, 'service_jobs.edit', $job), 403);
        abort_if($job->isTerminal(), 403, "{$job->job_no} is {$job->statusLabel()} and can no longer be changed.");

        return view('service.jobs.form', [
            'job' => $job->load('customer'), 'customer' => $job->customer->is_walk_in ? null : $job->customer, 'types' => $this->activeTypes($job->service_type_id), 'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'technicians' => $this->technicians(), 'canAssign' => false, 'canEstimate' => false, 'canAdvance' => false, 'methods' => collect(),
            'canWorkshop' => $user->can('service_jobs.manage_parts'),
        ]);
    }

    public function updateJob(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless(ServiceJobAccess::can($request->user(), 'service_jobs.edit', $job), 403);
        $d = $request->validate($this->rules(false));

        try {
            $this->update->execute($job, $d, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('service.jobs.show', $job)->with('success', 'Job saved.');
    }

    public function assignTechnician(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless(ServiceJobAccess::can($request->user(), 'service_jobs.assign', $job), 403);
        $d = $request->validate(['technician_id' => ['nullable', 'integer']]);

        try {
            $this->assign->execute($job, $d['technician_id'] ?? null, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The job has been assigned.');
    }

    public function changeStatus(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless(ServiceJobAccess::can($request->user(), 'service_jobs.change_status', $job), 403);
        $d = $request->validate(['to' => ['required', Rule::in(array_keys(ServiceJob::STATUSES))], 'note' => ['nullable', 'string', 'max:255'], 'approval_method' => ['nullable', Rule::in(array_keys(ServiceJob::APPROVAL_METHODS))]]);

        try {
            $after = $this->status->execute($job, $d['to'], $request->user(), ['note' => $d['note'] ?? null, 'approval_method' => $d['approval_method'] ?? null]);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$after->job_no} is now \"{$after->statusLabel()}\".");
    }

    // ------------------------------------------------------------------------------------------------ pickers for the forms (JSON, no money, no cost)

    public function productSearch(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);

        $rows = Product::query()->active()->stockItems()->with('unit:id,short_name')->search($request->input('q'))->orderBy('name')->limit(12)->get();

        return response()->json($rows->map(fn (Product $p) => [
            'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit?->short_name, 'stock' => (string) $p->getRawOriginal('current_stock'),
            'price' => (string) $p->sale_price, 'wholesale_price' => $p->wholesale_price !== null ? (string) $p->wholesale_price : null,
        ])->values());
    }

    public function customerSearch(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);
        $rows = Customer::query()->where('is_walk_in', false)->where('is_active', true)->search($request->input('q'))->orderBy('name')->limit(10)->get();

        return response()->json($rows->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'code' => $c->code])->values());
    }

    // ------------------------------------------------------------------------------------------------ printing

    /** The original job slip, through a signed link that lasts ten minutes (the intake page opens it). */
    public function slip(Request $request, ServiceJob $job, string $format): View
    {
        return $this->document($job, 'slip', $this->format($format), false, $request->boolean('autoprint'));
    }

    public function reprintSlip(Request $request, ServiceJob $job, string $format): View
    {
        $this->authorizePrint($request->user(), $job);
        $this->audit->log('service_slip_reprinted', 'service', "Job slip {$job->job_no} reprinted by {$request->user()->name}", $job, user: $request->user());

        return $this->document($job, 'slip', $this->format($format), true, false);
    }

    public function invoice(Request $request, ServiceJob $job, string $format): View
    {
        abort_unless($job->isDelivered(), 404);

        return $this->document($job, 'invoice', $this->format($format), false, $request->boolean('autoprint'));
    }

    public function reprintInvoice(Request $request, ServiceJob $job, string $format): View
    {
        $this->authorizePrint($request->user(), $job);
        abort_unless($job->isDelivered(), 404);
        $this->audit->log('service_invoice_reprinted', 'service', "Service invoice {$job->invoice_no} reprinted by {$request->user()->name}", $job, user: $request->user());

        return $this->document($job, 'invoice', $this->format($format), true, false);
    }

    public function invoicePdf(Request $request, ServiceJob $job): Response
    {
        $this->authorizePrint($request->user(), $job);
        abort_unless($job->isDelivered(), 404);
        $this->audit->log('service_invoice_pdf', 'service', "Service invoice {$job->invoice_no} downloaded as PDF by {$request->user()->name}", $job, user: $request->user());

        $html = $this->document($job, 'invoice', 'a4', true, false, true)->render();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$job->invoice_no.'.pdf"', 'Cache-Control' => 'private, no-store',
        ]);
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function document(ServiceJob $job, string $doc, string $format, bool $duplicate, bool $autoprint, bool $pdf = false): View
    {
        $job->load(['customer', 'serviceType', 'brand', 'technician', 'creator', 'deliverer', 'allocations.payment.method']);
        $items = $job->items()->where('status', 'active')->get();

        $number = $doc === 'invoice' ? (string) $job->invoice_no : $job->job_no;
        $barcode = null;
        try {
            $barcode = (new BarcodeGeneratorSVG)->getBarcode($number, BarcodeGenerator::TYPE_CODE_128, 1.4, 34);
        } catch (Throwable) {
            // the document is still valid without a barcode
        }

        $logo = null;
        $path = $this->settings->string('shop.logo_path');
        if ($this->settings->bool('receipt.show_logo') && $path !== '' && Storage::disk('local')->exists($path)) {
            $logo = $pdf ? 'data:'.(Storage::disk('local')->mimeType($path) ?: 'image/png').';base64,'.base64_encode(Storage::disk('local')->get($path)) : route('branding.logo');
        }

        return view($format === 'thermal' ? 'service.print.thermal' : 'service.print.a4', [
            'job' => $job, 'items' => $items, 'doc' => $doc, 'number' => $number, 'duplicate' => $duplicate, 'autoprint' => $autoprint, 'barcode' => $barcode, 'logo' => $logo, 'pdf' => $pdf,
            'barcodeImage' => ($pdf && $barcode) ? 'data:image/svg+xml;base64,'.base64_encode($barcode) : null,
            's' => $this->settings, 'copies' => $format === 'thermal' ? max(1, (int) $this->settings->get('printing.copies')) : 1,
        ]);
    }

    private function format(string $format): string
    {
        abort_unless(in_array($format, ['thermal', 'a4'], true), 404);

        return $format;
    }

    private function authorizeSee(User $user, ServiceJob $job): void
    {
        abort_unless(ServiceJobAccess::canSee($user, $job), 403);
    }

    private function authorizePrint(User $user, ServiceJob $job): void
    {
        abort_unless($user->can('sales.reprint') && ServiceJobAccess::canSee($user, $job), 403);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $common = [
            'contact_name' => ['nullable', 'string', 'max:150'], 'contact_phone' => ['nullable', 'string', 'max:30'],
            'service_type_id' => [$creating ? 'required' : 'sometimes', 'integer'], 'device_type' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(ServiceJob::DEVICE_TYPES))],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'], 'model' => ['nullable', 'string', 'max:100'], 'serial_number' => ['nullable', 'string', 'max:100'],
            'cartridge_model' => ['nullable', 'string', 'max:100'], 'reported_problem' => ['required', 'string', 'max:2000'], 'accessories_received' => ['nullable', 'string', 'max:255'],
            'expected_delivery_at' => ['nullable', 'date'], 'priority' => ['nullable', 'in:normal,urgent'], 'customer_notes' => ['nullable', 'string', 'max:2000'],
            'diagnosis' => ['nullable', 'string', 'max:2000'], 'internal_notes' => ['nullable', 'string', 'max:2000'],
        ];

        return $creating ? $common + [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'technician_id' => ['nullable', 'integer'], 'estimate_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'advance_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], 'advance_method' => ['nullable', 'integer'], 'advance_reference' => ['nullable', 'string', 'max:100'],
        ] : $common;
    }

    /** @return Collection<int, User> */
    private function technicians()
    {
        return User::query()->permission('service_jobs.view_assigned')->where('is_active', true)->orderBy('name')->get(['users.id', 'users.name']);
    }

    /** @return Collection<int, ServiceType> */
    private function activeTypes(?int $keep = null)
    {
        return ServiceType::query()->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($w) => $w->orWhere('id', $keep)))->orderBy('sort_order')->get();
    }

    /** SRV-15: a pre-filled WhatsApp message that opens only when someone clicks it (needs internet at that moment, never blocks work). */
    private function whatsappLink(ServiceJob $job): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) ($job->contact_phone ?: $job->customer->phone));
        if ($phone === '' || strlen($phone) < 9) {
            return null;
        }
        if (str_starts_with($phone, '0')) {
            $phone = '92'.substr($phone, 1);   // Pakistan numbers: 03xx... -> 923xx...
        }
        $shop = $this->settings->string('shop.name') ?: config('app.name');
        $text = match ($job->status) {
            ServiceJob::COMPLETED => "Assalam o Alaikum, your {$job->serviceType->name} job {$job->job_no} is ready for collection. {$shop}",
            ServiceJob::WAITING_APPROVAL => "Assalam o Alaikum, we have inspected your device (job {$job->job_no}). Please call us to approve the estimate. {$shop}",
            default => "Assalam o Alaikum, an update on your job {$job->job_no}: it is {$job->statusLabel()}. {$shop}",
        };

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($text);
    }
}
