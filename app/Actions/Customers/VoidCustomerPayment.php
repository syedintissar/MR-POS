<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\ServiceJob;
use App\Models\ServiceJobPayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\Sales\SaleAccountService;
use App\Services\Service\ServiceJobService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a payment that was recorded by mistake (SRS CUS-05). The ledger gets an opposite line (nothing is deleted) and every invoice the
 * payment had settled becomes unpaid again for that part. A payment taken at the till for a walk-in sale is cancelled by cancelling the sale.
 */
class VoidCustomerPayment
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly SaleAccountService $sales,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CustomerPayment $payment, string $reason, User $by): CustomerPayment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the payment.');
        }

        $customerId = CustomerPayment::query()->whereKey($payment->getKey())->value('customer_id');
        if ($customerId === null) {
            throw new DomainException('This payment no longer exists.');
        }

        return DB::transaction(function () use ($payment, $customerId, $reason, $by) {
            $customer = $this->accounts->lockCustomer((int) $customerId);
            $locked = CustomerPayment::query()->with('method')->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new DomainException("{$locked->receipt_no} has already been cancelled.");
            }
            if ($locked->type !== 'receipt') {
                throw new DomainException('Only a payment received from a customer can be cancelled here.');
            }
            if ($customer->is_walk_in) {
                throw new DomainException('This payment belongs to a till sale. Cancel the sale instead.');
            }

            $saleIds = $locked->allocations()->orderBy('sale_id')->pluck('sale_id')->all();
            $sales = Sale::query()->whereIn('id', $saleIds)->orderBy('id')->lockForUpdate()->get();

            $jobIds = ServiceJobPayment::query()->where('customer_payment_id', $locked->id)->orderBy('service_job_id')->pluck('service_job_id')->all();
            $jobs = ServiceJob::query()->whereIn('id', $jobIds)->orderBy('id')->lockForUpdate()->get();

            $locked->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();
            foreach ($sales as $sale) {
                $this->sales->refreshSale($sale);
            }
            foreach ($jobs as $job) {
                app(ServiceJobService::class)->refreshPayments($job);
            }

            $this->accounts->postEntry($customer, 'payment_void', (string) $locked->amount, '0', $locked, "Payment {$locked->receipt_no} cancelled: {$reason}", $by);

            $this->audit->log('customer_payment_voided', 'customers', "Payment {$locked->receipt_no} from {$customer->name} cancelled: {$reason}", $locked, new: [
                'reason' => $reason, 'amount' => (string) $locked->amount, 'invoices_reopened' => [...$sales->pluck('invoice_no')->all(), ...$jobs->pluck('job_no')->all()],
            ], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
