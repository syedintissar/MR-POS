<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An advance or a salary payment: says whose salary, of which month, an expense was. */
class EmployeePayment extends Model
{
    protected $fillable = ['employee_id', 'expense_id', 'type', 'salary_month', 'amount'];

    protected function casts(): array
    {
        return ['salary_month' => 'date', 'amount' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
