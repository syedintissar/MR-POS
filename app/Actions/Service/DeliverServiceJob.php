<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\ServiceJob;
use App\Models\ServiceJobPayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\DocumentNumberService;
use App\Services\Service\ServiceJobAccess;
use App\Services\Service\ServiceJobService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Hands the device back and invoices the job (SRV-08): assigns the service invoice number, puts the job total on the customer's
 * account, takes the final payment and locks the job. In one transaction, with the customer locked first.
 *
 *  - what the customer pays now may be split over several methods; only cash can be handed over in excess (the rest is change)
 *  - "Customer credit" spends money the customer paid in earlier for something else
 *  - what is not paid stays on the customer's account; that needs a named customer who may buy on credit (`sales.credit`) and stays
 *    within their credit limit (or `sales.override_credit_limit`). The walk-in customer pays in full.
 *  - advances taken earlier that turn out to be more than the job came to are given back (walk-in) or stay as the customer's credit
 */
class DeliverServiceJob
{
    /** Change to hand back after the last call (cash handed over in excess). */
    public string $change = '0.00';

    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly ServiceJobService $jobs,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{payment_method_id: int|string, amount: string, reference_no?: string|null}>  $payments
     */
    public function execute(ServiceJob $job, array $payments, User $by): ServiceJob
    {
        $this->change = '0.00';
        if (! $by->can('service_jobs.deliver')) {
            throw new AuthorizationException('You are not allowed to deliver and invoice jobs.');
        }

        $customerId = ServiceJob::query()->whereKey($job->getKey())->value('customer_id');
        if ($customerId === null) {
            throw new DomainException('This job no longer exists.');
        }
        $walkInId = Customer::walkIn()->id;   // read before the transaction: the customer lock must be its first read

        return DB::transaction(function () use ($job, $payments, $by, $customerId, $walkInId) {
            $customer = (int) $customerId === $walkInId ? Customer::query()->findOrFail($walkInId) : $this->accounts->lockCustomer((int) $customerId);
            $locked = ServiceJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! ServiceJobAccess::can($by, 'service_jobs.deliver', $locked)) {
                throw new AuthorizationException('You are not allowed to deliver this job.');
            }
            if ($locked->isDelivered()) {
                throw new DomainException("{$locked->job_no} has already been delivered ({$locked->invoice_no}).");
            }
            if ($locked->status !== ServiceJob::COMPLETED) {
                throw new DomainException("{$locked->job_no} is \"{$locked->statusLabel()}\". Only a completed job can be delivered: finish the work first.");
            }

            $this->jobs->refresh($locked);
            $total = (string) $locked->grand_total;

            $this->returnExcessAdvance($locked, $customer, $by);

            $paidBefore = (string) $locked->paid_amount;
            $due = Decimal::nonNegative(Decimal::sub($total, $paidBefore, 2), 2);
            $plan = $this->plan($payments, $due, $total, $customer, $locked, $by);

            $now = now();
            $invoiceNo = $this->numbers->next('service_invoice');

            if (! $customer->is_walk_in && Decimal::isPositive($total)) {
                $this->accounts->postEntry($customer, 'service_invoice', $total, '0', $locked, "Service invoice {$invoiceNo} · job {$locked->job_no}", $by);
            }

            $locked->forceFill([
                'status' => ServiceJob::DELIVERED, 'invoice_no' => $invoiceNo, 'invoiced_at' => $now, 'delivered_at' => $now,
                'delivered_by' => $by->id, 'updated_by' => $by->id,
            ])->save();

            $methods = [];
            foreach ($plan['lines'] as $line) {
                $payment = new CustomerPayment;
                $payment->forceFill([
                    'receipt_no' => $this->numbers->next('customer_payment'), 'customer_id' => $customer->id, 'payment_date' => $now,
                    'payment_method_id' => $line['method']->id, 'type' => 'receipt', 'amount' => $line['amount'], 'reference_no' => $line['reference'],
                    'notes' => "Payment for service job {$locked->job_no} ({$invoiceNo})", 'status' => 'posted', 'created_by' => $by->id,
                ])->save();
                $this->accounts->addAllocation($payment->id, $locked, $line['amount']);
                if (! $customer->is_walk_in) {
                    $this->accounts->postEntry($customer, 'payment', '0', $line['amount'], $payment, "Payment {$payment->receipt_no} · {$line['method']->name} · {$invoiceNo}", $by);
                }
                $methods[] = ['method' => $line['method']->name, 'amount' => $line['amount']];
            }
            if (Decimal::isPositive($plan['credit'])) {
                $this->accounts->allocateAdvance($customer->id, $locked, $plan['credit']);
                $methods[] = ['method' => 'Customer credit', 'amount' => $plan['credit']];
            }

            $this->jobs->refreshPayments($locked);
            $this->jobs->record($locked, ServiceJob::COMPLETED, ServiceJob::DELIVERED, $by, "Delivered and invoiced as {$invoiceNo}");

            $this->audit->log('service_job_delivered', 'service', "Service job {$locked->job_no} delivered and invoiced as {$invoiceNo} ({$total})", $locked, new: [
                'invoice_no' => $invoiceNo, 'customer' => $customer->name, 'total' => $total, 'advances' => $paidBefore, 'paid_now' => $methods,
                'on_account' => (string) $locked->due_amount, 'change' => $plan['change'],
            ], user: $by);

            $this->change = $plan['change'];

            return $locked->refresh();
        }, 3);
    }

    /**
     * If the advances taken are more than the job came to (the price went down), the extra stops being the job's: it stays as the named
     * customer's credit, or is handed back to a walk-in customer (who has no account to keep it on).
     */
    private function returnExcessAdvance(ServiceJob $job, Customer $customer, User $by): void
    {
        $excess = Decimal::sub((string) DB::table('service_job_payments as jp')
            ->join('customer_payments as cp', 'cp.id', '=', 'jp.customer_payment_id')
            ->where('jp.service_job_id', $job->id)->where('cp.status', 'posted')->where('cp.type', 'receipt')->sum('jp.amount'), (string) $job->grand_total, 2);
        if (! Decimal::isPositive($excess)) {
            return;
        }

        // take it off the newest receipts first
        $rows = ServiceJobPayment::query()->where('service_job_id', $job->id)->orderByDesc('id')->lockForUpdate()->get();
        $left = $excess;
        $trimmed = [];
        foreach ($rows as $row) {
            if (! Decimal::isPositive($left)) {
                break;
            }
            $take = Decimal::cmp($left, (string) $row->amount) < 0 ? $left : (string) $row->amount;
            $trimmed[] = [$row->customer_payment_id, $take];
            if (Decimal::cmp($take, (string) $row->amount) === 0) {
                $row->delete();
            } else {
                $row->update(['amount' => Decimal::sub((string) $row->amount, $take, 2)]);
            }
            $left = Decimal::sub($left, $take, 2);
        }
        $this->jobs->refreshPayments($job);

        if ($customer->is_walk_in) {
            $cash = PaymentMethod::query()->where('kind', 'cash')->where('is_active', true)->orderBy('sort_order')->first();
            if (! $cash) {
                throw new DomainException('There is no cash payment method to hand the extra advance back with.');
            }
            foreach ($trimmed as [$receiptId, $amount]) {
                $this->accounts->refundReceipt(CustomerPayment::query()->findOrFail($receiptId), $amount, $cash, $by, "Advance for {$job->job_no} was more than the job came to");
            }
        }
    }

    /**
     * Turns what the cashier typed into what is recorded.
     *
     * @param  list<array<string, mixed>>  $payments
     * @return array{lines: list<array{method: PaymentMethod, amount: string, reference: string|null}>, credit: string, change: string}
     */
    private function plan(array $payments, string $due, string $total, Customer $customer, ServiceJob $job, User $by): array
    {
        $lines = [];
        $credit = '0.00';
        foreach (array_values($payments) as $p) {
            $amount = Decimal::round((string) ($p['amount'] ?? '0'), 2);
            if (Decimal::isZero($amount)) {
                continue;
            }
            if (Decimal::isNegative($amount)) {
                throw new DomainException('A payment cannot be negative.');
            }
            $method = PaymentMethod::query()->find((int) ($p['payment_method_id'] ?? 0));
            if (! $method || ! $method->is_active) {
                throw new DomainException('Choose a payment method that can be used.');
            }
            if ($method->kind === 'customer_credit') {
                if ($customer->is_walk_in) {
                    throw new DomainException('Customer credit can only be used by a named customer.');
                }
                $credit = Decimal::add($credit, $amount, 2);

                continue;
            }
            $reference = isset($p['reference_no']) && trim((string) $p['reference_no']) !== '' ? mb_substr(trim((string) $p['reference_no']), 0, 100) : null;
            if ($method->requires_reference && $reference === null) {
                throw new DomainException("{$method->name} payments need a reference (transaction, cheque or slip number).");
            }
            $lines[] = ['method' => $method, 'amount' => $amount, 'reference' => $reference];
        }

        if (Decimal::isPositive($credit)) {
            $advance = $this->accounts->advanceFor($customer->id);
            if (Decimal::cmp($credit, $advance) > 0) {
                throw new DomainException("{$customer->name} has only {$advance} of credit, not {$credit}.");
            }
        }

        $real = array_reduce($lines, fn ($c, $l) => Decimal::add($c, $l['amount'], 2), '0.00');
        $paid = Decimal::add($real, $credit, 2);

        if (! Decimal::isPositive($due)) {
            if ($lines !== [] || Decimal::isPositive($credit)) {
                throw new DomainException('Nothing is left to pay on this job.');
            }

            return ['lines' => [], 'credit' => '0.00', 'change' => '0.00'];
        }

        if (Decimal::cmp($paid, $due) < 0) {
            $short = Decimal::sub($due, $paid, 2);
            if ($customer->is_walk_in) {
                throw new DomainException("The walk-in customer must pay in full: {$short} is still missing. Choose a named customer to leave it on account.");
            }
            if (! $customer->allow_credit) {
                throw new DomainException("{$customer->name} is not allowed credit, so the job must be paid in full ({$short} is still missing).");
            }
            if (! $by->can('sales.credit')) {
                throw new DomainException("{$short} would stay on {$customer->name}'s account, which needs a manager (you are not allowed to give credit).");
            }
            // what the customer would owe afterwards: today's balance (advances already in it), plus the invoice, minus what is paid now
            $after = Decimal::sub(Decimal::add((string) $customer->getRawOriginal('balance'), $total, 2), $real, 2);
            if ($customer->credit_limit !== null && Decimal::cmp($after, (string) $customer->credit_limit) > 0 && ! $by->can('sales.override_credit_limit')) {
                throw new DomainException("This would take {$customer->name} to {$after}, over their credit limit of ".Decimal::trim((string) $customer->credit_limit).'. A manager must deliver it.');
            }

            return ['lines' => $lines, 'credit' => $credit, 'change' => '0.00'];
        }

        $excess = Decimal::sub($paid, $due, 2);
        $change = '0.00';
        if (Decimal::isPositive($excess)) {
            $cash = array_reduce($lines, fn ($c, $l) => $l['method']->kind === 'cash' ? Decimal::add($c, $l['amount'], 2) : $c, '0.00');
            if (Decimal::cmp($excess, $cash) > 0) {
                throw new DomainException('Only cash can be handed over in excess. Card, bank and wallet payments must be exactly what is paid with them.');
            }
            $left = $excess;
            for ($i = count($lines) - 1; $i >= 0 && Decimal::isPositive($left); $i--) {
                if ($lines[$i]['method']->kind !== 'cash') {
                    continue;
                }
                $take = Decimal::cmp($left, $lines[$i]['amount']) < 0 ? $left : $lines[$i]['amount'];
                $lines[$i]['amount'] = Decimal::sub($lines[$i]['amount'], $take, 2);
                $left = Decimal::sub($left, $take, 2);
            }
            if (collect($lines)->contains(fn ($l) => Decimal::isZero($l['amount']))) {
                throw new DomainException('One payment line is not needed: the other lines already cover the amount. Remove it.');
            }
            $change = $excess;
        }

        return ['lines' => $lines, 'credit' => $credit, 'change' => $change];
    }
}
