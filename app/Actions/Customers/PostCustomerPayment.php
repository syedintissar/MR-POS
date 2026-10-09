<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\DocumentNumberService;
use App\Services\Sales\SaleAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Records money received from a customer (SRS CUS-04).
 *
 *  allocation 'auto'    : settles the oldest unpaid invoices first; whatever is left over stays as the customer's advance (credit)
 *  allocation 'manual'  : settles exactly the invoices (and amounts) given in `allocations` [sale_id => amount; "j7" => amount for a delivered service job]
 *  allocation 'advance' : settles nothing; the whole amount is kept as an advance
 *
 * All-or-nothing. The customer row is locked first, so two cashiers collecting from the same customer (or paying the same invoice) at the
 * same moment are handled one after the other and can never settle an invoice twice.
 */
class PostCustomerPayment
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly SaleAccountService $sales,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{customer_id: int, payment_method_id: int, amount: string, reference_no?: string|null, notes?: string|null, allocation?: string, allocations?: array<int|string, string>}  $data
     */
    public function execute(array $data, User $by): CustomerPayment
    {
        $amount = Decimal::round((string) $data['amount'], 2);
        if (! Decimal::isPositive($amount)) {
            throw new DomainException('Enter an amount greater than zero.');
        }

        $mode = $data['allocation'] ?? 'auto';
        if (! in_array($mode, ['auto', 'manual', 'advance'], true)) {
            throw new DomainException('Unknown way of applying the payment.');
        }

        return DB::transaction(function () use ($data, $amount, $mode, $by) {
            $customer = $this->accounts->lockCustomer((int) $data['customer_id']);
            if ($customer->is_walk_in) {
                throw new DomainException('The walk-in customer pays at the till and has no account to collect on.');
            }

            $method = PaymentMethod::query()->find((int) $data['payment_method_id']);
            if (! $method || ! $method->is_active || $method->kind === 'customer_credit') {
                throw new DomainException('Choose a payment method that money can be received with.');
            }
            $reference = isset($data['reference_no']) && trim((string) $data['reference_no']) !== '' ? mb_substr(trim((string) $data['reference_no']), 0, 100) : null;
            if ($method->requires_reference && $reference === null) {
                throw new DomainException("{$method->name} payments need a reference (transaction, cheque or slip number).");
            }

            $plan = match ($mode) {
                'auto' => $this->planAuto($customer->id, $amount),
                'manual' => $this->planManual($customer->id, $amount, $data['allocations'] ?? []),
                default => [],
            };

            $payment = new CustomerPayment;
            $payment->forceFill([
                'receipt_no' => $this->numbers->next('customer_payment'),
                'customer_id' => $customer->id,
                'payment_date' => now(),
                'payment_method_id' => $method->id,
                'type' => 'receipt',
                'amount' => $amount,
                'reference_no' => $reference,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 255) : null,
                'status' => 'posted',
                'created_by' => $by->id,
            ])->save();

            $settled = [];
            $allocated = '0.00';
            foreach ($plan as [$doc, $part]) {
                $this->accounts->addAllocation($payment->id, $doc, $part);
                $settled[] = ['invoice' => $doc instanceof Sale ? $doc->invoice_no : $doc->invoice_no.' ('.$doc->job_no.')', 'amount' => $part];
                $allocated = Decimal::add($allocated, $part, 2);
            }

            $this->accounts->postEntry($customer, 'payment', '0', $amount, $payment,
                "Payment {$payment->receipt_no} · {$method->name}".($reference ? " · {$reference}" : ''), $by);

            $this->audit->log('customer_payment_posted', 'customers', "Payment {$payment->receipt_no} of {$amount} from {$customer->name} ({$method->name})", $payment, new: [
                'customer' => $customer->name, 'method' => $method->name, 'amount' => $amount, 'reference' => $reference, 'settled' => $settled,
                'advance' => Decimal::sub($amount, $allocated, 2),
            ], user: $by);

            return $payment->load('allocations.sale', 'method', 'customer');
        }, 3);
    }

    /** Oldest unpaid invoices and delivered jobs first. @return list<array{0: Sale|ServiceJob, 1: string}> */
    private function planAuto(int $customerId, string $amount): array
    {
        $plan = [];
        $left = $amount;

        foreach ($this->accounts->openDocuments($customerId, true) as $doc) {
            if (! Decimal::isPositive($left)) {
                break;
            }
            $part = Decimal::cmp($left, (string) $doc->due_amount) < 0 ? $left : Decimal::round((string) $doc->due_amount, 2);
            $plan[] = [$doc, $part];
            $left = Decimal::sub($left, $part, 2);
        }

        return $plan;
    }

    /**
     * @param  array<int|string, string>  $allocations  "12" => amount for invoice 12, "j7" => amount for service job 7
     * @return list<array{0: Sale|ServiceJob, 1: string}>
     */
    private function planManual(int $customerId, string $amount, array $allocations): array
    {
        $wanted = [];
        foreach ($allocations as $key => $part) {
            $part = Decimal::round((string) $part, 2);
            if (Decimal::isNegative($part)) {
                throw new DomainException('An invoice cannot be paid a negative amount.');
            }
            if (Decimal::isPositive($part)) {
                $wanted[(string) $key] = $part;
            }
        }
        if ($wanted === []) {
            throw new DomainException('Enter how much of the payment goes to which invoice, or choose "keep as advance".');
        }

        $saleIds = [];
        $jobIds = [];
        foreach (array_keys($wanted) as $key) {
            $key = (string) $key;
            if (preg_match('/^j(\d+)$/', $key, $m)) {
                $jobIds[] = (int) $m[1];
            } elseif (ctype_digit($key)) {
                $saleIds[] = (int) $key;
            } else {
                throw new DomainException('One of the invoices is not valid.');
            }
        }
        $sales = Sale::query()->whereIn('id', $saleIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $jobs = ServiceJob::query()->whereIn('id', $jobIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $plan = [];
        $total = '0.00';
        foreach ($wanted as $key => $part) {
            $key = (string) $key;
            $isJob = $key[0] === 'j';
            $doc = $isJob ? ($jobs[(int) substr($key, 1)] ?? null) : ($sales[(int) $key] ?? null);
            if (! $doc || $doc->customer_id !== $customerId) {
                throw new DomainException('One of the invoices does not belong to this customer.');
            }
            $no = $isJob ? $doc->invoice_no : $doc->invoice_no;
            if ($isJob ? $doc->status !== ServiceJob::DELIVERED : $doc->isVoided()) {
                throw new DomainException($isJob ? "Service job {$doc->job_no} has not been invoiced (or was cancelled), so it cannot be paid here." : "{$no} has been cancelled.");
            }
            if (Decimal::cmp($part, (string) $doc->due_amount) > 0) {
                throw new DomainException(($isJob ? $no : $no)." has only {$doc->due_amount} left to pay, not {$part}.");
            }
            $plan[] = [$doc, $part];
            $total = Decimal::add($total, $part, 2);
        }

        if (Decimal::cmp($total, $amount) > 0) {
            throw new DomainException("The amounts given to the invoices ({$total}) are more than the payment ({$amount}).");
        }

        return $plan;
    }
}
