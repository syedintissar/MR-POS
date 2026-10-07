<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the shop owes a supplier, as a list of entries. suppliers.balance is only a cache of this list:
        //   balance = SUM(credit) - SUM(debit);  positive = we owe the supplier, negative = the supplier owes us (advance).
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('entry_date');
            $table->string('entry_type', 30);
            $table->decimal('debit', 15, 2)->default(0);             // we paid / goods returned: owe less
            $table->decimal('credit', 15, 2)->default(0);            // we received goods: owe more
            $table->string('source_type', 30)->nullable();           // morph alias: purchase, supplier_payment, supplier
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255)->default('');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 6);

            $table->index(['supplier_id', 'entry_date', 'id']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement('ALTER TABLE supplier_ledger_entries ADD CONSTRAINT chk_supplier_ledger_sides CHECK (debit >= 0 AND credit >= 0 AND ((debit > 0) <> (credit > 0)))');
        DB::statement("ALTER TABLE supplier_ledger_entries ADD CONSTRAINT chk_supplier_ledger_type CHECK (entry_type IN ('opening_balance','purchase','purchase_void','payment','payment_void','purchase_return','refund_received','balance_adjustment'))");

        DB::unprepared("CREATE TRIGGER supplier_ledger_no_update BEFORE UPDATE ON supplier_ledger_entries FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'supplier_ledger_entries is append-only'");
        DB::unprepared("CREATE TRIGGER supplier_ledger_no_delete BEFORE DELETE ON supplier_ledger_entries FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'supplier_ledger_entries is append-only'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS supplier_ledger_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS supplier_ledger_no_delete');
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
