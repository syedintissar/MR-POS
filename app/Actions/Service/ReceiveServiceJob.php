<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Customer;
use App\Models\ServiceJob;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\Service\ServiceJobService;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Takes a device in at the counter (SRV-02): writes the job card, its first status-history line and, when the service type has a
 * suggested charge, that charge as the first line. The walk-in customer is allowed, but then a name and a phone number are needed
 * so the shop knows whom to call.
 */
class ReceiveServiceJob
{
    public function __construct(
        private readonly ServiceJobService $jobs,
        private readonly DocumentNumberService $numbers,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $d
     */
    public function execute(array $d, User $by): ServiceJob
    {
        if (! $by->can('service_jobs.create')) {
            throw new AuthorizationException('You are not allowed to receive service jobs.');
        }

        $problem = trim((string) ($d['reported_problem'] ?? ''));
        if ($problem === '') {
            throw new DomainException('Describe the problem the customer reported.');
        }

        return DB::transaction(function () use ($d, $by, $problem) {
            $customer = isset($d['customer_id']) && $d['customer_id'] ? Customer::query()->find((int) $d['customer_id']) : Customer::walkIn();
            if (! $customer) {
                throw new DomainException('That customer no longer exists.');
            }
            if (! $customer->is_walk_in && ! $customer->is_active) {
                throw new DomainException("Customer \"{$customer->name}\" is inactive. Re-activate the customer first.");
            }

            $name = trim((string) ($d['contact_name'] ?? '')) ?: ($customer->is_walk_in ? '' : $customer->name);
            $phone = trim((string) ($d['contact_phone'] ?? '')) ?: ($customer->is_walk_in ? '' : (string) $customer->phone);
            if ($customer->is_walk_in && ($name === '' || $phone === '')) {
                throw new DomainException('For a walk-in customer enter a name and a phone number, so the shop can call when the job is ready.');
            }

            $type = ServiceType::query()->find((int) ($d['service_type_id'] ?? 0));
            if (! $type || ! $type->is_active) {
                throw new DomainException('Choose the type of service.');
            }

            $device = (string) ($d['device_type'] ?? 'printer');
            if (! array_key_exists($device, ServiceJob::DEVICE_TYPES)) {
                throw new DomainException('Choose what kind of device it is.');
            }

            $technicianId = null;
            if (! empty($d['technician_id'])) {
                if (! $by->can('service_jobs.assign')) {
                    throw new AuthorizationException('You are not allowed to assign a technician.');
                }
                $technicianId = $this->technician((int) $d['technician_id'])->id;
            } elseif (! $by->can('service_jobs.view_all')) {
                $technicianId = $by->id;   // a technician who takes a job in works on it: otherwise they could not even open it afterwards
            }

            $taxOn = $this->settings->bool('tax.enabled');
            $estimate = null;
            if (isset($d['estimate_amount']) && trim((string) $d['estimate_amount']) !== '') {
                if (! $by->can('service_jobs.set_charges')) {
                    throw new AuthorizationException('You are not allowed to set an estimate.');
                }
                $estimate = $this->money((string) $d['estimate_amount'], 'estimate');
            }

            $priority = ($d['priority'] ?? 'normal') === 'urgent' ? 'urgent' : 'normal';
            $now = now();

            $job = new ServiceJob;
            $job->forceFill([
                'job_no' => $this->numbers->next('service_job'),
                'customer_id' => $customer->id,
                'contact_name' => $name !== '' ? mb_substr($name, 0, 150) : null,
                'contact_phone' => $phone !== '' ? mb_substr($phone, 0, 30) : null,
                'service_type_id' => $type->id,
                'device_type' => $device,
                'brand_id' => ! empty($d['brand_id']) ? (int) $d['brand_id'] : null,
                'model' => $this->text($d['model'] ?? null, 100),
                'serial_number' => $this->text($d['serial_number'] ?? null, 100),
                'cartridge_model' => $this->text($d['cartridge_model'] ?? null, 100),
                'reported_problem' => $problem,
                'accessories_received' => $this->text($d['accessories_received'] ?? null, 255),
                'received_at' => $now,
                'expected_delivery_at' => ! empty($d['expected_delivery_at']) ? $d['expected_delivery_at'] : null,
                'technician_id' => $technicianId,
                'priority' => $priority,
                'status' => ServiceJob::RECEIVED,
                'estimate_amount' => $estimate,
                'tax_inclusive' => $taxOn && $this->settings->bool('tax.inclusive'),
                'customer_notes' => $this->text($d['customer_notes'] ?? null, 2000),
                'internal_notes' => $by->can('service_jobs.manage_parts') ? $this->text($d['internal_notes'] ?? null, 2000) : null,
                'created_by' => $by->id,
                'updated_by' => $by->id,
            ])->save();

            $this->jobs->record($job, null, ServiceJob::RECEIVED, $by, 'Received');

            // the type's suggested charge goes on the job as its first line, unless the cashier unticked it
            $addCharge = (bool) ($d['add_default_charge'] ?? true);
            if ($addCharge && $type->default_charge !== null && Decimal::isPositive((string) $type->default_charge)) {
                $this->jobs->addCharge($job, $type->name, '1', (string) $type->default_charge, '0', $taxOn ? (string) $this->settings->get('tax.default_rate') : null, $by);
            }

            $this->jobs->refresh($job);

            $this->audit->log('service_job_received', 'service', "Service job {$job->job_no} received from ".($name !== '' ? $name : $customer->name), $job, new: [
                'customer' => $customer->name, 'type' => $type->name, 'device' => $device, 'model' => $job->model, 'serial' => $job->serial_number,
                'technician_id' => $technicianId, 'priority' => $priority,
            ], user: $by);

            return $job->refresh();
        }, 3);
    }

    private function technician(int $id): User
    {
        try {
            $u = User::query()->findOrFail($id);
        } catch (ModelNotFoundException) {
            throw new DomainException('That technician no longer exists.');
        }
        if (! $u->is_active || ! $u->can('service_jobs.view_assigned')) {
            throw new DomainException("{$u->name} cannot be given service jobs.");
        }

        return $u;
    }

    private function money(string $v, string $what): string
    {
        $v = Decimal::round($v, 2);
        if (Decimal::isNegative($v)) {
            throw new DomainException("The {$what} cannot be negative.");
        }

        return $v;
    }

    private function text(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
