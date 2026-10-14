<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\ServiceJob;
use App\Models\ServiceJobPayment;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\Sales\SaleAccountService;
use App\Services\Service\ServiceJobService;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * THE ONLY code allowed to write a customer's ledger and to change customers.balance.
 *
 * Sign convention:  balance = SUM(debit) - SUM(credit).
 *   positive balance = the customer owes the shop;  negative = the shop holds the customer's money (credit).
 *
 * Money a customer has paid that is not (or no longer) settling any invoice is an ADVANCE; it is what "Customer credit" spends at the till.
 *
 * Lock order used everywhere (so two terminals can never deadlock): customer -> sales (by id) -> products (by id) -> number sequences -> audit trail.
 */
class CustomerAccountService
{
    public function lockCustomer(int $customerId): Customer
    {
        $this->assertInTransaction();

        return Customer::query()->withTrashed()->whereKey($customerId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Append one entry to the customer's account and move the cached balance by the same amount.
     *
     * @param  string  $debit  the customer owes more (a sale, a cancelled payment)
     * @param  string  $credit  the customer owes less (a payment, a cancelled sale)
     */
    public function postEntry(
        Customer|int $customer,
        string $type,
        string $debit,
        string $credit,
        ?Model $source,
        string $description,
        ?User $user = null,
        ?string $date = null,
    ): CustomerLedgerEntry {
        $this->assertInTransaction();

        $debit = Decimal::round($debit, 2);
        $credit = Decimal::round($credit, 2);
        if (Decimal::isPositive($debit) === Decimal::isPositive($credit)) {
            throw new InvalidArgumentException('A ledger entry needs exactly one of debit or credit, and it must be above zero.');
        }

        $c = $this->lockCustomer($customer instanceof Customer ? $customer->getKey() : $customer);
        if ($c->is_walk_in) {
            throw new LogicException('The walk-in customer has no account.');
        }

        $entry = new CustomerLedgerEntry;
        $entry->forceFill([
            'customer_id' => $c->id,
            'entry_date' => $date ?? now()->toDateString(),
            'entry_type' => $type,
            'debit' => $debit,
            'credit' => $credit,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'description' => mb_substr($description, 0, 255),
            'created_by' => ($user ?? auth()->user())?->id,
            'created_at' => now()->format('Y-m-d H:i:s.u'),
        ])->save();

        $newBalance = Decimal::sub(Decimal::add((string) $c->getRawOriginal('balance'), $debit, 2), $credit, 2);
        $c->forceFill(['balance' => $newBalance])->saveQuietly();

        if ($customer instanceof Customer) {
            $customer->forceFill(['balance' => $newBalance])->syncOriginal();
        }

        return $entry;
    }

    /** What the ledger says the balance is (the truth the cached column must agree with). */
    public function balanceFromLedger(int $customerId): string
    {
        $row = DB::selectOne('SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS b FROM customer_ledger_entries WHERE customer_id = ?', [$customerId]);

        return Decimal::round((string) $row->b, 2);
    }

    /**
     * Receipts of this customer that still hold money not applied to an invoice that stands, oldest first.
     * An allocation to a cancelled invoice does not count: that money is an advance again.
     *
     * @return list<array{id: int, free: string}>
     */
    public function advanceReceipts(int $customerId): array
    {
        $rows = DB::select(
            "SELECT cp.id, cp.amount - COALESCE((
                        SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
                         WHERE sp.customer_payment_id = cp.id AND s.status <> 'voided'), 0)
                    - COALESCE((
                        SELECT SUM(jp.amount) FROM service_job_payments jp JOIN service_jobs j ON j.id = jp.service_job_id
                         WHERE jp.customer_payment_id = cp.id AND j.status <> 'cancelled'), 0)
                    - COALESCE((
                        SELECT SUM(rf.amount) FROM customer_payments rf
                         WHERE rf.refund_of_id = cp.id AND rf.type = 'refund' AND rf.status = 'posted'), 0) AS free
               FROM customer_payments cp
              WHERE cp.customer_id = ? AND cp.status = 'posted' AND cp.type = 'receipt'
             HAVING free > 0
              ORDER BY cp.payment_date, cp.id",
            [$customerId],
        );

        return array_map(fn ($r) => ['id' => (int) $r->id, 'free' => Decimal::round((string) $r->free, 2)], $rows);
    }

