<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\ServiceJob;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Service\ServiceJobAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Edits what is written on a job card (never money, status or stock). Two groups of fields:
 *  - the counter's: contact, device, problem, accessories, expected date, priority, notes for the customer (`service_jobs.edit`)
 *  - the workshop's: diagnosis and internal notes (also needs `service_jobs.manage_parts`, i.e. the people who work on the device)
 * A delivered or cancelled job is locked.
 */
class UpdateServiceJob
{
    public const COUNTER_FIELDS = ['contact_name', 'contact_phone', 'service_type_id', 'device_type', 'brand_id', 'model', 'serial_number', 'cartridge_model', 'reported_problem', 'accessories_received', 'expected_delivery_at', 'priority', 'customer_notes'];

    public const WORKSHOP_FIELDS = ['diagnosis', 'internal_notes'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $d  only the keys present are changed
     */
    public function execute(ServiceJob $job, array $d, User $by): ServiceJob
    {
        return DB::transaction(function () use ($job, $d, $by) {
            $locked = ServiceJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! ServiceJobAccess::can($by, 'service_jobs.edit', $locked)) {
                throw new AuthorizationException('You are not allowed to edit this job.');
            }
            if ($locked->isTerminal()) {
                throw new DomainException("{$locked->job_no} is {$locked->statusLabel()} and can no longer be changed.");
            }

            $workshop = array_intersect_key($d, array_flip(self::WORKSHOP_FIELDS));
            if ($workshop !== [] && ! $by->can('service_jobs.manage_parts')) {
                throw new AuthorizationException('Only the workshop can write the diagnosis and the internal notes.');
            }

            $old = [];
            $new = [];
            foreach (array_merge(self::COUNTER_FIELDS, self::WORKSHOP_FIELDS) as $field) {
                if (! array_key_exists($field, $d)) {
                    continue;
                }
                $value = $d[$field];
                $value = is_string($value) ? trim($value) : $value;
                $value = $value === '' ? null : $value;

                switch ($field) {
                    case 'reported_problem':
                        if ($value === null) {
                            throw new DomainException('The reported problem cannot be empty.');
                        }
                        break;
                    case 'service_type_id':
                        $type = ServiceType::query()->find((int) $value);
                        if (! $type || (! $type->is_active && (int) $value !== $locked->service_type_id)) {
                            throw new DomainException('Choose the type of service.');
                        }
                        $value = $type->id;
                        break;
                    case 'device_type':
                        if (! array_key_exists((string) $value, ServiceJob::DEVICE_TYPES)) {
                            throw new DomainException('Choose what kind of device it is.');
                        }
                        break;
                    case 'priority':
                        $value = $value === 'urgent' ? 'urgent' : 'normal';
                        break;
                    case 'brand_id':
                        $value = $value === null ? null : (int) $value;
                        break;
                    case 'contact_phone':
                    case 'contact_name':
                        if ($locked->customer->is_walk_in && $value === null) {
                            throw new DomainException('A walk-in customer needs a name and a phone number.');
                        }
                        break;
                }
                if (is_string($value)) {
                    $value = mb_substr($value, 0, in_array($field, ['reported_problem', 'diagnosis', 'internal_notes', 'customer_notes'], true) ? 2000 : 255);
                }

                $current = $locked->getAttribute($field);
                $currentText = $current instanceof \DateTimeInterface ? $current->format('Y-m-d H:i') : (string) ($current ?? '');
                $newText = $value === null ? '' : (string) $value;
                if ($field === 'expected_delivery_at' && $value !== null) {
                    $newText = Carbon::parse($value)->format('Y-m-d H:i');
                }
                if ($currentText !== $newText) {
                    $old[$field] = $currentText === '' ? null : $currentText;
                    $new[$field] = $newText === '' ? null : $newText;
                    $locked->setAttribute($field, $value);
                }
            }

            if ($new === []) {
                return $locked;
            }

            $locked->forceFill(['updated_by' => $by->id])->save();
            $this->audit->log('service_job_updated', 'service', "Service job {$locked->job_no} edited", $locked, old: $old, new: $new, user: $by);

            return $locked->refresh();
        }, 3);
    }
}
