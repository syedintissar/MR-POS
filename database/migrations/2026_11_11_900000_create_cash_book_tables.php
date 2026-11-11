<?php

use Database\Seeders\DocumentSequenceSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 11: the cash & bank book (additive).
 *  - money accounts: where the shop's money is (the cash drawer, a bank account, a wallet); every payment method pays into one
 *  - cash & bank entries: money moved between accounts, put in or taken out by the owner, an opening balance, and what a count found over or short
 *  - day closings: the drawer counted against the book, note by note, with the difference recorded
 *
 * An account has no balance column: its balance is always added up from the receipts, payments and expenses that already exist
 * (through their payment method) plus the entries here, so it can never disagree with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('kind', 20);                                  // cash | bank | wallet
            $table->string('account_number', 60)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);                // the cash drawer: cannot be switched off
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT chk_money_account_kind CHECK (kind IN ('cash','bank','wallet'))");

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('money_account_id')->nullable()->after('kind')->constrained('money_accounts')->restrictOnDelete();   // never set for customer credit: it is not money
        });

        Schema::create('money_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('txn_no', 30)->unique();
            $table->string('type', 20);                                  // opening_balance | transfer | owner_in | owner_out | count_over | count_short
            $table->date('txn_date');
            $table->foreignId('from_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();   // money leaves this account
            $table->foreignId('to_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();     // money arrives in this account
            $table->decimal('amount', 15, 2);
            $table->string('reference_no', 100)->nullable();             // deposit slip, transaction id
            $table->string('description', 255);
            $table->string('status', 20)->default('posted');             // posted | voided
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['from_account_id', 'txn_date']);
            $table->index(['to_account_id', 'txn_date']);
            $table->index('txn_date');
        });
        DB::statement("ALTER TABLE money_transactions ADD CONSTRAINT chk_money_transaction CHECK (amount > 0
            AND ((type = 'transfer' AND from_account_id IS NOT NULL AND to_account_id IS NOT NULL AND from_account_id <> to_account_id)
              OR (type IN ('opening_balance','owner_in','count_over') AND from_account_id IS NULL AND to_account_id IS NOT NULL)
              OR (type IN ('owner_out','count_short') AND from_account_id IS NOT NULL AND to_account_id IS NULL))
            AND status IN ('posted','voided') AND ((status = 'voided') = (voided_at IS NOT NULL)))");

        Schema::create('day_closings', function (Blueprint $table) {
            $table->id();
            $table->string('closing_no', 30)->unique();
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->date('business_date');
            $table->dateTime('closed_at');
            $table->decimal('expected_amount', 15, 2);                   // what the book said was in the drawer at that moment
            $table->decimal('counted_amount', 15, 2);                    // what was really there
            $table->decimal('difference', 15, 2);                        // counted - expected: above zero is over, below zero is short
            $table->json('denominations')->nullable();                   // how many notes of each kind, and the coins
            $table->string('notes', 255)->nullable();                    // the explanation; required when there is a difference
            $table->foreignId('adjustment_transaction_id')->nullable()->constrained('money_transactions')->restrictOnDelete();   // the entry that brings the book to the count
            $table->string('status', 20)->default('posted');             // posted | voided
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['money_account_id', 'status', 'id']);
            $table->index('business_date');
        });
        DB::statement("ALTER TABLE day_closings ADD CONSTRAINT chk_day_closing CHECK (counted_amount >= 0 AND ABS(counted_amount - expected_amount - difference) < 0.005
            AND ((difference = 0) = (adjustment_transaction_id IS NULL))
            AND status IN ('posted','voided') AND ((status = 'voided') = (voided_at IS NOT NULL)))");

        // ------------------------------------------------------------------------------------------------ the accounts every shop starts with
        // The cash drawer for cash, one bank account for bank and card methods, and an account of its own for each wallet.
        // Names and the method links can be changed afterwards (Cash & bank → Accounts).
        $now = now();
        $account = function (string $name, string $kind, bool $system, int $order) use ($now): int {
            $name = mb_substr($name, 0, 60);
            $existing = DB::table('money_accounts')->where('name', $name)->value('id');

            return (int) ($existing ?? DB::table('money_accounts')->insertGetId([
                'name' => $name, 'kind' => $kind, 'is_active' => true, 'is_system' => $system, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]));
        };

        $cash = $account('Cash drawer', 'cash', true, 10);
        $bank = null;
        $order = 20;
        foreach (DB::table('payment_methods')->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'kind']) as $method) {
            $target = match ($method->kind) {
                'cash' => $cash,
                'bank', 'card' => $bank ??= $account('Bank account', 'bank', false, 20),
                'wallet' => $account($method->name, 'wallet', false, $order += 10),
                default => null,   // customer credit is not money; "other" methods are linked by the administrator
            };
            if ($target !== null) {
                DB::table('payment_methods')->where('id', $method->id)->update(['money_account_id' => $target]);
            }
        }

        // the two new document numbers (CBK-000001, DCL-000001) and the new permissions for the default roles that hold them
        (new DocumentSequenceSeeder)->run();
        (new PermissionSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('day_closings');
        Schema::dropIfExists('money_transactions');
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('money_account_id');
        });
        Schema::dropIfExists('money_accounts');
        DB::table('document_sequences')->whereIn('doc_type', ['money_transaction', 'day_closing'])->delete();
    }
};
