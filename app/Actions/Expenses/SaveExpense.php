<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Records a running cost (EXP-02) or corrects one on the day it was entered (EXP-03: after that it can only be cancelled).
 * The old and new values of a correction are audited. A receipt or bill can be attached (jpg, png or pdf, up to 5 MB, kept in a private folder).
 */
class SaveExpense
{
    public const ATTACHMENT_MAX_KB = 5120;

    public const ATTACHMENT_TYPES = ['jpg', 'jpeg', 'png', 'pdf'];

    public function __construct(private readonly DocumentNumberService $numbers, private readonly AuditLogger $audit) {}

    /**
     * @param  array{expense_category_id: int, expense_date: string, amount: string, payment_method_id: int, payee?: string|null, description: string}  $d
     */
    public function execute(array $d, User $by, ?Expense $existing = null, ?UploadedFile $attachment = null): Expense
    {
        if ($existing ? ! $by->can('expenses.edit') : ! $by->can('expenses.create')) {
            throw new AuthorizationException($existing ? 'You are not allowed to edit expenses.' : 'You are not allowed to record expenses.');
        }

        $category = ExpenseCategory::query()->find((int) ($d['expense_category_id'] ?? 0));
        if (! $category || (! $category->is_active && (! $existing || $existing->expense_category_id !== $category->id))) {
            throw new DomainException('Choose the category of the expense.');
        }
        $method = PaymentMethod::query()->find((int) ($d['payment_method_id'] ?? 0));
        if (! $method || (! $method->is_active && (! $existing || $existing->payment_method_id !== $method->id)) || $method->kind === 'customer_credit') {
            throw new DomainException('Choose how the expense was paid.');
        }
        $amount = Decimal::round((string) ($d['amount'] ?? '0'), 2);
        if (! Decimal::isPositive($amount)) {
            throw new DomainException('Enter an amount greater than zero.');
        }
        $date = (string) ($d['expense_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $date) || strtotime($date) === false) {
            throw new DomainException('Enter the date of the expense.');
        }
        $date = date('Y-m-d', strtotime($date));
        if ($date > now()->toDateString()) {
            throw new DomainException('The date of an expense cannot be in the future.');
        }
        $description = trim((string) ($d['description'] ?? ''));
        if ($description === '') {
            throw new DomainException('Describe what the money was spent on.');
        }
        $payee = trim((string) ($d['payee'] ?? ''));

        $stored = $attachment ? $this->store($attachment) : null;

        try {
            return DB::transaction(function () use ($existing, $by, $category, $method, $amount, $date, $description, $payee, $attachment, $stored) {
                if ($existing === null) {
                    $expense = new Expense;
                    $expense->forceFill([
                        'expense_no' => $this->numbers->next('expense'), 'expense_category_id' => $category->id, 'expense_date' => $date, 'amount' => $amount,
                        'payment_method_id' => $method->id, 'payee' => $payee !== '' ? mb_substr($payee, 0, 150) : null, 'description' => $description,
                        'attachment_path' => $stored, 'attachment_name' => $attachment ? mb_substr($attachment->getClientOriginalName(), 0, 255) : null,
                        'status' => Expense::POSTED, 'created_by' => $by->id, 'updated_by' => $by->id,
                    ])->save();
                    $this->audit->log('expense_created', 'expenses', "Expense {$expense->expense_no} of {$amount} ({$category->name}) recorded", $expense, new: [
                        'category' => $category->name, 'amount' => $amount, 'date' => $date, 'method' => $method->name, 'payee' => $payee ?: null,
                    ], user: $by);

                    return $expense->refresh();
                }

                $expense = Expense::query()->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();
                if (! $expense->isEditable()) {
                    throw new DomainException("{$expense->expense_no} can only be corrected on the day it was entered".($expense->isVoided() ? ' (and it has been cancelled)' : '').'. Cancel it and enter it again.');
                }

                $old = ['category' => $expense->category->name, 'amount' => (string) $expense->amount, 'date' => $expense->expense_date->toDateString(), 'method' => $expense->method->name, 'payee' => $expense->payee, 'description' => $expense->description];
                $new = ['category' => $category->name, 'amount' => $amount, 'date' => $date, 'method' => $method->name, 'payee' => $payee ?: null, 'description' => $description];
                $oldFile = $expense->attachment_path;

                $changes = ['expense_category_id' => $category->id, 'expense_date' => $date, 'amount' => $amount, 'payment_method_id' => $method->id, 'payee' => $payee !== '' ? mb_substr($payee, 0, 150) : null, 'description' => $description, 'updated_by' => $by->id];
                if ($stored !== null) {
                    $changes += ['attachment_path' => $stored, 'attachment_name' => mb_substr($attachment->getClientOriginalName(), 0, 255)];
                    $new['attachment'] = $attachment->getClientOriginalName();
                }
                $expense->forceFill($changes)->save();

                $this->audit->log('expense_updated', 'expenses', "Expense {$expense->expense_no} corrected", $expense, old: $old, new: $new, user: $by);

                if ($stored !== null && $oldFile) {
                    Storage::disk('local')->delete($oldFile);   // the replaced file is no longer needed
                }

                return $expense->refresh();
            }, 3);
        } catch (\Throwable $e) {
            if ($stored !== null) {
                Storage::disk('local')->delete($stored);   // nothing was saved, so the uploaded file must not be left behind
            }
            throw $e;
        }
    }

    private function store(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
        if (! in_array($ext, self::ATTACHMENT_TYPES, true)) {
            throw new DomainException('An attachment must be a jpg, png or pdf file.');
        }
        if ($file->getSize() > self::ATTACHMENT_MAX_KB * 1024) {
            throw new DomainException('An attachment can be at most 5 MB.');
        }

        return $file->storeAs('expenses/'.now()->format('Y'), Str::random(40).'.'.$ext, 'local');
    }
}
