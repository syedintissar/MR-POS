<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The people the shop pays a salary to. (Not the same as a login: a helper may have no login at all.)
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->decimal('monthly_salary', 15, 2)->default(0);
            $table->date('joined_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
        DB::statement('ALTER TABLE employees ADD CONSTRAINT chk_employee_salary CHECK (monthly_salary >= 0)');

        // Money handed to an employee against the salary of one month: an advance during the month, or the salary itself.
        // The money is an expense (category "Salary"), so the cash book and the profit figures need nothing new: this row only says
        // whose salary, and of which month, that expense was. A cancelled expense no longer counts.
        Schema::create('employee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('expense_id')->unique()->constrained('expenses')->restrictOnDelete();
            $table->string('type', 10);                                  // advance | salary
            $table->date('salary_month');                                // the first day of the month it counts for
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['employee_id', 'salary_month']);
        });
        DB::statement("ALTER TABLE employee_payments ADD CONSTRAINT chk_employee_payment CHECK (type IN ('advance','salary') AND amount > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payments');
        Schema::dropIfExists('employees');
    }
};
