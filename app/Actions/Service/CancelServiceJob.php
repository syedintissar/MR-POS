<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use App\Models\ServiceJobPayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Services\Service\ServiceJobAccess;
use App\Services\Service\ServiceJobService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a job that will not be completed (SRV-09), for example because the customer declined the estimate.
 *  - every part on the job goes back to stock, or is written off when it cannot be reused (listed in `parts`: item id => disposition)
 *  - advances the customer paid are kept as their credit, or handed back (always handed back to the walk-in customer, who has no account)
 * Nothing is deleted: lines stay, marked removed, and the history says who cancelled and why.
 */
class CancelServiceJob
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly InventoryService $inventory,
        private readonly ServiceJobService $jobs,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<int|string, string>  $parts  item id => returned_to_stock | written_off (parts not listed go back to stock)
     * @param  string  $advance  'credit' = keep what the customer paid as their credit; 'refund' = hand it back
     */
    public function execute(ServiceJob $job, string $reason, User $by, array $parts = [], string $advance = 'credit', ?int $refundMethodId = null): ServiceJob
    {
        if (! $by->can('service_jobs.cancel')) {
            throw new AuthorizationException('You are not allowed to cancel service jobs.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the job.');
        }
        if (! in_array($advance, ['credit', 'refund'], true)) {
            throw new DomainException('Say whether the advance is kept as credit or handed back.');
        }

        $customerId = ServiceJob::query()->whereKey($job->getKey())->value('customer_id');
        if ($customerId === null) {
            throw new DomainException('This job no longer exists.');
        }
        $walkInId = Customer::walkIn()->id;   // read before the transaction: the customer lock must be its first read

        return DB::transaction(function () use ($job, $reason, $by, $parts, $advance, $refundMethodId, $customerId, $walkInId) {
            $customer = (int) $customerId === $walkInId ? Customer::query()->findOrFail($walkInId) : $this->accounts->lockCustomer((int) $customerId);
            $locked = ServiceJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! ServiceJobAccess::can($by, 'service_jobs.cancel', $locked)) {
                throw new AuthorizationException('You are not allowed to cancel this job.');
            }
            if ($locked->isTerminal()) {
                throw new DomainException("{$locked->job_no} is {$locked->statusLabel()} and can no longer be changed.");
            }
            if (! $locked->canTransitionTo(ServiceJob::CANCELLED)) {
                throw new DomainException("A job that is \"{$locked->statusLabel()}\" cannot be cancelled. Reopen it first.");
            }

            $items = ServiceJobItem::query()->where('service_job_id', $locked->id)->where('status', ServiceJobItem::ACTIVE)->where('line_type', ServiceJobItem::PART)->orderBy('id')->lockForUpdate()->get();
            $this->inventory->lockProducts($items->pluck('product_id')->all());

            $returned = [];
            $writtenOff = [];
            foreach ($items as $item) {
                $disposition = $parts[$item->id] ?? $parts[(string) $item->id] ?? 'returned_to_stock';
                if (! in_array($disposition, ['returned_to_stock', 'written_off'], true)) {
                    throw new DomainException('Say whether each part goes back to stock or is written off.');
                }
                if ($disposition === 'returned_to_stock') {
                    $this->inventory->move($item->product_id, (string) $item->quantity, 'service_return', [
                        'unit_cost' => (string) $item->unit_cost, 'source' => $locked, 'source_line_id' => $item->id,
                        'notes' => "Part back from cancelled service job {$locked->job_no}", 'user' => $by,
                    ]);
                    $returned[] = $item->description;
                } else {
                    $writtenOff[] = $item->description;
                }
                $item->forceFill([
                    'status' => ServiceJobItem::REMOVED, 'removed_by' => $by->id, 'removed_at' => now(), 'removal_disposition' => $disposition,
                    'removal_reason' => mb_substr("Job cancelled: {$reason}", 0, 255),
                ])->save();
            }

            // what the customer had paid towards this job (read before the job stops counting)
            $advances = ServiceJobPayment::query()->where('service_job_id', $locked->id)->orderBy('id')->get()
                ->filter(fn ($a) => CustomerPayment::query()->whereKey($a->customer_payment_id)->where('status', 'posted')->where('type', 'receipt')->exists());

            $from = $locked->status;
            $locked->forceFill(['status' => ServiceJob::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => mb_substr($reason, 0, 255), 'updated_by' => $by->id])->save();
            $this->jobs->refreshPayments($locked);   // a cancelled job owes and has been paid nothing: the money is now the customer's credit

            $refunded = '0.00';
            $kept = '0.00';
            $handBack = $advance === 'refund' || $customer->is_walk_in;
            if ($advances->isNotEmpty()) {
                $method = null;
                if ($handBack) {
                    $method = $refundMethodId
                        ? PaymentMethod::query()->find($refundMethodId)
                        : PaymentMethod::query()->where('kind', 'cash')->where('is_active', true)->orderBy('sort_order')->first();
                    if (! $method || ! $method->is_active) {
                        throw new DomainException('Choose how the advance is handed back.');
                    }
                }
                foreach ($advances as $a) {
                    if ($handBack) {
                        $this->accounts->refundReceipt(CustomerPayment::query()->findOrFail($a->customer_payment_id), (string) $a->amount, $method, $by, "Job {$locked->job_no} cancelled");
                        $refunded = Decimal::add($refunded, (string) $a->amount, 2);
                    } else {
                        $kept = Decimal::add($kept, (string) $a->amount, 2);
                    }
                }
            }

            $this->jobs->record($locked, $from, ServiceJob::CANCELLED, $by, $reason);
            $this->audit->log('service_job_cancelled', 'service', "Service job {$locked->job_no} cancelled: {$reason}", $locked, old: ['status' => $from], new: [
                'reason' => $reason, 'parts_returned' => $returned, 'parts_written_off' => $writtenOff, 'advance_refunded' => $refunded, 'advance_kept_as_credit' => $kept,
            ], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
