<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The owner's decisions after Phase 8 (additive):
 *  - returns: a restocking fee, a return-period exception reason, and the link to the replacement sale of an exchange
 *  - purchase returns: how the credit was split (taken off the bill / kept as supplier credit / refunded), so credit can be used later
 *  - supplier credit applications: credit used on a bill, one row per use, never twice
 *  - a new permission for return-period exceptions; cashiers lose the expenses permissions (only managers and administrators keep them)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->decimal('restocking_fee', 15, 2)->default(0)->after('total');                 // kept by the shop out of the value of the returned goods
            $table->string('period_exception_reason', 255)->nullable()->after('reason');          // set when the return period had passed and a manager allowed it
            $table->foreignId('exchange_sale_id')->nullable()->after('store_credit_payment_id')->constrained('sales')->restrictOnDelete();   // the replacement sale of an exchange
        });
        DB::statement('ALTER TABLE sale_returns DROP CONSTRAINT chk_sale_return');
        DB::statement("ALTER TABLE sale_returns ADD CONSTRAINT chk_sale_return CHECK (net_total >= 0 AND tax_total >= 0 AND total > 0 AND applied_to_due >= 0 AND refund_amount >= 0 AND store_credit_amount >= 0
            AND restocking_fee >= 0 AND restocking_fee <= net_total
            AND ABS(net_total + tax_total - total) < 0.005 AND ABS(applied_to_due + refund_amount + store_credit_amount - (total - restocking_fee)) < 0.005
            AND status IN ('posted','voided') AND ((status = 'voided') = (voided_at IS NOT NULL)))");

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->decimal('applied_to_due', 15, 2)->default(0)->after('total');      // taken off what was still owed on the bill
            $table->decimal('credit_amount', 15, 2)->default(0)->after('applied_to_due');   // left with the supplier as credit the shop can use on later bills
            $table->decimal('refund_amount', 15, 2)->default(0)->after('credit_amount');     // paid back by the supplier
        });
        // returns made before this change: the refund is the payment's amount; a return without a bill kept all the rest as credit; one with a bill is treated as taken off the bill
        DB::statement('UPDATE purchase_returns r LEFT JOIN supplier_payments p ON p.id = r.supplier_payment_id
                          SET r.refund_amount = COALESCE(p.amount, 0),
                              r.credit_amount = CASE WHEN r.purchase_id IS NULL THEN r.total - COALESCE(p.amount, 0) ELSE 0 END,
                              r.applied_to_due = CASE WHEN r.purchase_id IS NULL THEN 0 ELSE r.total - COALESCE(p.amount, 0) END');
        DB::statement('ALTER TABLE purchase_returns ADD CONSTRAINT chk_purchase_return_split CHECK (applied_to_due >= 0 AND credit_amount >= 0 AND refund_amount >= 0 AND ABS(applied_to_due + credit_amount + refund_amount - total) < 0.005)');

        // credit used on a bill: money the shop does not have to pay because the supplier already owed it (the supplier's balance does not change: it already said so)
        Schema::create('supplier_credit_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('posted');
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['purchase_return_id', 'status']);
            $table->index(['purchase_id', 'status']);
        });
        DB::statement("ALTER TABLE supplier_credit_applications ADD CONSTRAINT chk_supplier_credit_application CHECK (amount > 0 AND status IN ('posted','voided') AND ((status = 'voided') = (voided_at IS NOT NULL)))");

        // permissions: the new one is created and given to the default roles that hold it; cashiers no longer see or record expenses
        (new PermissionSeeder)->run();
        if ($cashier = Role::query()->where('name', 'Cashier')->where('guard_name', 'web')->first()) {
            $cashier->revokePermissionTo(array_values(array_filter(['expenses.view', 'expenses.create'], fn ($p) => $cashier->hasPermissionTo($p))));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_applications');
        DB::statement('ALTER TABLE purchase_returns DROP CONSTRAINT chk_purchase_return_split');
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn(['applied_to_due', 'credit_amount', 'refund_amount']);
        });
        DB::statement('ALTER TABLE sale_returns DROP CONSTRAINT chk_sale_return');
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exchange_sale_id');
            $table->dropColumn(['restocking_fee', 'period_exception_reason']);
        });
        DB::statement("ALTER TABLE sale_returns ADD CONSTRAINT chk_sale_return CHECK (net_total >= 0 AND tax_total >= 0 AND total > 0 AND applied_to_due >= 0 AND refund_amount >= 0 AND store_credit_amount >= 0
            AND ABS(net_total + tax_total - total) < 0.005 AND ABS(applied_to_due + refund_amount + store_credit_amount - total) < 0.005
            AND status IN ('posted','voided') AND ((status = 'voided') = (voided_at IS NOT NULL)))");
    }
};
