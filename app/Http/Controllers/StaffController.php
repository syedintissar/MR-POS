<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Expenses\SaveExpense;
use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Staff salaries and advances. Every advance or salary paid is saved as an ordinary expense in the "Salary" category, so the cash book
 * and the profit figures are right without anything new; this screen adds whose salary it was and of which month, and from that what
 * is still to be paid. An expense that is cancelled (on the Expenses screen) stops counting here by itself.
 */
class StaffController extends Controller
{
    public const CATEGORY = 'Salary';

    public function __construct(private readonly SaveExpense $expenses, private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('expenses.view');
        $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'show' => ['nullable', 'in:active,all']]);
        $month = CarbonImmutable::createFromFormat('Y-m-d', ($request->input('month') ?: now()->format('Y-m')).'-01')->startOfDay();
        $all = $request->input('show') === 'all';

        $employees = Employee::query()->when(! $all, fn ($q) => $q->where('is_active', true))->orderByDesc('is_active')->orderBy('name')->get();

        // what stands for the month: payments whose expense was not cancelled
        $payments = EmployeePayment::query()->with('expense:id,expense_no,expense_date,status')
            ->whereDate('salary_month', $month->toDateString())->whereHas('expense', fn ($q) => $q->where('status', Expense::POSTED))
            ->orderBy('id')->get()->groupBy('employee_id');

        $rows = [];
        $totals = ['salary' => '0.00', 'paid' => '0.00', 'left' => '0.00'];
        foreach ($employees as $e) {
            $mine = $payments->get($e->id, collect());
            $paid = $mine->reduce(fn ($c, $p) => Decimal::add($c, (string) $p->amount, 2), '0.00');
            if (! $e->is_active && $mine->isEmpty()) {
                $left = '0.00';
            } else {
                $left = Decimal::sub((string) $e->monthly_salary, $paid, 2);
            }
            $rows[] = ['employee' => $e, 'payments' => $mine, 'paid' => $paid, 'left' => $left];
            if ($e->is_active) {
                $totals['salary'] = Decimal::add($totals['salary'], (string) $e->monthly_salary, 2);
            }
            $totals['paid'] = Decimal::add($totals['paid'], $paid, 2);
            if (Decimal::isPositive($left)) {
                $totals['left'] = Decimal::add($totals['left'], $left, 2);
            }
        }

        return view('staff.index', [
            'rows' => $rows, 'totals' => $totals, 'month' => $month, 'all' => $all,
            'methods' => PaymentMethod::query()->forSuppliers()->get(['id', 'name', 'requires_reference']),
            'canPay' => $request->user()->can('expenses.create'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('expenses.create');
        $employee = Employee::query()->create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->audit->log('employee_created', 'expenses', "Employee {$employee->name} added (monthly salary {$employee->monthly_salary})", $employee);

        return back()->with('success', "{$employee->name} added.");
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('expenses.create');
        $old = $employee->only(['name', 'monthly_salary', 'is_active']);
        $employee->update($this->validated($request));
        $this->audit->log('employee_updated', 'expenses', "Employee {$employee->name} updated", $employee, $old, $employee->only(['name', 'monthly_salary', 'is_active']));

        return back()->with('success', 'Saved.');
    }

    /** An advance or the salary: paid out now, saved as a "Salary" expense and counted against the chosen month. */
    public function pay(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('expenses.create');
        $d = $request->validate([
            'type' => ['required', 'in:advance,salary'],
            'salary_month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'payment_method_id' => ['required', 'integer'],
            'expense_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:200'],
        ], ['decimal' => 'Too many decimal places.']);

        $month = CarbonImmutable::createFromFormat('Y-m-d', $d['salary_month'].'-01')->startOfDay();
        $label = ($d['type'] === 'advance' ? 'Advance against the salary of ' : 'Salary of ').$month->format('F Y');

        try {
            $payment = DB::transaction(function () use ($d, $employee, $month, $label, $request) {
                $category = ExpenseCategory::query()->firstOrCreate(['name' => self::CATEGORY], ['is_active' => true]);
                if (! $category->is_active) {
                    $category->update(['is_active' => true]);
                }
                $expense = $this->expenses->execute([
                    'expense_category_id' => $category->id, 'expense_date' => $d['expense_date'] ?? now()->toDateString(), 'amount' => (string) $d['amount'],
                    'payment_method_id' => (int) $d['payment_method_id'], 'payee' => $employee->name,
                    'description' => $label.(trim((string) ($d['notes'] ?? '')) !== '' ? ' · '.trim((string) $d['notes']) : ''),
                ], $request->user());

                return EmployeePayment::query()->create([
                    'employee_id' => $employee->id, 'expense_id' => $expense->id, 'type' => $d['type'], 'salary_month' => $month->toDateString(), 'amount' => $expense->amount,
                ]);
            });
        } catch (DomainException|AuthorizationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$label} paid to {$employee->name}: ".$payment->amount.'. It is in the expenses as '.$payment->expense->expense_no.'.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:30'], 'job_title' => ['nullable', 'string', 'max:100'],
            'monthly_salary' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], 'joined_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:255'],
        ], ['decimal' => 'Too many decimal places.']);
        $d['name'] = trim($d['name']);
        $d['is_active'] = $request->boolean('is_active', true);

        return $d;
    }
}
