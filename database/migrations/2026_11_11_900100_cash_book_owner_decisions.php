<?php

use Database\Seeders\DocumentSequenceSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The owner's decisions after Phase 11 (additive):
 *  - one drawer per counter: a person can have a cash drawer of their own; the cash they take or pay lands there
 *  - every receipt, payment and expense now remembers the account it landed in, written by the database the moment the row is made,
 *    so giving a cashier another drawer (or pointing a method at another account) changes the future only, never the past
 *  - bank statement matching: the lines of a bank or wallet account are ticked against the statement and the statement is closed
 */
return new class extends Migration
{
    /**
     * Where a row of money lands: the account of its payment method; but cash that would go to the shop's main drawer goes to the drawer
     * of the person who entered it, when they have one. One expression, used by every trigger and by the back-fill.
     */
    private function accountOf(string $method, string $user): string
    {
        return "(SELECT CASE WHEN ma.is_system = 1 THEN COALESCE((SELECT u.money_account_id FROM users u WHERE u.id = {$user}), pm.money_account_id) ELSE pm.money_account_id END
                   FROM payment_methods pm LEFT JOIN money_accounts ma ON ma.id = pm.money_account_id WHERE pm.id = {$method})";
    }

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();   // this person's own cash drawer; empty = the shop's main drawer
        });

        foreach (['customer_payments', 'supplier_payments', 'expenses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('money_account_id')->nullable()->after('payment_method_id')->constrained('money_accounts')->restrictOnDelete();
            });
            // what exists already went to the account of its method (nobody had a drawer of their own yet)
            DB::statement("UPDATE {$name} t JOIN payment_methods pm ON pm.id = t.payment_method_id SET t.money_account_id = pm.money_account_id");
            // single-statement triggers on purpose: they survive any way a backup is restored
            DB::unprepared("CREATE TRIGGER {$name}_money_account BEFORE INSERT ON {$name} FOR EACH ROW
                SET NEW.money_account_id = COALESCE(NEW.money_account_id, {$this->accountOf('NEW.payment_method_id', 'NEW.created_by')})");
        }
        // an expense can be corrected on the day it was entered: if the way it was paid changes, so does the account
        DB::unprepared("CREATE TRIGGER expenses_money_account_update BEFORE UPDATE ON expenses FOR EACH ROW
            SET NEW.money_account_id = IF(NEW.payment_method_id <> OLD.payment_method_id, {$this->accountOf('NEW.payment_method_id', 'NEW.created_by')}, NEW.money_account_id)");

        // ------------------------------------------------------------------------------------------------ bank statement matching
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('reconciliation_no', 30)->unique();
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->date('statement_date');                              // the last day the statement covers
            $table->decimal('opening_balance', 15, 2);                   // the closing balance of the statement matched before this one (0 for the first)
            $table->decimal('statement_balance', 15, 2);                 // the closing balance printed on the statement
            $table->string('notes', 255)->nullable();
            $table->string('status', 20)->default('open');               // open | completed | voided
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['money_account_id', 'status', 'statement_date'], 'ix_bank_reconciliation_account');
        });
        DB::statement("ALTER TABLE bank_reconciliations ADD CONSTRAINT chk_bank_reconciliation CHECK (status IN ('open','completed','voided')
            AND ((status = 'completed') = (completed_at IS NOT NULL)) AND ((status = 'voided') = (voided_at IS NOT NULL)))");

        // one row per line of the book that was found on a statement; a line can be matched once only
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->restrictOnDelete();
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->string('source', 30);                                // customer_payment | supplier_payment | expense | money_transaction
            $table->unsignedBigInteger('source_id');
            $table->string('doc_no', 30);
            $table->date('line_date');
            $table->decimal('money_in', 15, 2)->default(0);              // as it was when it was matched: a later change is noticed
            $table->decimal('money_out', 15, 2)->default(0);

            $table->unique(['money_account_id', 'source', 'source_id'], 'uq_reconciled_line');
        });

        (new DocumentSequenceSeeder)->run();
        (new PermissionSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_items');
        Schema::dropIfExists('bank_reconciliations');
        DB::unprepared('DROP TRIGGER IF EXISTS expenses_money_account_update');
        foreach (['customer_payments', 'supplier_payments', 'expenses'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}_money_account");
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('money_account_id');
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('money_account_id');
        });
        DB::table('document_sequences')->where('doc_type', 'bank_reconciliation')->delete();
    }
};
