<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The cheque register: every cheque received or given, with the date on it and what became of it. It follows the cheque only:
        // the money itself is recorded by the customer or supplier payment (method "Cheque"), which the register points at.
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10);                             // received (from a customer) | given (to a supplier)
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('party_name', 150);                           // who it is from / to, as written at the time
            $table->string('cheque_no', 100)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('cheque_date');                                 // the date on the cheque (a post-dated cheque: in the future)
            $table->string('status', 20)->default('pending');            // pending | cleared | bounced | cancelled
            $table->date('status_date')->nullable();                     // when it cleared, bounced or was cancelled
            $table->string('notes', 255)->nullable();
            $table->foreignId('customer_payment_id')->nullable()->constrained('customer_payments')->restrictOnDelete();
            $table->foreignId('supplier_payment_id')->nullable()->constrained('supplier_payments')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'cheque_date']);
            $table->index(['direction', 'status']);
        });
        DB::statement("ALTER TABLE cheques ADD CONSTRAINT chk_cheque CHECK (direction IN ('received','given') AND status IN ('pending','cleared','bounced','cancelled') AND amount > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('cheques');
    }
};
