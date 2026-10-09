<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Services\Service\ServiceJobAccess;
use App\Services\Service\ServiceJobService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Takes a line off a job (SRV-05, SRV-06). Lines are never deleted, only marked removed.
 *  - a part goes back to stock (`service_return`, at the cost it left at), or is written off when it cannot be reused (it already left
 *    the stock when it was added, so nothing more moves; the cost stays on the line as a loss)
 *  - a charge simply stops counting
 */
class RemoveServiceLine
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ServiceJobService $jobs,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(ServiceJobItem $item, string $disposition, ?string $reason, User $by): ServiceJobItem
    {
        $jobId = ServiceJobItem::query()->whereKey($item->getKey())->value('service_job_id');
        if ($jobId === null) {
            throw new DomainException('This line no longer exists.');
        }

        return DB::transaction(function () use ($item, $jobId, $disposition, $reason, $by) {
            $job = ServiceJob::query()->whereKey($jobId)->lockForUpdate()->firstOrFail();
            $line = ServiceJobItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            $permission = $line->isPart() ? 'service_jobs.manage_parts' : 'service_jobs.set_charges';
            if (! ServiceJobAccess::can($by, $permission, $job)) {
                throw new AuthorizationException('You are not allowed to change this line.');
            }
            if ($job->isTerminal()) {
                throw new DomainException("{$job->job_no} is {$job->statusLabel()} and can no longer be changed.");
            }
            if (! $line->isActive()) {
                throw new DomainException('This line has already been removed.');
            }

            $disposition = $line->isPart() ? $disposition : null;
            if ($line->isPart() && ! in_array($disposition, ['returned_to_stock', 'written_off'], true)) {
                throw new DomainException('Say whether the part goes back to stock or is written off.');
            }
            $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null;
            if ($disposition === 'written_off' && $reason === null) {
                throw new DomainException('Give a reason for writing the part off (for example "damaged while fitting").');
            }

            if ($line->isPart()) {
                $this->inventory->lockProducts([$line->product_id]);
                if ($disposition === 'returned_to_stock') {
                    $this->inventory->move($line->product_id, (string) $line->quantity, 'service_return', [
                        'unit_cost' => (string) $line->unit_cost, 'source' => $job, 'source_line_id' => $line->id,
                        'notes' => "Part taken off service job {$job->job_no}", 'user' => $by,
                    ]);
                }
            }

            $line->forceFill([
                'status' => ServiceJobItem::REMOVED, 'removed_by' => $by->id, 'removed_at' => now(), 'removal_disposition' => $disposition, 'removal_reason' => $reason,
            ])->save();

            $job->forceFill(['updated_by' => $by->id])->save();
            $this->jobs->refresh($job);

            $this->audit->log($line->isPart() ? 'service_part_removed' : 'service_charge_removed', 'service',
                "Service job {$job->job_no}: {$line->description} removed".($disposition ? ' ('.str_replace('_', ' ', $disposition).')' : ''), $job, new: [
                    'line' => $line->description, 'quantity' => Decimal::trim((string) $line->quantity), 'disposition' => $disposition, 'reason' => $reason,
                ], user: $by);

            return $line->refresh();
        }, 3);
    }
}
