<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The shop's running costs (rent, electricity, salaries ...) and the categories they are filed under. */
class ExpenseController extends Controller
{
    public function __construct(
        private readonly SaveExpense $save,
        private readonly VoidExpense $void,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('expenses.view');
        $d = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer'], 'method' => ['nullable', 'integer'], 'status' => ['nullable', 'in:posted,voided,all'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $status = $d['status'] ?? 'posted';

        $query = Expense::query()->with(['category:id,name', 'method:id,name', 'creator:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(! empty($d['category']), fn ($q) => $q->where('expense_category_id', (int) $d['category']))
            ->when(! empty($d['method']), fn ($q) => $q->where('payment_method_id', (int) $d['method']))
            ->when(! empty($d['from']), fn ($q) => $q->whereDate('expense_date', '>=', $d['from']))
            ->when(! empty($d['to']), fn ($q) => $q->whereDate('expense_date', '<=', $d['to']))
            ->when(! empty($d['q']), function ($q) use ($d) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($d['q'])).'%';
                $q->where(fn ($w) => $w->where('expense_no', 'like', $like)->orWhere('payee', 'like', $like)->orWhere('description', 'like', $like));
            });

        $total = (clone $query)->reorder()->where('status', Expense::POSTED)->sum('amount');

        return view('expenses.index', [
            'expenses' => $query->orderByDesc('expense_date')->orderByDesc('id')->paginate(25)->withQueryString(), 'total' => (string) $total, 'status' => $status,
            'categories' => ExpenseCategory::query()->orderBy('name')->get(['id', 'name']), 'methods' => PaymentMethod::query()->forTill()->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        $this->authorize('expenses.create');

        return view('expenses.form', ['expense' => (new Expense)->forceFill(['expense_date' => now()->toDateString()]), ...$this->lists()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('expenses.create');
        $d = $request->validate($this->rules());

        try {
            $expense = $this->save->execute($d, $request->user(), null, $request->file('attachment'));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('expenses.show', $expense)->with('success', "Expense {$expense->expense_no} recorded.");
    }

    public function show(Expense $expense): View
    {
        $this->authorize('expenses.view');
        $expense->load(['category', 'method', 'creator', 'voider']);

        return view('expenses.show', ['expense' => $expense]);
    }

    public function edit(Expense $expense): View
    {
        $this->authorize('expenses.edit');
        abort_unless($expense->isEditable(), 403, 'An expense can only be corrected on the day it was entered.');

        return view('expenses.form', ['expense' => $expense, ...$this->lists($expense)]);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('expenses.edit');
        $d = $request->validate($this->rules());

        try {
            $this->save->execute($d, $request->user(), $expense, $request->file('attachment'));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('expenses.show', $expense)->with('success', 'Expense corrected.');
    }

    public function voidExpense(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('expenses.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->void->execute($expense, $d['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('expenses.show', $expense)->with('success', "Expense {$expense->expense_no} cancelled.");
    }

    /** The bill or receipt that was attached, served from the private folder to people who may see expenses. */
    public function attachment(Expense $expense): StreamedResponse
    {
        $this->authorize('expenses.view');
        abort_unless($expense->attachment_path && Storage::disk('local')->exists($expense->attachment_path), 404);

        $ext = strtolower(pathinfo($expense->attachment_path, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'][$ext] ?? 'application/octet-stream';
        $this->audit->log('expense_attachment_viewed', 'expenses', "Attachment of {$expense->expense_no} opened", $expense, user: request()->user());

        return Storage::disk('local')->response($expense->attachment_path, $expense->attachment_name ?: basename($expense->attachment_path), [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $expense->attachment_name ?: 'attachment.'.$ext).'"',
        ]);
    }

    // ------------------------------------------------------------------------------------------------ categories

    public function categories(): View
    {
        $this->authorize('expenses.view');

        return view('expenses.categories', ['categories' => ExpenseCategory::query()->withCount('expenses')->orderBy('name')->get()]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('expenses.edit');
        $d = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')]]);

        $cat = ExpenseCategory::query()->create(['name' => trim($d['name']), 'is_active' => true]);
        $this->audit->log('expense_category_created', 'expenses', "Expense category \"{$cat->name}\" created", $cat, user: $request->user());

        return back()->with('success', "Category \"{$cat->name}\" added.");
    }

    public function updateCategory(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorize('expenses.edit');
        $d = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->ignore($category->id)]]);

        $old = $category->name;
        $category->update(['name' => trim($d['name'])]);
        $this->audit->log('expense_category_updated', 'expenses', "Expense category \"{$old}\" renamed to \"{$category->name}\"", $category, old: ['name' => $old], new: ['name' => $category->name], user: $request->user());

        return back()->with('success', 'Category renamed.');
    }

    public function toggleCategory(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorize('expenses.edit');
        $category->update(['is_active' => ! $category->is_active]);
        $this->audit->log($category->is_active ? 'expense_category_activated' : 'expense_category_deactivated', 'expenses', "Expense category \"{$category->name}\" ".($category->is_active ? 'switched on' : 'switched off'), $category, user: $request->user());

        return back()->with('success', $category->is_active ? 'Category is on again.' : 'Category switched off: it is not offered for new expenses.');
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'expense_category_id' => ['required', 'integer'], 'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'], 'payment_method_id' => ['required', 'integer'],
            'payee' => ['nullable', 'string', 'max:150'], 'description' => ['required', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.SaveExpense::ATTACHMENT_MAX_KB],
        ];
    }

    /** @return array<string, mixed> */
    private function lists(?Expense $current = null): array
    {
        return [
            'categories' => ExpenseCategory::query()->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($w) => $w->orWhere('id', $current->expense_category_id)))->orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::query()->where('kind', '<>', 'customer_credit')->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($w) => $w->orWhere('id', $current->payment_method_id)))->orderBy('sort_order')->get(['id', 'name']),
        ];
    }
}
