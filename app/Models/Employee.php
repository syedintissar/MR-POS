<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone the shop pays a salary to. */
class Employee extends Model
{
    protected $fillable = ['name', 'phone', 'job_title', 'monthly_salary', 'joined_on', 'is_active', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['monthly_salary' => 'decimal:2', 'joined_on' => 'date', 'is_active' => 'boolean'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EmployeePayment::class);
    }
}
