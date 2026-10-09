<?php

namespace Tests\Feature\Rbac;

use App\Actions\Service\AddServiceCharge;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Actions\Service\ReceiveServiceJob;
use App\Models\Customer;
use App\Models\ServiceJob;
use App\Models\ServiceType;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/**
 * Every Phase 7 route against every default role, exactly as documented in docs/RBAC.md.
 * {j} is a job assigned to the person being tested when that person is a technician (a technician only reaches their own jobs),
 * otherwise to another technician. {i} is a charge line on that job.
 */
class Phase7RoleMatrixTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    /** [method, uri template, [role => allowed?]] */
    public static function routes(): array
    {
        $all = ['Admin' => true, 'Manager' => true, 'Cashier' => true, 'Technician' => true];
        $notTech = ['Admin' => true, 'Manager' => true, 'Cashier' => true, 'Technician' => false];
        $notCashier = ['Admin' => true, 'Manager' => true, 'Cashier' => false, 'Technician' => true];
        $managers = ['Admin' => true, 'Manager' => true, 'Cashier' => false, 'Technician' => false];
        $nobody = ['Admin' => false, 'Manager' => false, 'Cashier' => false, 'Technician' => false];

        $rows = [
            ['GET', '/service/jobs', $all], ['GET', '/service/jobs?view=all&q=a', $all],
            ['GET', '/service/jobs/create', $all], ['POST', '/service/jobs', $all],
            ['GET', '/service/jobs/{j}', $all], ['GET', '/service/jobs/{j}/edit', $all], ['PUT', '/service/jobs/{j}', $all],
            ['POST', '/service/jobs/{j}/assign', $notTech], ['POST', '/service/jobs/{j}/status', $all],
            ['POST', '/service/jobs/{j}/parts', $notCashier], ['POST', '/service/jobs/{j}/charges', $notCashier], ['POST', '/service/jobs/{j}/pricing', $notCashier],
            ['POST', '/service/jobs/{j}/lines/{i}/remove', $notCashier],
            ['POST', '/service/jobs/{j}/advance', $notTech], ['POST', '/service/jobs/{j}/deliver', $notTech], ['POST', '/service/jobs/{j}/cancel', $managers],
            // the original slip / invoice are signed links only: without the signature nobody gets in, not even an administrator
            ['GET', '/service/jobs/{j}/slip/thermal', $nobody], ['GET', '/service/jobs/{j}/invoice/a4', $nobody],
            ['GET', '/service/jobs/{j}/slip/thermal/reprint', $notTech], ['GET', '/service/jobs/{j}/slip/a4/reprint', $notTech],
            ['GET', '/service/jobs/{j}/invoice/thermal/reprint', $notTech], ['GET', '/service/jobs/{j}/invoice.pdf', $notTech],
            ['GET', '/service/products/search?q=a', $notCashier], ['GET', '/service/customers/search?q=a', $all],
            ['GET', '/service/types', $managers], ['GET', '/service/types/create', $managers], ['POST', '/service/types', $managers],
            ['GET', '/service/types/{t}/edit', $managers], ['PUT', '/service/types/{t}', $managers], ['POST', '/service/types/{t}/toggle', $managers],
        ];

        $cases = [];
        foreach ($rows as [$method, $uri, $expect]) {
            foreach ($expect as $role => $allowed) {
                $cases["$role $method $uri"] = [$role, $method, $uri, $allowed];
            }
        }

        return $cases;
    }

    #[DataProvider('routes')]
    public function test_route_access_matches_the_documented_matrix(string $role, string $method, string $uri, bool $allowed): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();
        $manager = User::factory()->withRole('Manager')->create();
        $user = User::factory()->withRole($role)->create();
        $other = User::factory()->withRole('Technician')->create();

        $job = app(ReceiveServiceJob::class)->execute([
            'customer_id' => Customer::factory()->create(['allow_credit' => true])->id, 'service_type_id' => ServiceType::query()->value('id'), 'device_type' => 'printer',
            'reported_problem' => 'Does not print', 'technician_id' => $role === 'Technician' ? $user->id : $other->id,
        ], $cashier);
        $job = app(ChangeServiceJobStatus::class)->execute($job, 'diagnosing', $manager);
        $line = app(AddServiceCharge::class)->execute($job, ['description' => 'Labour', 'unit_price' => '500'], $manager);

        $uri = str_replace(['{j}', '{i}', '{t}'], [(string) $job->id, (string) $line->id, (string) ServiceType::query()->value('id')], $uri);

        $response = $this->actingAs($user)->withSession($this->confirmed())->call($method, $uri, $method === 'GET' ? [] : ['_token' => csrf_token()]);

        if ($allowed) {
            $this->assertNotSame(403, $response->getStatusCode(), "{$role} was wrongly blocked from {$method} {$uri}");
            $this->assertLessThan(500, $response->getStatusCode(), "{$method} {$uri} crashed for {$role}");
        } else {
            $response->assertForbidden();
        }
    }

    public function test_a_technician_is_blocked_from_every_job_that_is_not_theirs(): void
    {
        $tech = User::factory()->withRole('Technician')->create();
        $other = User::factory()->withRole('Technician')->create();
        $cashier = User::factory()->withRole('Cashier')->create();
        $job = app(ReceiveServiceJob::class)->execute([
            'customer_id' => null, 'contact_name' => 'Mr Ali', 'contact_phone' => '0300-1', 'service_type_id' => ServiceType::query()->value('id'), 'device_type' => 'printer',
            'reported_problem' => 'x', 'technician_id' => $other->id,
        ], $cashier);
        $line = app(AddServiceCharge::class)->execute($job, ['description' => 'Labour', 'unit_price' => '500'], User::factory()->withRole('Manager')->create());

        $this->actingAs($tech);
        foreach ([
            ['GET', "/service/jobs/{$job->id}"], ['GET', "/service/jobs/{$job->id}/edit"], ['PUT', "/service/jobs/{$job->id}"], ['POST', "/service/jobs/{$job->id}/status"],
            ['POST', "/service/jobs/{$job->id}/parts"], ['POST', "/service/jobs/{$job->id}/charges"], ['POST', "/service/jobs/{$job->id}/pricing"],
            ['POST', "/service/jobs/{$job->id}/lines/{$line->id}/remove"],
        ] as [$method, $uri]) {
            $this->call($method, $uri, ['_token' => csrf_token()])->assertForbidden();
        }
        $this->assertSame(ServiceJob::RECEIVED, $job->fresh()->status);
    }

    public function test_guests_are_sent_to_the_login_page_for_every_phase_7_screen(): void
    {
        foreach (['/service/jobs', '/service/jobs/create', '/service/jobs/1', '/service/jobs/1/edit', '/service/types', '/service/products/search?q=a', '/service/customers/search?q=a'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
        $this->post('/service/jobs', [])->assertRedirect('/login');
        $this->post('/service/jobs/1/deliver', [])->assertRedirect('/login');
    }
}
