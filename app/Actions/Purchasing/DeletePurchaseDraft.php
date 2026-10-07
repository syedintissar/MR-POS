<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Purchase;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Removes a purchase draft (and only a draft: a received purchase is cancelled with VoidPurchase, never deleted). */
class DeletePurchaseDraft
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(Purchase $draft, User $by): void
    {
        DB::transaction(function () use ($draft, $by) {
            $locked = Purchase::query()->with('supplier')->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw new DomainException("{$locked->label()} is not a draft and cannot be deleted.");
            }

            $summary = ['supplier' => $locked->supplier->name, 'invoice' => $locked->supplier_invoice_no, 'grand_total' => (string) $locked->grand_total];
            $this->audit->log('purchase_draft_deleted', 'purchasing', "Deleted purchase draft #{$locked->id} for {$locked->supplier->name}", $locked, old: $summary, user: $by);

            $locked->items()->delete();
            $locked->delete();
        }, 3);
    }
}
