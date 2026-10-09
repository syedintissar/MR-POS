<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Service\AddServiceCharge;
use App\Actions\Service\AddServicePart;
use App\Actions\Service\CancelServiceJob;
use App\Actions\Service\DeliverServiceJob;
use App\Actions\Service\RemoveServiceLine;
use App\Actions\Service\TakeServiceAdvance;
use App\Actions\Service\UpdateServicePricing;
use App\Exceptions\InsufficientStockException;
use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use App\Services\Service\ServiceJobAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** What changes stock and money on a job: parts, charges, estimate and discount, advances, delivery and cancellation. */
class ServiceJobWorkController extends Controller
{
    public function __construct(
        private readonly AddServicePart $addPart,
        private readonly AddServiceCharge $addCharge,
        private readonly RemoveServiceLine $removeLine,
        private readonly UpdateServicePricing $pricing,
        private readonly TakeServiceAdvance $advance,
        private readonly DeliverServiceJob $deliver,
        private readonly CancelServiceJob $cancel,
    ) {}

    public function addPart(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless(ServiceJobAccess::can($request->user(), 'service_jobs.manage_parts', $job), 403);
        $d = $request->validate([
            'product_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], 'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ]);

        return $this->run(fn () => $this->addPart->execute($job, $d, $request->user()), 'Part added: the stock has been reduced.');
    }

    public function addCharge(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless(ServiceJobAccess::can($request->user(), 'service_jobs.set_charges', $job), 403);
        $d = $request->validate([
            'description' => ['required', 'string', 'max:255'], 'quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], 'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ]);

        return $this->run(fn () => $this->addCharge->execute($job, $d, $request->user()), 'Charge added.');
    }

    public function removeLine(Request $request, ServiceJob $job, ServiceJobItem $item): RedirectResponse
    {
        abort_unless($item->service_job_id === $job->id, 404);
        abort_unless(ServiceJobAccess::can($request->user(), $item->isPart() ? 'service_jobs.manage_parts' : 'service_jobs.set_charges', $job), 403);
        $d = $request->validate(['disposition' => ['nullable', 'in:returned_to_stock,written_off'], 'reason' => ['nullable', 'string', 'max:255']]);

        return $this->run(fn () => $this->removeLine->execute($item, $d['disposition'] ?? 'returned_to_stock', $d['reason'] ?? null, $request->user()),
            $item->isPart() ? 'The part was taken off the job.' : 'The charge was removed.');
    }

    public function updatePricing(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless(ServiceJobAccess::can($request->user(), 'service_jobs.set_charges', $job), 403);
        $d = $request->validate([
            'estimate_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], 'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ]);

        return $this->run(fn () => $this->pricing->execute($job, ['estimate_amount' => $d['estimate_amount'] ?? null, 'discount_amount' => $d['discount_amount'] ?? null], $request->user()), 'Estimate and discount saved.');
    }

    public function takeAdvance(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless($request->user()->can('customer_payments.create') && ServiceJobAccess::canSee($request->user(), $job), 403);
        $d = $request->validate([
            'payment_method_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'], 'reference_no' => ['nullable', 'string', 'max:100'],
        ], ['decimal' => 'Too many decimal places.']);

        try {
            $payment = $this->advance->execute($job, $d, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Advance {$payment->receipt_no} recorded.")
            ->with('receipt_url', URL::temporarySignedRoute('customer-payments.receipt', now()->addMinutes(10), ['payment' => $payment->id, 'autoprint' => 1]));
    }

    public function deliver(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless(ServiceJobAccess::can($request->user(), 'service_jobs.deliver', $job), 403);
        $d = $request->validate([
            'payments' => ['nullable', 'array', 'max:8'], 'payments.*.payment_method_id' => ['nullable', 'integer'], 'payments.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:100'],
        ], ['decimal' => 'Too many decimal places.']);

        $lines = collect($d['payments'] ?? [])->filter(fn ($p) => ! empty($p['payment_method_id']) && isset($p['amount']) && (float) $p['amount'] > 0)->values()->all();

        try {
            $after = $this->deliver->execute($job, $lines, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = "{$after->job_no} delivered and invoiced as {$after->invoice_no}.";
        $redirect = redirect()->route('service.jobs.show', $after)->with('success', $message)
            ->with('invoice_url', URL::temporarySignedRoute('service.jobs.invoice', now()->addMinutes(10), ['job' => $after->id, 'format' => 'thermal', 'autoprint' => 1]));

        return (float) $this->deliver->change > 0 ? $redirect->with('change', $this->deliver->change) : $redirect;
    }

    public function cancelJob(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless(ServiceJobAccess::can($request->user(), 'service_jobs.cancel', $job), 403);
        $d = $request->validate([
            'reason' => ['required', 'string', 'max:255'], 'parts' => ['nullable', 'array'], 'parts.*' => ['in:returned_to_stock,written_off'],
            'advance' => ['nullable', 'in:credit,refund'], 'refund_method_id' => ['nullable', 'integer'],
        ]);

        try {
            $after = $this->cancel->execute($job, $d['reason'], $request->user(), $d['parts'] ?? [], $d['advance'] ?? 'credit', isset($d['refund_method_id']) ? (int) $d['refund_method_id'] : null);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('service.jobs.show', $after)->with('success', "{$after->job_no} cancelled.");
    }

    private function run(callable $do, string $message): RedirectResponse
    {
        try {
            $do();
        } catch (DomainException|AuthorizationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (InsufficientStockException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }
}