    /**
     * Settles part of an invoice with money the customer already paid in (their advance), oldest receipt first. No money moves and the
     * ledger does not change (the customer's money was already on their account); only the invoice's paid / due figures do.
     *
     * @return list<array{receipt_no: string, amount: string}> which receipts were used
     */
    public function allocateAdvance(int $customerId, Sale|ServiceJob $sale, string $amount): array
    {
        $this->assertInTransaction();
        $amount = Decimal::round($amount, 2);
        if (! Decimal::isPositive($amount)) {
            throw new InvalidArgumentException('Nothing to allocate.');
        }

        $left = $amount;
        $used = [];
        foreach ($this->advanceReceipts($customerId) as $r) {
            if (! Decimal::isPositive($left)) {
                break;
            }
            $take = Decimal::cmp($left, $r['free']) < 0 ? $left : $r['free'];

            $this->addAllocation($r['id'], $sale, $take);
            $used[] = ['receipt_no' => (string) CustomerPayment::query()->whereKey($r['id'])->value('receipt_no'), 'amount' => $take];
            $left = Decimal::sub($left, $take, 2);
        }

        if (Decimal::isPositive($left)) {
            throw new \DomainException('The customer has only '.Decimal::sub($amount, $left, 2).' of credit, not '.$amount.'.');
        }

        return $used;
    }

    /**
     * Hands money back to the customer out of a receipt that is not (or no longer) settling anything: writes a `refund` payment that says
     * which receipt it gives back, and for a named customer a `refund` debit on the account (their credit shrinks by the same amount).
     * The walk-in customer has no account, so there is no ledger line.
     */
    public function refundReceipt(CustomerPayment $receipt, string $amount, PaymentMethod $method, User $by, string $why): CustomerPayment
    {
        $this->assertInTransaction();

        $amount = Decimal::round($amount, 2);
        if (! Decimal::isPositive($amount)) {
            throw new InvalidArgumentException('Nothing to refund.');
        }
        if ($method->kind === 'customer_credit' || ! $method->is_active) {
            throw new \DomainException('Choose a payment method that money can be handed back with.');
        }

        $free = collect($this->advanceReceipts($receipt->customer_id))->firstWhere('id', $receipt->id)['free'] ?? '0.00';
        if (Decimal::cmp($amount, $free) > 0) {
            throw new \DomainException("Only {$free} of receipt {$receipt->receipt_no} is not used for anything, so {$amount} cannot be refunded from it.");
        }

        $refund = new CustomerPayment;
        $refund->forceFill([
            'receipt_no' => app(DocumentNumberService::class)->next('customer_payment'),
            'customer_id' => $receipt->customer_id, 'payment_date' => now(), 'payment_method_id' => $method->id, 'type' => 'refund',
            'amount' => $amount, 'notes' => mb_substr($why, 0, 255), 'status' => 'posted', 'refund_of_id' => $receipt->id, 'created_by' => $by->id,
        ])->save();

        $customer = Customer::query()->withTrashed()->findOrFail($receipt->customer_id);
        if (! $customer->is_walk_in) {
            $this->postEntry($customer, 'refund', $amount, '0', $refund, "Refund {$refund->receipt_no} of {$receipt->receipt_no}: {$why}", $by);
        }

        return $refund;
    }

    /** Adds money to the allocation of a receipt to one invoice or one job (one row per pair), then refreshes that document's paid / due. */
    public function addAllocation(int $paymentId, Sale|ServiceJob $doc, string $amount): void
    {
        $this->assertInTransaction();

        if ($doc instanceof Sale) {
            $row = SalePayment::query()->where('customer_payment_id', $paymentId)->where('sale_id', $doc->id)->first();
            if ($row) {
                $row->update(['amount' => Decimal::add((string) $row->amount, $amount, 2)]);
            } else {
                SalePayment::query()->create(['customer_payment_id' => $paymentId, 'sale_id' => $doc->id, 'amount' => $amount]);
            }
            app(SaleAccountService::class)->refreshSale($doc);

            return;
        }

        $row = ServiceJobPayment::query()->where('customer_payment_id', $paymentId)->where('service_job_id', $doc->id)->first();
        if ($row) {
            $row->update(['amount' => Decimal::add((string) $row->amount, $amount, 2)]);
        } else {
            ServiceJobPayment::query()->create(['customer_payment_id' => $paymentId, 'service_job_id' => $doc->id, 'amount' => $amount]);
        }
        app(ServiceJobService::class)->refreshPayments($doc);
    }

    /**
     * What a named customer still owes on documents: completed invoices and delivered service jobs with something left to pay, oldest first.
     * With $lock the rows are locked (use it inside a transaction that already holds the customer lock).
     *
     * @return list<Sale|ServiceJob>
     */
    public function openDocuments(int $customerId, bool $lock = false): array
    {
        $sales = Sale::query()->where('customer_id', $customerId)->where('status', Sale::COMPLETED)->where('due_amount', '>', 0)->orderBy('id');
        $jobs = ServiceJob::query()->where('customer_id', $customerId)->where('status', ServiceJob::DELIVERED)->where('due_amount', '>', 0)->orderBy('id');
        if ($lock) {
            $sales->lockForUpdate();
            $jobs->lockForUpdate();
        }

        $docs = [...$sales->get()->all(), ...$jobs->get()->all()];
        usort($docs, fn ($a, $b) => [self::documentDate($a)->getTimestamp(), $a instanceof Sale ? 0 : 1, $a->id] <=> [self::documentDate($b)->getTimestamp(), $b instanceof Sale ? 0 : 1, $b->id]);

        return $docs;
    }

