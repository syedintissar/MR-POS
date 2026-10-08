<?php

namespace Tests\Feature\Rbac;

use App\Actions\Customers\PostCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/**
 * Every Phase 6 route against every default role, exactly as documented in docs/RBAC.md.
 * {c} is a customer with an invoice (so it has history) and {p} a payment collected from them.
 */
class Phase6RoleMatrixTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    /** [method, uri template, [role => allowed?]] */
    public static function routes(): array
    {
        $everyone = ['Admin' => true, 'Manager' => true, 'Cashier' => true, 'Technician' => true];
        $sales = ['Admin' => true, 'Manager' => true, 'Cashier' => true, 'Technician' => false];
        $managers = ['Admin' => true, 'Manager' => true, 'Cashier' => false, 'Technician' => false];
        $adminOnly = ['Admin' => true, 'Manager' => false, 'Cashier' => false, 'Technician' => false];
        $nobody = ['Admin' => false, 'Manager' => false, 'Cashier' => false, 'Technician' => false];

        $rows = [
            ['GET', '/customers', $everyone], ['GET', '/customers/{c}', $everyone],
            ['GET', '/customers/create', $sales], ['POST', '/customers', $sales],
            ['GET', '/customers/{c}/edit', $sales], ['PUT', '/customers/{c}', $sales], ['POST', '/customers/{c}/toggle', $sales],
            ['DELETE', '/customers/{c}', $managers],
            ['GET', '/customers/{c}/statement', $sales], ['GET', '/customers/{c}/statement.pdf', $sales],
            ['POST', '/customers/{c}/adjust-balance', $adminOnly],
            ['POST', '/customers/{c}/apply-advance', $sales],
            ['GET', '/customer-payments', $sales], ['GET', '/customer-payments/create', $sales], ['POST', '/customer-payments', $sales],
            ['GET', '/customer-payments/{p}', $sales],
            ['POST', '/customer-payments/{p}/void', $managers],
            ['GET', '/customer-payments/{p}/reprint', $sales],
            // the original receipt is a signed link only: without the signature nobody gets in, not even an administrator
            ['GET', '/customer-payments/{p}/receipt', $nobody],
            ['GET', '/receivables', $managers],
            ['GET', '/pos/customers/search?q=a', $sales], ['POST', '/pos/customers', $sales],
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
        $manager = User::factory()->withRole('Manager')->create();
        $customer = Customer::factory()->create(['allow_credit' => true]);
        $cash = PaymentMethod::query()->where('code', 'cash')->value('id');
        $product = $this->stocked('5', '100', ['sale_price' => '150']);
        app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'lines' => [['product_id' => $product->id, 'quantity' => '1']],
        ], $manager);
        $payment = app(PostCustomerPayment::class)->execute(['customer_id' => $customer->id, 'payment_method_id' => $cash, 'amount' => '50', 'allocation' => 'auto'], $manager);
        $uri = str_replace(['{c}', '{p}'], [(string) $customer->id, (string) $payment->id], $uri);

        $response = $this->as($role)->withSession($this->confirmed())->call($method, $uri, $method === 'GET' ? [] : ['_token' => csrf_token()]);

        if ($allowed) {
            $this->assertNotSame(403, $response->getStatusCode(), "{$role} was wrongly blocked from {$method} {$uri}");
            $this->assertLessThan(500, $response->getStatusCode(), "{$method} {$uri} crashed for {$role}");
        } else {
            $response->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_the_login_page_for_every_phase_6_screen(): void
    {
        foreach (['/customers', '/customers/create', '/customers/1', '/customers/1/statement', '/customer-payments', '/customer-payments/create', '/receivables', '/pos/customers/search?q=a'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
        $this->post('/customers', [])->assertRedirect('/login');
        $this->post('/customer-payments', [])->assertRedirect('/login');
        $this->post('/pos/customers', [])->assertRedirect('/login');
    }
}
