<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\DocumentNumberService;
use App\Services\Service\ServiceJobAccess;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Money taken from the customer while the job is open (SRV-07). It is a numbered receipt that pays the job, so the job's paid and due
 * figures change at once; a named customer's account is credited, the walk-in customer has no account (like a till receipt).
 * If the job is cancelled, the money is either kept as the customer's credit or handed back (CancelServiceJob).
 */
class TakeServiceAdvance
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{payment_method_id: int, amount: string, reference_no?: string|null}  $d
     */
    public function execute(ServiceJob $job, array $d, User $by): CustomerPayment
    {
        if (! $by->can('customer_payments.create')) {
            throw new AuthorizationException('You are not allowed to take payments.');
        }
        $amount = Decimal::round((string) ($d['amount'] ?? '0'), 2);
        if (! Decimal::isPositive($amount)) {
            throw new DomainException('Enter an amount greater than zero.');
        }

        // the customer is locked first (their account is also changed by payments and deliveries): learn who it is before the transaction starts
        $customerId = ServiceJob::query()->whereKey($job->getKey())->value('customer_id');
        if ($customerId === null) {
            throw new DomainException('This job no longer exists.');
        }
        $walkInId = Customer::walkIn()->id;

        return DB::transaction(function () use ($job, $d, $by, $amount, $customerId, $walkInId) {
            $customer = (int) $customerId === $walkInId ? Customer::query()->findOrFail($walkInId) : $this->accounts->lockCustomer((int) $customerId);
            $locked = ServiceJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! ServiceJobAccess::canSee($by, $locked)) {
                throw new AuthorizationException('You are not allowed to take a payment for this job.');
            }
            if ($locked->isTerminal()) {
                throw new DomainException("{$locked->job_no} is {$locked->statusLabel()}: no more advances can be taken.");
            }

            $method = PaymentMethod::query()->find((int) ($d['payment_method_id'] ?? 0));
            if (! $method || ! $method->is_active || $method->kind === 'customer_credit') {
                throw new DomainException('Choose a payment method that money can be received with.');
            }
            $reference = isset($d['reference_no']) && trim((string) $d['reference_no']) !== '' ? mb_substr(trim((string) $d['reference_no']), 0, 100) : null;
            if ($method->requires_reference && $reference === null) {
                throw new DomainException("{$method->name} payments need a reference (transaction, cheque or slip number).");
            }

            // never more than the job is worth (once it has a price or an estimate): the rest would only have to be handed back
            $ceiling = Decimal::cmp((string) $locked->grand_total, (string) ($locked->estimate_amount ?? '0')) >= 0 ? (string) $locked->grand_total : (string) $locked->estimate_amount;
            if (Decimal::isPositive($ceiling)) {
                $room = Decimal::sub($ceiling, (string) $locked->paid_amount, 2);
                if (Decimal::cmp($amount, $room) > 0) {
                    throw new DomainException('The job comes to '.Decimal::trim($ceiling).' and '.Decimal::trim((string) $locked->paid_amount).' has been paid, so at most '.Decimal::trim(Decimal::nonNegative($room, 2)).' can be taken as an advance.');
                }
            }

            $payment = new CustomerPayment;
            $payment->forceFill([
                'receipt_no' => $this->numbers->next('customer_payment'), 'customer_id' => $customer->id, 'payment_date' => now(),
                'payment_method_id' => $method->id, 'type' => 'receipt', 'amount' => $amount, 'reference_no' => $reference,
                'notes' => "Advance for service job {$locked->job_no}", 'status' => 'posted', 'created_by' => $by->id,
            ])->save();

            $this->accounts->addAllocation($payment->id, $locked, $amount);

            if (! $customer->is_walk_in) {
                $this->accounts->postEntry($customer, 'payment', '0', $amount, $payment, "Advance {$payment->receipt_no} · {$method->name} · job {$locked->job_no}", $by);
            }

            $this->audit->log('service_advance_taken', 'service', "Advance {$payment->receipt_no} of {$amount} taken for service job {$locked->job_no} ({$method->name})", $payment, new: [
                'job' => $locked->job_no, 'method' => $method->name, 'amount' => $amount, 'reference' => $reference,
            ], user: $by);

            return $payment->refresh();
        }, 3);
    }
}