    /** The same list, as plain rows for screens and the aging report. */
    public function openRows(int $customerId): array
    {
        return array_map(fn (Sale|ServiceJob $d) => [
            'kind' => $d instanceof Sale ? 'sale' : 'job', 'key' => self::documentKey($d), 'id' => $d->id, 'no' => self::documentNo($d), 'date' => self::documentDate($d),
            'total' => (string) $d->grand_total, 'paid' => (string) $d->paid_amount, 'due' => (string) $d->due_amount,
            'url' => $d instanceof Sale ? route('sales.show', $d->id) : route('service.jobs.show', $d->id),
        ], $this->openDocuments($customerId));
    }

    public static function documentNo(Sale|ServiceJob $d): string
    {
        return $d instanceof Sale ? (string) $d->invoice_no : (string) $d->invoice_no.' ('.$d->job_no.')';
    }

    public static function documentDate(Sale|ServiceJob $d): Carbon
    {
        return $d instanceof Sale ? $d->sale_date : $d->invoiced_at;
    }

    /** "12" for an invoice (as before), "j7" for a service job: the key used by the collect-payment form. */
    public static function documentKey(Sale|ServiceJob $d): string
    {
        return $d instanceof Sale ? (string) $d->id : 'j'.$d->id;
    }

    /**
     * What the till needs to know about a customer. Money figures only for people who may see customer payments.
     *
     * @return array<string, mixed>
     */
    public function summary(Customer $c, bool $money): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'company' => $c->company, 'phone' => $c->phone,
            'tier' => $c->price_tier, 'allow_credit' => (bool) $c->allow_credit,
            'credit_limit' => $money && $c->credit_limit !== null ? (string) $c->credit_limit : null,
            'balance' => $money ? (string) $c->getRawOriginal('balance') : null,
            'advance' => $money ? $this->advanceFor($c->id) : null,
        ];
    }

    /** Money the customer has paid in that is not settling an invoice. It can be applied to invoices or spent at the till as "Customer credit". */
    public function advanceFor(int $customerId): string
    {
        return array_reduce($this->advanceReceipts($customerId), fn ($c, $r) => Decimal::add($c, $r['free'], 2), '0.00');
    }

    /**
     * The customer's account as a statement: an opening figure (everything before $from), the entries in the period with a running
     * balance, and the closing figure. Positive = the customer owes the shop.
     *
     * @return array{opening: string, rows: list<array{entry: CustomerLedgerEntry, balance: string}>, debit: string, credit: string, closing: string}
     */
    public function statement(int $customerId, ?string $from = null, ?string $to = null): array
    {
        $opening = '0.00';
        if ($from) {
            $row = DB::selectOne('SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS b FROM customer_ledger_entries WHERE customer_id = ? AND entry_date < ?', [$customerId, $from]);
            $opening = Decimal::round((string) $row->b, 2);
        }

        $entries = CustomerLedgerEntry::query()->where('customer_id', $customerId)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->orderBy('entry_date')->orderBy('id')->get();

        $running = $opening;
        $debit = $credit = '0.00';
        $rows = [];
        foreach ($entries as $e) {
            $running = Decimal::sub(Decimal::add($running, (string) $e->debit, 2), (string) $e->credit, 2);
            $debit = Decimal::add($debit, (string) $e->debit, 2);
            $credit = Decimal::add($credit, (string) $e->credit, 2);
            $rows[] = ['entry' => $e, 'balance' => $running];
        }

        return ['opening' => $opening, 'rows' => $rows, 'debit' => $debit, 'credit' => $credit, 'closing' => $running];
    }

    /**
     * Everything that should agree and does not. Used by the twice-daily integrity check; never repairs anything.
     *
     * @return list<string>
     */
    public function findDrift(): array
    {
        $problems = [];

        foreach (DB::select('SELECT c.id, c.code, c.name, c.balance, COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS ledger
                               FROM customers c LEFT JOIN customer_ledger_entries l ON l.customer_id = c.id
                              GROUP BY c.id, c.code, c.name, c.balance HAVING ABS(c.balance - ledger) > 0.004') as $r) {
            $problems[] = "Balance of customer {$r->code} · {$r->name} is {$r->balance} but their ledger adds up to {$r->ledger}.";
        }

        // every invoice of a named customer is on the account exactly once (and once more, reversed, if it was cancelled)
        foreach (DB::select("SELECT * FROM (
                                SELECT s.invoice_no, s.status, s.grand_total,
                                       COALESCE((SELECT SUM(l.debit) FROM customer_ledger_entries l WHERE l.entry_type = 'sale' AND l.source_type = 'sale' AND l.source_id = s.id), 0) AS booked,
                                       COALESCE((SELECT SUM(l.credit) FROM customer_ledger_entries l WHERE l.entry_type = 'sale_void' AND l.source_type = 'sale' AND l.source_id = s.id), 0) AS reversed
                                  FROM sales s JOIN customers c ON c.id = s.customer_id
                                 WHERE c.is_walk_in = 0 AND s.grand_total > 0) t
                              WHERE ABS(booked - grand_total) > 0.004 OR (status = 'voided' AND ABS(reversed - grand_total) > 0.004) OR (status = 'completed' AND reversed <> 0)") as $r) {
            $problems[] = "Invoice {$r->invoice_no} ({$r->grand_total}) is on the customer's account as {$r->booked}".($r->status === 'voided' ? " and reversed by {$r->reversed}" : '').'.';
        }

        // every receipt taken from a named customer is on the account, and reversed when it was cancelled
        foreach (DB::select("SELECT * FROM (
                                SELECT cp.receipt_no, cp.status, cp.amount,
                                       COALESCE((SELECT SUM(l.credit) FROM customer_ledger_entries l WHERE l.entry_type = 'payment' AND l.source_type = 'customer_payment' AND l.source_id = cp.id), 0) AS booked,
                                       COALESCE((SELECT SUM(l.debit) FROM customer_ledger_entries l WHERE l.entry_type = 'payment_void' AND l.source_type = 'customer_payment' AND l.source_id = cp.id), 0) AS reversed
                                  FROM customer_payments cp JOIN customers c ON c.id = cp.customer_id JOIN payment_methods pm ON pm.id = cp.payment_method_id
                                 WHERE c.is_walk_in = 0 AND cp.type = 'receipt' AND pm.kind <> 'customer_credit') t
                              WHERE ABS(booked - amount) > 0.004 OR (status = 'voided' AND ABS(reversed - amount) > 0.004) OR (status = 'posted' AND reversed <> 0)") as $r) {
            $problems[] = "Receipt {$r->receipt_no} ({$r->amount}) is on the customer's account as {$r->booked}".($r->status === 'voided' ? " and reversed by {$r->reversed}" : '').'.';
        }

        // every sales return of a named customer is on the account once (and reversed once when cancelled); store credit is a receipt in the
        // "Customer Credit" method with no ledger line of its own, which is why the receipt check above leaves it out
        foreach (DB::select("SELECT * FROM (
                                SELECT r.return_no, r.status, (r.total - r.restocking_fee) AS total,
                                       COALESCE((SELECT SUM(l.credit) FROM customer_ledger_entries l WHERE l.entry_type = 'sale_return' AND l.source_type = 'sale_return' AND l.source_id = r.id), 0) AS booked,
                                       COALESCE((SELECT SUM(l.debit) FROM customer_ledger_entries l WHERE l.entry_type = 'sale_return_void' AND l.source_type = 'sale_return' AND l.source_id = r.id), 0) AS reversed
                                  FROM sale_returns r JOIN customers c ON c.id = r.customer_id WHERE c.is_walk_in = 0) t
                              WHERE ABS(booked - total) > 0.004 OR (status = 'voided' AND ABS(reversed - total) > 0.004) OR (status = 'posted' AND reversed <> 0)") as $r) {
            $problems[] = "Return {$r->return_no} ({$r->total}) is on the customer's account as {$r->booked}".($r->status === 'voided' ? " and reversed by {$r->reversed}" : '').'.';
        }

        // every refund handed to a named customer is on the account exactly once
        foreach (DB::select("SELECT * FROM (
                                SELECT cp.receipt_no, cp.amount,
                                       COALESCE((SELECT SUM(l.debit) FROM customer_ledger_entries l WHERE l.entry_type = 'refund' AND l.source_type = 'customer_payment' AND l.source_id = cp.id), 0) AS booked
                                  FROM customer_payments cp JOIN customers c ON c.id = cp.customer_id
                                 WHERE c.is_walk_in = 0 AND cp.type = 'refund' AND cp.status = 'posted') t
                              WHERE ABS(booked - amount) > 0.004") as $r) {
            $problems[] = "Refund {$r->receipt_no} ({$r->amount}) is on the customer's account as {$r->booked}.";
        }

        return $problems;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('CustomerAccountService must be used inside a database transaction.');
        }
    }
}
