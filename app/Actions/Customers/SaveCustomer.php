<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Exceptions\DuplicatePhoneException;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a customer's details. Balances are never touched here: they come only from the customer ledger (sales, payments
 * and the administrator's opening balance / corrections). The walk-in customer cannot be edited.
 */
class SaveCustomer
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, company?: string|null, phone?: string|null, whatsapp?: string|null, email?: string|null, address?: string|null, price_tier?: string|null, allow_credit?: bool, credit_limit?: string|null, notes?: string|null, is_active?: bool}  $data
     *
     * @throws DuplicatePhoneException when the phone number belongs to someone else and $allowDuplicatePhone is false
     */
    public function execute(array $data, User $by, ?Customer $customer = null, bool $allowDuplicatePhone = false): Customer
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new DomainException('Enter the customer\'s name.');
        }
        $tier = ($data['price_tier'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
        $allowCredit = (bool) ($data['allow_credit'] ?? false);
        $limit = $allowCredit && isset($data['credit_limit']) && $data['credit_limit'] !== '' ? Decimal::round((string) $data['credit_limit'], 2) : null;
        if ($limit !== null && Decimal::isNegative($limit)) {
            throw new DomainException('A credit limit cannot be negative.');
        }

        $clean = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;
        $values = [
            'name' => $name, 'company' => $clean($data['company'] ?? null), 'phone' => $clean($data['phone'] ?? null), 'whatsapp' => $clean($data['whatsapp'] ?? null),
            'email' => $clean($data['email'] ?? null), 'address' => $clean($data['address'] ?? null), 'price_tier' => $tier,
            'allow_credit' => $allowCredit, 'credit_limit' => $limit, 'notes' => $clean($data['notes'] ?? null), 'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        return DB::transaction(function () use ($values, $by, $customer, $allowDuplicatePhone) {
            if ($customer) {
                $customer = Customer::query()->withTrashed()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
                if ($customer->is_walk_in) {
                    throw new DomainException('The walk-in customer is part of the system and cannot be changed.');
                }
            }

            if (! $allowDuplicatePhone && $values['phone'] !== null) {
                $digits = Customer::normalizePhone($values['phone']);
                if (strlen($digits) >= 5) {
                    $same = Customer::query()->where('is_walk_in', false)->when($customer, fn ($q) => $q->where('id', '!=', $customer->id))
                        ->whereRaw("REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') = ?", [$digits])->orderBy('name')->limit(3)->pluck('name')->all();
                    if ($same !== []) {
                        throw new DuplicatePhoneException($same);
                    }
                }
            }

            if ($customer) {
                $old = $customer->only(array_keys($values));
                $customer->fill($values + ['updated_by' => $by->id])->save();
                $changed = array_filter($values, fn ($v, $k) => (string) ($old[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH);
                if ($changed !== []) {
                    $this->audit->log('customer_updated', 'customers', "Customer {$customer->code} · {$customer->name} updated", $customer, array_intersect_key($old, $changed), $changed, $by);
                }

                return $customer->refresh();
            }

            $created = new Customer;
            $created->fill($values + ['code' => $this->numbers->next('customer'), 'created_by' => $by->id, 'updated_by' => $by->id])->save();
            $this->audit->log('customer_created', 'customers', "Customer {$created->code} · {$created->name} created", $created, new: $values, user: $by);

            return $created->refresh();
        }, 3);
    }
}
