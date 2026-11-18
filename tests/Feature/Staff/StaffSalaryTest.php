<?php

namespace Tests\Feature\Staff;

use App\Actions\Expenses\VoidExpense;
use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\User;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** Staff salaries: employees, advances and salary payments (each one an expense), and what is still to pay for a month. */
class StaffSalaryTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    public function test_advances_and_salary_are_expenses_and_the_month_shows_what_is_left(): void
    {
        $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
        $this->as('Manager');
        $month = now()->format('Y-m');

        $this->get('/staff')->assertOk()->assertSee('No employees yet')->assertSee('Add an employee');
        $this->post('/staff', ['name' => 'Bilal Technician', 'job_title' => 'Technician', 'monthly_salary' => '30000'])->assertSessionHasNoErrors();
        $this->post('/staff', ['name' => '', 'monthly_salary' => '-5'])->assertSessionHasErrors(['name', 'monthly_salary']);
        $bilal = Employee::query()->sole();

        // an advance of 8,000 in the month, then 10,000 of the salary
        $this->post("/staff/{$bilal->id}/pay", ['type' => 'advance', 'salary_month' => $month, 'amount' => '8000', 'payment_method_id' => $cash->id, 'notes' => 'for Eid'])->assertSessionHas('success');
        $this->post("/staff/{$bilal->id}/pay", ['type' => 'salary', 'salary_month' => $month, 'amount' => '10000', 'payment_method_id' => $cash->id])->assertSessionHas('success');
        $this->post("/staff/{$bilal->id}/pay", ['type' => 'salary', 'salary_month' => $month, 'amount' => '0', 'payment_method_id' => $cash->id])->assertSessionHasErrors('amount');

        $advance = EmployeePayment::query()->where('type', 'advance')->sole();
        $expense = $advance->expense;
        $this->assertSame('Salary', $expense->category->name);
        $this->assertSame('Bilal Technician', $expense->payee);
        $this->assertSame('8000.00', (string) $expense->amount);
        $this->assertStringContainsString('Advance against the salary of '.now()->format('F Y'), $expense->description);
        $this->assertStringContainsString('for Eid', $expense->description);
        $this->assertSame(2, Expense::query()->count(), 'each payout is one expense, so the cash book and the profit report already have it');

        $this->get('/staff')->assertOk()->assertSee('Bilal Technician')->assertSee('30,000.00')->assertSee('18,000.00')->assertSee('12,000.00')->assertSee($expense->expense_no);
        // another month owes the whole salary
        $this->get('/staff?month='.now()->addMonth()->format('Y-m'))->assertOk()->assertSee('Bilal Technician')->assertDontSee($expense->expense_no);

        // an advance paid by mistake is cancelled as an expense, and stops counting
        app(VoidExpense::class)->execute($expense, 'Entered twice', User::factory()->withRole('Admin')->create());
        $this->get('/staff')->assertOk()->assertSee('20,000.00')->assertDontSee($expense->expense_no);

        // the salary changes; someone who left is hidden unless asked for
        $this->put("/staff/{$bilal->id}", ['name' => 'Bilal Technician', 'monthly_salary' => '35000', 'is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('35000.00', (string) $bilal->fresh()->monthly_salary);
        $this->get('/staff?month='.now()->addMonth()->format('Y-m'))->assertOk()->assertDontSee('Bilal Technician');
        $this->get('/staff?show=all')->assertOk()->assertSee('Bilal Technician')->assertSee('No longer working here');
    }

    public function test_only_people_who_handle_expenses_see_or_pay_salaries(): void
    {
        $e = Employee::query()->create(['name' => 'Helper', 'monthly_salary' => '20000']);
        $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();

        $this->as('Cashier');
        $this->get('/staff')->assertForbidden();
        $this->post("/staff/{$e->id}/pay", ['type' => 'advance', 'salary_month' => now()->format('Y-m'), 'amount' => '100', 'payment_method_id' => $cash->id])->assertForbidden();

        $this->asWithOnly(['expenses.view']);
        $this->get('/staff')->assertOk()->assertSee('Helper')->assertDontSee('Add an employee');
        $this->post('/staff', ['name' => 'X', 'monthly_salary' => '1'])->assertForbidden();
        $this->assertSame(0, Expense::query()->count());
    }
}
