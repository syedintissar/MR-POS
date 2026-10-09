<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public const NAMES = ['Rent', 'Electricity', 'Salary', 'Internet', 'Transport', 'Maintenance', 'Tea / Refreshments', 'Office expense', 'Miscellaneous'];

    /** Safe to run again: only missing categories are created; renamed or switched-off ones are left alone. */
    public function run(): void
    {
        foreach (self::NAMES as $name) {
            ExpenseCategory::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
