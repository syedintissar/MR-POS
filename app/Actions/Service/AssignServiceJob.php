<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Service\ServiceJobAccess;
use App\Services\Service\ServiceJobService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Gives a job to a technician (or takes the assignment away). A technician only sees the jobs given to them, so this decides who can work on it. */
class AssignServiceJob
{
    public function __construct(private readonly ServiceJobService $jobs, private readonly AuditLogger $audit) {}

    public function execute(ServiceJob $job, ?int $technicianId, User $by): ServiceJob
    {
        return DB::transaction(function () use ($job, $technicianId, $by) {
            $locked = ServiceJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! ServiceJobAccess::can($by, 'service_jobs.assign', $locked)) {
                throw new AuthorizationException('You are not allowed to assign this job.');
            }
            if ($locked->isTerminal()) {
                throw new DomainException("{$locked->job_no} is {$locked->statusLabel()} and can no longer be changed.");
            }

            $tech = null;
            if ($technicianId !== null) {
                $tech = User::query()->find($technicianId);
                if (! $tech || ! $tech->is_active || ! $tech->can('service_jobs.view_assigned')) {
                    throw new DomainException('That person cannot be given service jobs.');
                }
            }

            if ($locked->technician_id === ($tech?->id)) {
                return $locked;
            }

            $before = $locked->technician?->name;
            $locked->forceFill(['technician_id' => $tech?->id, 'updated_by' => $by->id])->save();
            $note = $tech ? "Assigned to {$tech->name}".($before ? " (was {$before})" : '') : 'Technician removed'.($before ? " (was {$before})" : '');
            $this->jobs->record($locked, $locked->status, $locked->status, $by, $note);
            $this->audit->log('service_job_assigned', 'service', "Service job {$locked->job_no}: {$note}", $locked, old: ['technician' => $before], new: ['technician' => $tech?->name], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
