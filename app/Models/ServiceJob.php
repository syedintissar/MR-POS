<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A repair, refill or other service job. Nothing is mass-assignable: the service actions write every column deliberately. */
class ServiceJob extends Model
{
    public const RECEIVED = 'received';

    public const INSPECTION = 'inspection_pending';

    public const DIAGNOSING = 'diagnosing';

    public const WAITING_APPROVAL = 'waiting_for_approval';

    public const APPROVED = 'approved';

    public const IN_PROGRESS = 'in_progress';

    public const WAITING_PARTS = 'waiting_for_parts';

    public const COMPLETED = 'completed';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    /** status => label shown to people */
    public const STATUSES = [
        self::RECEIVED => 'Received', self::INSPECTION => 'Inspection pending', self::DIAGNOSING => 'Diagnosing',
        self::WAITING_APPROVAL => 'Waiting for approval', self::APPROVED => 'Approved', self::IN_PROGRESS => 'In progress',
        self::WAITING_PARTS => 'Waiting for parts', self::COMPLETED => 'Completed', self::DELIVERED => 'Delivered', self::CANCELLED => 'Cancelled',
    ];

    /**
     * The state machine (DATABASE.md §7.4): where a job may go from each status. "delivered" is reached only through the
     * deliver action (invoice + payment) and "cancelled" only through the cancel action; both are listed so the diagram is complete.
     */
    public const TRANSITIONS = [
        self::RECEIVED => [self::INSPECTION, self::DIAGNOSING, self::CANCELLED],
        self::INSPECTION => [self::DIAGNOSING, self::CANCELLED],
        self::DIAGNOSING => [self::WAITING_APPROVAL, self::IN_PROGRESS, self::CANCELLED],
        self::WAITING_APPROVAL => [self::APPROVED, self::CANCELLED],
        self::APPROVED => [self::IN_PROGRESS, self::CANCELLED],
        self::IN_PROGRESS => [self::WAITING_PARTS, self::COMPLETED, self::CANCELLED],
        self::WAITING_PARTS => [self::IN_PROGRESS, self::CANCELLED],
        self::COMPLETED => [self::IN_PROGRESS, self::DELIVERED],
        self::DELIVERED => [],
        self::CANCELLED => [],
    ];

    /** Parts can be added from diagnosing up to completed (SRV-05). */
    public const PART_STATUSES = [self::DIAGNOSING, self::WAITING_APPROVAL, self::APPROVED, self::IN_PROGRESS, self::WAITING_PARTS, self::COMPLETED];

    public const DEVICE_TYPES = ['printer' => 'Printer', 'toner' => 'Toner', 'cartridge' => 'Cartridge', 'other' => 'Other'];

    public const APPROVAL_METHODS = ['in_person' => 'In person', 'phone' => 'By phone', 'whatsapp' => 'WhatsApp'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime', 'expected_delivery_at' => 'datetime', 'approved_at' => 'datetime', 'completed_at' => 'datetime',
            'delivered_at' => 'datetime', 'invoiced_at' => 'datetime', 'cancelled_at' => 'datetime', 'tax_inclusive' => 'boolean',
            'estimate_amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'service_charges' => 'decimal:2', 'parts_amount' => 'decimal:2',
            'parts_cost' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2', 'due_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceJobItem::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ServiceStatusHistory::class)->orderBy('id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ServiceJobPayment::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isDelivered(): bool
    {
        return $this->status === self::DELIVERED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /** Delivered and cancelled jobs are locked. */
    public function isTerminal(): bool
    {
        return $this->isDelivered() || $this->isCancelled();
    }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function acceptsParts(): bool
    {
        return in_array($this->status, self::PART_STATUSES, true);
    }

    /** SRV-13: the promised date has passed and the job is not yet finished. */
    public function isOverdue(): bool
    {
        return $this->expected_delivery_at !== null && ! in_array($this->status, [self::COMPLETED, self::DELIVERED, self::CANCELLED], true)
            && $this->expected_delivery_at->isPast();
    }

    /** Jobs that still need the shop's attention (completed ones wait to be collected). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [self::DELIVERED, self::CANCELLED]);
    }

    /** Job number, invoice number, who it is for, or the device (serial number, model, cartridge model). */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $q->where(fn ($w) => $w->where('service_jobs.job_no', 'like', $like)->orWhere('service_jobs.invoice_no', 'like', $like)
            ->orWhere('service_jobs.contact_name', 'like', $like)->orWhere('service_jobs.contact_phone', 'like', $like)
            ->orWhere('service_jobs.serial_number', 'like', $like)->orWhere('service_jobs.model', 'like', $like)->orWhere('service_jobs.cartridge_model', 'like', $like)
            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like)));
    }
}
