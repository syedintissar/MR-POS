<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Service\ServiceJobAccess;
use App\Services\Service\ServiceJobService;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Adds a charge line (labour, refill, call-out) to a job (SRV-06). Charges touch no stock.
 * With `outside_part` it is a part bought outside for this job (from the market, not from the shop's stock): it is billed at the price
 * typed, and the cost typed is what the job's profit is worked out from.
 */
class AddServiceCharge
{
    public function __construct(private readonly ServiceJobService $jobs, private readonly SettingsService $settings, private readonly AuditLogger $audit) {}

    /**
     * @param  array{description: string, quantity?: string|null, unit_price: string, discount_amount?: string|null, outside_part?: bool, unit_cost?: string|null, outside_source?: string|null}  $d
     */
    public function execute(ServiceJob $job, array $d, User $by): ServiceJobItem
    {
        $description = trim((string) ($d['description'] ?? ''));
        if ($description === '') {
            throw new DomainException('Describe the charge (for example "Labour" or "Toner refill").');
        }
        $outside = (bool) ($d['outside_part'] ?? false);
        $cost = null;
        if ($outside) {
            if (! isset($d['unit_cost']) || $d['unit_cost'] === '') {
                throw new DomainException('Enter what the shop paid for the part (the cost of one).');
            }
            $cost = Decimal::round((string) $d['unit_cost'], 4);
            if (Decimal::isNegative($cost)) {
                throw new DomainException('A cost cannot be negative.');
            }
        }
        $source = $outside ? trim((string) ($d['outside_source'] ?? '')) : null;
        $quantity = Decimal::round((string) ($d['quantity'] ?? '1') ?: '1', 3);
        if (! Decimal::isPositive($quantity)) {
            throw new DomainException('Enter a quantity greater than zero.');
        }
        $price = Decimal::round((string) ($d['unit_price'] ?? '0'), 2);
        if (Decimal::isNegative($price)) {
            throw new DomainException('A charge cannot be negative.');
        }
        $discount = Decimal::round((string) ($d['discount_amount'] ?? '0') ?: '0', 2);
        if (Decimal::isNegative($discount) || Decimal::cmp($discount, Decimal::mul($quantity, $price, 2)) > 0) {
            throw new DomainException('The discount cannot be more than the charge.');
        }

        return DB::transaction(function () use ($job, $by, $description, $quantity, $price, $discount, $outside, $cost, $source) {
            $locked = ServiceJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! ServiceJobAccess::can($by, 'service_jobs.set_charges', $locked)) {
                throw new AuthorizationException('You are not allowed to set charges on this job.');
            }
            if ($locked->isTerminal()) {
                throw new DomainException("{$locked->job_no} is {$locked->statusLabel()} and can no longer be changed.");
            }

            $taxOn = $this->settings->bool('tax.enabled');
            $item = $this->jobs->addCharge($locked, $description, $quantity, $price, $discount, $taxOn ? (string) $this->settings->get('tax.default_rate') : null, $by, $cost, $source);

            $locked->forceFill(['updated_by' => $by->id])->save();
            $this->jobs->refresh($locked);

            // the cost of an outside part stays out of the general audit snapshot, like every other cost
            $this->audit->log($outside ? 'service_outside_part_added' : 'service_charge_added', 'service',
                "Service job {$locked->job_no}: ".($outside ? 'outside part' : 'charge')." \"{$description}\" {$price} added", $locked, new: [
                    'description' => $description, 'quantity' => $quantity, 'unit_price' => $price, 'discount' => $discount,
                ] + ($outside ? ['bought_from' => $source ?: null] : []), user: $by);

            return $item;
        }, 3);
    }
}
