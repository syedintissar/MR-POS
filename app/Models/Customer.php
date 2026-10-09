<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Customers. Phase 5 only needs the one walk-in customer (every till sale is a walk-in sale until named customers, credit and
 * the customer ledger arrive in Phase 6); the table already has its full shape so Phase 6 adds code, not schema.
 */
class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Balance, opening balance and the walk-in flag are written only by dedicated code (the customer ledger service in Phase 6). */
    protected $fillable = [
        'code', 'name', 'company', 'phone', 'whatsapp', 'email', 'address', 'price_tier', 'allow_credit', 'credit_limit', 'notes', 'is_active',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'allow_credit' => 'boolean', 'is_walk_in' => 'boolean', 'is_active' => 'boolean',
            'credit_limit' => 'decimal:2', 'opening_balance' => 'decimal:2', 'balance' => 'decimal:2',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    public function serviceJobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class);
    }

    /** A customer with any history can only be deactivated, never deleted. */
    public function hasHistory(): bool
    {
        return $this->sales()->exists() || $this->payments()->exists() || $this->ledgerEntries()->exists() || $this->serviceJobs()->exists();
    }

    /** Phone number reduced to its digits, so "0300-1234567" and "0300 1234567" count as the same number. */
    public static function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    /** The one walk-in customer. Created if it is missing (for example on a database restored from an old backup). */
    public static function walkIn(): self
    {
        return static::query()->withTrashed()->where('is_walk_in', true)->first() ?? static::createWalkIn();
    }

    private static function createWalkIn(): self
    {
        $customer = new static;
        $customer->forceFill([
            'code' => 'WALK-IN', 'name' => 'Walk-in customer', 'price_tier' => 'retail', 'allow_credit' => false,
            'is_walk_in' => true, 'is_active' => true,
        ])->save();

        return $customer->refresh();
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)
            ->orWhere('code', 'like', $like)->orWhere('company', 'like', $like));
    }
}
