<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a customer owes the shop, as a list of entries. customers.balance is only a cache of this list:
        //   balance = SUM(debit) - SUM(credit);  positive = the customer owes the shop, negative = the shop holds the customer's money (credit).
        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('entry_date');
            $table->string('entry_type', 30);
            $table->decimal('debit', 15, 2)->default(0);             // the customer owes more (a sale, a cancelled payment)
            $table->decimal('credit', 15, 2)->default(0);            // the customer owes less (a payment, a cancelled sale)
            $table->string('source_type', 30)->nullable();           // morph alias: sale, customer_payment, customer
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255)->default('');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 6);

            $table->index(['customer_id', 'entry_date', 'id']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement('ALTER TABLE customer_ledger_entries ADD CONSTRAINT chk_customer_ledger_sides CHECK (debit >= 0 AND credit >= 0 AND ((debit > 0) <> (credit > 0)))');
        DB::statement("ALTER TABLE customer_ledger_entries ADD CONSTRAINT chk_customer_ledger_type CHECK (entry_type IN ('opening_balance','sale','sale_void','service_invoice','service_void','payment','payment_void','refund','refund_void','sale_return','balance_adjustment'))");

        DB::unprepared("CREATE TRIGGER customer_ledger_no_update BEFORE UPDATE ON customer_ledger_entries FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_ledger_entries is append-only'");
        DB::unprepared("CREATE TRIGGER customer_ledger_no_delete BEFORE DELETE ON customer_ledger_entries FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_ledger_entries is append-only'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS customer_ledger_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS customer_ledger_no_delete');
        Schema::dropIfExists('customer_ledger_entries');
    }
};
