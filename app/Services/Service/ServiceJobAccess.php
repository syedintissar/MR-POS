<?php

declare(strict_types=1);

namespace App\Services\Service;

use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and work on which job (SRV-10). People with `service_jobs.view_all` (cashiers, managers, administrators) see every job;
 * people with only `service_jobs.view_assigned` (technicians) see, and may act on, only the jobs assigned to them.
 */
class ServiceJobAccess
{
    /** @return Builder<ServiceJob> */
    public static function visibleTo(User $user): Builder
    {
        $q = ServiceJob::query();

        if ($user->can('service_jobs.view_all')) {
            return $q;
        }

        return $user->can('service_jobs.view_assigned') ? $q->where('service_jobs.technician_id', $user->id) : $q->whereRaw('1 = 0');
    }

    public static function canSee(User $user, ServiceJob $job): bool
    {
        if ($user->can('service_jobs.view_all')) {
            return true;
        }

        return $user->can('service_jobs.view_assigned') && $job->technician_id === $user->id;
    }

    /** Allowed to do $permission to this job: holds the permission and may see the job. */
    public static function can(User $user, string $permission, ServiceJob $job): bool
    {
        return $user->can($permission) && self::canSee($user, $job);
    }
}
