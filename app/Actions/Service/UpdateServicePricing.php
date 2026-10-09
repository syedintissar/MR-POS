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

/** Sets the estimate given to the customer and the discount on the whole job (SRV-04). The totals are recomputed from the lines. */
class UpdateServicePricing
{
    public function __construct(private readonly ServiceJobService $jobs, private readonly AuditLogger $audit) {}

    /**
     * @param  array{estimate_amount?: string|null, discount_amount?: string|null}  $d  only the keys present are changed; an empty estimate clears it
     */
    public function execute(ServiceJob $job, array $d, User $by): ServiceJob
    {
        return DB::transaction(function () use ($job, $d, $by) {
            $locked = ServiceJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! ServiceJobAccess::can($by, 'service_jobs.set_charges', $locked)) {
                throw new AuthorizationException('You are not allowed to set the estimate or discount of this job.');
            }
            if ($locked->isTerminal()) {
                throw new DomainException("{$locked->job_no} is {$locked->statusLabel()} and can no longer be changed.");
            }

            $old = [];
            $new = [];

            if (array_key_exists('estimate_amount', $d)) {
                $raw = trim((string) $d['estimate_amount']);
                $estimate = $raw === '' ? null : Decimal::round($raw, 2);
                if ($estimate !== null && Decimal::isNegative($estimate)) {
                    throw new DomainException('The estimate cannot be negative.');
                }
                if (($locked->estimate_amount === null ? null : (string) $locked->estimate_amount) !== $estimate) {
                    $old['estimate'] = $locked->estimate_amount === null ? null : (string) $locked->estimate_amount;
                    $new['estimate'] = $estimate;
                    $locked->forceFill(['estimate_amount' => $estimate]);
                }
            }

            if (array_key_exists('discount_amount', $d)) {
                $discount = Decimal::round(trim((string) $d['discount_amount']) === '' ? '0' : (string) $d['discount_amount'], 2);
                if (Decimal::isNegative($discount)) {
                    throw new DomainException('The discount cannot be negative.');
                }
                if (Decimal::cmp($discount, (string) $locked->discount_amount) !== 0) {
                    $old['discount'] = (string) $locked->discount_amount;
                    $new['discount'] = $discount;
                    $locked->forceFill(['discount_amount' => $discount]);
                }
            }

            if ($new === []) {
                return $locked;
            }

            $locked->forceFill(['updated_by' => $by->id])->save();
            $this->jobs->refresh($locked);   // refuses a discount larger than the job

            $this->audit->log('service_pricing_updated', 'service', "Service job {$locked->job_no}: estimate / discount changed", $locked, old: $old, new: $new, user: $by);

            return $locked->refresh();
        }, 3);
    }
}
