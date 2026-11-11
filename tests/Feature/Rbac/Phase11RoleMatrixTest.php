<?php

namespace Tests\Feature\Rbac;

use App\Actions\CashBook\CloseDay;
use App\Actions\CashBook\PostMoneyTransaction;
use App\Models\MoneyAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\CashBook\BankReconciliationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/**
 * Every Phase 11 route against every default role, exactly as documented in docs/RBAC.md.
 * {a} an account, {t} an entry, {d} a day closing, {m} a payment method.
 */
class Phase11RoleMatrixTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    /** [method, uri template, [role => allowed?]] */
    public static function routes(): array
    {
        $managers = ['Admin' => true, 'Manager' => true, 'Cashier' => false, 'Technician' => false];
        $adminOnly = ['Admin' => true, 'Manager' => false, 'Cashier' => false, 'Technician' => false];

        $rows = [
            // looking, moving money, closing the day and cancelling: managers and administrators
            ['GET', '/cash-book', $managers], ['GET', '/cash-book/accounts/{a}', $managers], ['GET', '/cash-book/accounts/{a}/print', $managers],
            ['GET', '/cash-book/entries', $managers], ['GET', '/cash-book/entries?status=all', $managers], ['GET', '/cash-book/entries/{t}', $managers],
            ['GET', '/cash-book/entries/create', $managers], ['POST', '/cash-book/entries', $managers], ['POST', '/cash-book/entries/{t}/void', $managers],
            ['GET', '/cash-book/closings', $managers], ['GET', '/cash-book/closings/{d}', $managers],
            ['GET', '/cash-book/closings/create', $managers], ['POST', '/cash-book/closings', $managers], ['POST', '/cash-book/closings/{d}/void', $managers],

            // accounts and where payment methods pay into: the administrator only
            ['GET', '/cash-book/setup', $adminOnly], ['POST', '/cash-book/accounts', $adminOnly], ['PUT', '/cash-book/accounts/{a}', $adminOnly],
            ['POST', '/cash-book/accounts/{a}/toggle', $adminOnly], ['PUT', '/cash-book/methods/{m}', $adminOnly],
            ['PUT', '/cash-book/people/{u}/drawer', $adminOnly],

            // bank statement matching: managers and administrators
            ['GET', '/cash-book/statements', $managers], ['POST', '/cash-book/statements', $managers], ['GET', '/cash-book/statements/{r}', $managers],
            ['PUT', '/cash-book/statements/{r}', $managers], ['POST', '/cash-book/statements/{r}/void', $managers],
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
        $user = User::factory()->withRole($role)->create();

        $drawer = MoneyAccount::query()->where('is_system', true)->sole();
        $entry = app(PostMoneyTransaction::class)->execute(['type' => 'owner_in', 'txn_date' => now()->toDateString(), 'amount' => '500', 'to_account_id' => $drawer->id], $manager);
        $closing = app(CloseDay::class)->execute($drawer, ['counted_amount' => '500'], $manager);

        $statement = app(BankReconciliationService::class)->start(MoneyAccount::query()->where('kind', 'bank')->firstOrFail(), now()->toDateString(), '0', null, $manager);

        $uri = str_replace(
            ['{a}', '{t}', '{d}', '{m}', '{u}', '{r}'],
            [(string) $drawer->id, (string) $entry->id, (string) $closing->id, (string) PaymentMethod::query()->where('code', 'cash')->value('id'), (string) $manager->id, (string) $statement->id],
            $uri,
        );

        $response = $this->actingAs($user)->withSession($this->confirmed())->call($method, $uri, $method === 'GET' ? [] : ['_token' => csrf_token()]);

        if ($allowed) {
            $this->assertNotSame(403, $response->getStatusCode(), "{$role} was wrongly blocked from {$method} {$uri}");
            $this->assertLessThan(500, $response->getStatusCode(), "{$method} {$uri} crashed for {$role}");
        } else {
            $response->assertForbidden();
        }
    }

    public function test_the_default_roles_hold_exactly_the_documented_phase_11_permissions(): void
    {
        $daily = ['cashbook.view', 'cashbook.transact', 'cashbook.close_day', 'cashbook.reconcile', 'cashbook.void'];
        $can = fn (string $role, array $perms) => collect($perms)->mapWithKeys(fn ($p) => [$p => User::factory()->withRole($role)->create()->can($p)])->all();

        $this->assertSame(array_fill_keys([...$daily, 'cashbook.manage'], true), $can('Admin', [...$daily, 'cashbook.manage']));
        $this->assertSame(array_fill_keys($daily, true) + ['cashbook.manage' => false], $can('Manager', [...$daily, 'cashbook.manage']));
        foreach (['Cashier', 'Technician'] as $role) {
            $this->assertSame(array_fill_keys([...$daily, 'cashbook.manage'], false), $can($role, [...$daily, 'cashbook.manage']));
        }
    }

    public function test_each_permission_is_enforced_on_its_own(): void
    {
        $drawer = MoneyAccount::query()->where('is_system', true)->sole();

        // looking only: no moving money, no closing, no setup
        $this->asWithOnly(['cashbook.view']);
        $this->get('/cash-book')->assertOk()->assertDontSee('Move money')->assertDontSee('Close the day');
        $this->get('/cash-book/entries/create')->assertForbidden();
        $this->post('/cash-book/entries', ['type' => 'owner_in', 'txn_date' => now()->toDateString(), 'amount' => '5', 'to_account_id' => $drawer->id])->assertForbidden();
        $this->get('/cash-book/closings/create')->assertForbidden();
        $this->get('/cash-book/setup')->assertForbidden();

        // closing only: may count, may not move money
        $this->asWithOnly(['cashbook.view', 'cashbook.close_day'], 'Counter');
        $this->get('/cash-book/closings/create')->assertOk();
        $this->post('/cash-book/closings', ['money_account_id' => $drawer->id, 'counted_amount' => '0'])->assertRedirect();
        $this->get('/cash-book/entries/create')->assertForbidden();
        $this->post('/cash-book/statements', ['money_account_id' => $drawer->id, 'statement_date' => now()->toDateString(), 'statement_balance' => '0'])->assertForbidden();
    }
}
