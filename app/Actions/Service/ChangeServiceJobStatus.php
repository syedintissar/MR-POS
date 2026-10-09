<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Service\ServiceJobAccess;
use App\Services\Service\ServiceJobService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Moves a job along the state machine (SRV-03). Every move writes a history line. "Delivered" and "cancelled" are not reachable from here:
 * delivering takes the invoice and the payment (DeliverServiceJob), cancelling returns parts and settles advances (CancelServiceJob).
 */
class ChangeServiceJobStatus
{
    public function __construct(private readonly ServiceJobService $jobs, private readonly AuditLogger $audit) {}

    /**
     * @param  array{note?: string|null, approval_method?: string|null}  $extra
     */
    public function execute(ServiceJob $job, string $to, User $by, array $extra = []): ServiceJob
    {
        if (! array_key_exists($to, ServiceJob::STATUSES)) {
            throw new DomainException('Unknown status.');
        }
        if (in_array($to, [ServiceJob::DELIVERED, ServiceJob::CANCELLED], true)) {
            throw new DomainException($to === ServiceJob::DELIVERED ? 'A job is delivered with "Deliver and invoice".' : 'A job is cancelled with "Cancel job".');
        }

        return DB::transaction(function () use ($job, $to, $by, $extra) {
            // the row decides, not what the screen showed a moment ago
            $locked = ServiceJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! ServiceJobAccess::can($by, 'service_jobs.change_status', $locked)) {
                throw new AuthorizationException('You are not allowed to change the status of this job.');
            }

            $from = $locked->status;
            if ($locked->isTerminal()) {
                throw new DomainException("{$locked->job_no} is {$locked->statusLabel()} and can no longer be changed.");
            }
            if (! $locked->canTransitionTo($to)) {
                throw new DomainException("A job that is \"{$locked->statusLabel()}\" cannot go to \"".ServiceJob::STATUSES[$to].'".');
            }

            $note = isset($extra['note']) ? trim((string) $extra['note']) : '';
            $changes = ['status' => $to, 'updated_by' => $by->id];

            if ($from === ServiceJob::COMPLETED && $to === ServiceJob::IN_PROGRESS) {
                if ($note === '') {
                    throw new DomainException('Say why the completed job is being reopened.');
                }
                $changes['completed_at'] = null;
            }

            if ($to === ServiceJob::APPROVED) {
                $method = (string) ($extra['approval_method'] ?? '');
                if (! array_key_exists($method, ServiceJob::APPROVAL_METHODS)) {
                    throw new DomainException('Say how the customer approved: in person, by phone or on WhatsApp.');
                }
                $approved = $locked->estimate_amount !== null ? (string) $locked->estimate_amount : (string) $locked->grand_total;
                $changes += ['approval_method' => $method, 'approved_at' => now(), 'approved_by' => $by->id, 'approved_amount' => $approved];
                $note = trim(($note !== '' ? $note.' · ' : '').'Approved '.mb_strtolower(ServiceJob::APPROVAL_METHODS[$method]).' for '.Decimal::trim($approved));
            }

            if ($to === ServiceJob::COMPLETED) {
                $changes['completed_at'] = now();
            }

            $locked->forceFill($changes)->save();
            $this->jobs->record($locked, $from, $to, $by, $note !== '' ? $note : null);
            $this->audit->log('service_job_status', 'service', "Service job {$locked->job_no}: ".ServiceJob::STATUSES[$from].' → '.ServiceJob::STATUSES[$to], $locked,
                old: ['status' => $from], new: ['status' => $to, 'note' => $note !== '' ? $note : null], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
