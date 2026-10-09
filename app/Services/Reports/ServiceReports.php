<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ServiceJob;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

use function route;

/** Service, repair and refill jobs: the register of the period, and how each technician did. */
class ServiceReports
{
    private const LIMIT = 5000;

    private function col(string $key, string $label, string $type = 'text', ?string $gate = null): array
    {
        return ReportResult::col($key, $label, $type, $gate);
    }

    public function jobs(ReportFilters $f): ReportResult
    {
        $sql = 'SELECT j.id, j.job_no, j.received_at, j.status, j.device_type, j.model, j.grand_total, j.parts_cost, j.paid_amount, j.due_amount, j.delivered_at,
                       c.name AS customer, c.is_walk_in, j.contact_name, t.name AS type, u.name AS technician, b.name AS brand
                  FROM service_jobs j JOIN customers c ON c.id = j.customer_id JOIN service_types t ON t.id = j.service_type_id
                  LEFT JOIN users u ON u.id = j.technician_id LEFT JOIN brands b ON b.id = j.brand_id
                 WHERE j.received_at >= ? AND j.received_at < ?';
        $args = [$f->start(), $f->endExclusive()];
        $status = $f->str('status');
        if ($status && isset(ServiceJob::STATUSES[$status])) {
            $sql .= ' AND j.status = ?';
            $args[] = $status;
        }
        if ($f->id('technician_id')) {
            $sql .= ' AND j.technician_id = ?';
            $args[] = $f->id('technician_id');
        }
        $found = DB::select($sql.' ORDER BY j.received_at, j.id LIMIT '.(self::LIMIT + 1), $args);

        $rows = [];
        $tot = ['job_no' => 'Total (not cancelled)', 'total' => '0.00', 'parts_cost' => '0.00', 'paid' => '0.00', 'due' => '0.00'];
        foreach (array_slice($found, 0, self::LIMIT) as $j) {
            $cancelled = $j->status === 'cancelled';
            $rows[] = [
                'job_no' => $j->job_no, 'received' => $j->received_at, 'customer' => $j->is_walk_in ? ($j->contact_name ?: 'Walk-in customer') : $j->customer,
                'device' => trim(($j->brand ? $j->brand.' ' : '').($j->model ?? '')) ?: ucfirst($j->device_type), 'type' => $j->type, 'technician' => $j->technician,
                'status' => ServiceJob::STATUSES[$j->status] ?? $j->status, 'delivered' => $j->delivered_at, 'total' => $cancelled ? '0.00' : Decimal::round((string) $j->grand_total, 2),
                'parts_cost' => Decimal::round((string) $j->parts_cost, 2), 'paid' => Decimal::round((string) $j->paid_amount, 2), 'due' => $cancelled ? '0.00' : Decimal::round((string) $j->due_amount, 2),
                '_href' => route('service.jobs.show', $j->id),
            ];
            if (! $cancelled) {
                $tot['total'] = Decimal::add($tot['total'], (string) $j->grand_total, 2);
                $tot['parts_cost'] = Decimal::add($tot['parts_cost'], (string) $j->parts_cost, 2);
                $tot['paid'] = Decimal::add($tot['paid'], (string) $j->paid_amount, 2);
                $tot['due'] = Decimal::add($tot['due'], (string) $j->due_amount, 2);
            }
        }

        $result = (new ReportResult('Service jobs and technicians', $f->label()))->table('Jobs received in this period', [
            $this->col('job_no', 'Job'), $this->col('received', 'Received', 'datetime'), $this->col('customer', 'Customer'), $this->col('device', 'Device'), $this->col('type', 'Service'),
            $this->col('technician', 'Technician'), $this->col('status', 'Status'), $this->col('delivered', 'Delivered', 'datetime'), $this->col('total', 'Billed', 'money'),
            $this->col('parts_cost', 'Parts cost', 'money', 'products.view_cost'), $this->col('paid', 'Paid', 'money'), $this->col('due', 'Still owed', 'money'),
        ], $rows, $tot);
        if (count($found) > self::LIMIT) {
            $result->note('Only the first '.number_format(self::LIMIT).' jobs are listed. Choose a shorter period to see the rest.');
        }

        // technicians: what they took in, finished and handed over in the period
        $techs = [];
        $blank = fn (string $name) => ['technician' => $name, 'received' => 0, 'completed' => 0, 'delivered' => 0, 'revenue' => '0.00', 'days' => null, 'open' => 0];
        foreach (DB::select('SELECT u.id, u.name, COUNT(*) AS n FROM service_jobs j JOIN users u ON u.id = j.technician_id WHERE j.received_at >= ? AND j.received_at < ? GROUP BY u.id, u.name', [$f->start(), $f->endExclusive()]) as $r) {
            $techs[$r->id] = $blank($r->name);
            $techs[$r->id]['received'] = (int) $r->n;
        }
        foreach (DB::select('SELECT u.id, u.name, COUNT(*) AS n FROM service_jobs j JOIN users u ON u.id = j.technician_id WHERE j.completed_at >= ? AND j.completed_at < ? GROUP BY u.id, u.name', [$f->start(), $f->endExclusive()]) as $r) {
            $techs[$r->id] ??= $blank($r->name);
            $techs[$r->id]['completed'] = (int) $r->n;
        }
        foreach (DB::select("SELECT u.id, u.name, COUNT(*) AS n, COALESCE(SUM(j.grand_total - j.tax_amount), 0) AS revenue, AVG(TIMESTAMPDIFF(HOUR, j.received_at, j.delivered_at)) / 24 AS days
                               FROM service_jobs j JOIN users u ON u.id = j.technician_id WHERE j.status = 'delivered' AND j.invoiced_at >= ? AND j.invoiced_at < ? GROUP BY u.id, u.name", [$f->start(), $f->endExclusive()]) as $r) {
            $techs[$r->id] ??= $blank($r->name);
            $techs[$r->id]['delivered'] = (int) $r->n;
            $techs[$r->id]['revenue'] = Decimal::round((string) $r->revenue, 2);
            $techs[$r->id]['days'] = $r->days === null ? null : Decimal::round((string) $r->days, 1);
        }
        foreach (DB::select("SELECT u.id, u.name, COUNT(*) AS n FROM service_jobs j JOIN users u ON u.id = j.technician_id WHERE j.status NOT IN ('delivered', 'cancelled') GROUP BY u.id, u.name") as $r) {
            $techs[$r->id] ??= $blank($r->name);
            $techs[$r->id]['open'] = (int) $r->n;
        }
        $techRows = array_values($techs);
        usort($techRows, fn ($a, $b) => Decimal::cmp($b['revenue'], $a['revenue']) ?: strcmp($a['technician'], $b['technician']));

        return $result->table('Technicians', [
            $this->col('technician', 'Technician'), $this->col('received', 'Jobs received', 'int'), $this->col('completed', 'Finished', 'int'), $this->col('delivered', 'Delivered', 'int'),
            $this->col('revenue', 'Billed on delivered jobs (without tax)', 'money'), $this->col('days', 'Average days to deliver', 'qty'), $this->col('open', 'Open right now', 'int'),
        ], $techRows, [
            'technician' => 'Total', 'received' => array_sum(array_column($techRows, 'received')), 'completed' => array_sum(array_column($techRows, 'completed')),
            'delivered' => array_sum(array_column($techRows, 'delivered')), 'revenue' => array_reduce($techRows, fn ($c, $r) => Decimal::add($c, $r['revenue'], 2), '0.00'), 'open' => array_sum(array_column($techRows, 'open')),
        ]);
    }
}
