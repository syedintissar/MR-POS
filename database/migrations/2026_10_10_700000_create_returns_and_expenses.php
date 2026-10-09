<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MOVEMENT_TYPES_OLD = "'opening','purchase','purchase_return','purchase_void','sale','sale_return','sale_void','service_consumption','service_return','adjustment_in','adjustment_out'";

    public function up(): void
    {
        // ------------------------------------------------------------------------------------------------ expenses (EXP-01..03)
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $now = now();
        foreach (['Rent', 'Electricity', 'Salary', 'Internet', 'Transport', 'Maintenance', 'Tea / Refreshments', 'Office expense', 'Miscellaneous'] as $name) {
            DB::table('expense_categories')->insert(['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_no', 30)->unique();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->date('expense_date');
            $table->decimal('amount', 15, 2);
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('payee', 150)->nullable();
            $table->text('description');
            $table->string('attachment_path', 255)->nullable();           // a private file (jpg / png / pdf, up to 5 MB)
            $table->string('attachment_name', 255)->nullable();           // the name it had when it was uploaded
            $table->string('status', 20)->default('posted');              // posted | voided
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('expense_date');
            $table->index(['expense_category_id', 'expense_date']);
        });
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT chk_expense CHECK (amount > 0 AND status IN ('posted','voided') AND ((status = 'voided') = (voided_at IS NOT NULL)))");

        // ------------------------------------------------------------------------------------------------ sales returns (SRT-01..06)
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 30)->unique();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->dateTime('return_date');
            $table->decimal('net_total', 15, 2);                         // excluding tax
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2);                             // what the customer gets back: net + tax
            $table->decimal('applied_to_due', 15, 2)->default(0);        // 1. reduces what the customer still owes on the invoice
            $table->decimal('refund_amount', 15, 2)->default(0);         // 2. money handed back (cash / bank / wallet)
            $table->decimal('store_credit_amount', 15, 2)->default(0);   // 3. left on the account as credit to spend later
            $table->foreignId('refund_payment_id')->nullable()->constrained('customer_payments')->restrictOnDelete();
            $table->foreignId('store_credit_payment_id')->nullable()->constrained('customer_payments')->restrictOnDelete();
            $table->string('reason', 255);
            $table->string('status', 20)->default('posted');             // posted | voided
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['sale_id', 'status']);
            $table->index('return_date');
            $table->index('customer_id');
        });
        DB::statement("ALTER TABLE sale_returns ADD CONSTRAINT chk_sale_return CHECK (net_total >= 0 AND tax_total >= 0 AND total > 0 AND applied_to_due >= 0 AND refund_amount >= 0 AND store_credit_amount >= 0
            AND ABS(net_total + tax_total - total) < 0.005 AND ABS(applied_to_due + refund_amount + store_credit_amount - total) < 0.005
            AND status IN ('posted','voided') AND ((status = 'voided') = (voided_at IS NOT NULL)))");

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_net_price', 15, 4);                    // what was actually paid per unit, excluding tax, after both discounts
            $table->decimal('unit_tax', 15, 4)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->decimal('unit_cost', 15, 4)->default(0);             // the cost at the moment of the sale (from the invoice line)
            $table->string('item_condition', 20)->default('resellable'); // resellable | damaged | defective ("condition" is a reserved word)
            $table->boolean('restock')->default(false);                  // only restocked lines put units back in stock
            $table->index('sale_item_id');
        });
        DB::statement("ALTER TABLE sale_return_items ADD CONSTRAINT chk_sale_return_item CHECK (quantity > 0 AND net_amount >= 0 AND tax_amount >= 0 AND line_total >= 0 AND unit_cost >= 0 AND item_condition IN ('resellable','damaged','defective'))");

        // ------------------------------------------------------------------------------------------------ purchase returns (PRT-01..03)
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 30)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->restrictOnDelete();
            $table->dateTime('return_date');
            $table->decimal('subtotal', 15, 2);                          // what the supplier credits us, before tax
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->string('settlement', 20)->default('adjust_balance'); // adjust_balance (reduce what we owe) | refund (the supplier pays us back)
            $table->foreignId('supplier_payment_id')->nullable()->constrained('supplier_payments')->restrictOnDelete();   // the refund received
            $table->string('reason', 255);
            $table->string('status', 20)->default('posted');
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'return_date']);
            $table->index(['purchase_id', 'status']);
        });
        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT chk_purchase_return CHECK (subtotal >= 0 AND tax_amount >= 0 AND total > 0 AND ABS(subtotal + tax_amount - total) < 0.005
            AND settlement IN ('adjust_balance','refund') AND ((settlement = 'refund') = (supplier_payment_id IS NOT NULL))
            AND status IN ('posted','voided') AND ((status = 'voided') = (voided_at IS NOT NULL)))");

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->restrictOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 15, 4);                         // the cost the stock leaves at (the line's landed cost)
            $table->decimal('net_amount', 15, 2);                        // credited by the supplier, before tax
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->index('purchase_item_id');
        });
        DB::statement('ALTER TABLE purchase_return_items ADD CONSTRAINT chk_purchase_return_item CHECK (quantity > 0 AND unit_cost >= 0 AND net_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)');

        // ------------------------------------------------------------------------------------------------ what the ledgers must be able to say
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT chk_movement_type');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_movement_type CHECK (movement_type IN ('.self::MOVEMENT_TYPES_OLD.",'sale_return_void','purchase_return_void'))");

        DB::statement('ALTER TABLE customer_ledger_entries DROP CONSTRAINT chk_customer_ledger_type');
        DB::statement("ALTER TABLE customer_ledger_entries ADD CONSTRAINT chk_customer_ledger_type CHECK (entry_type IN ('opening_balance','sale','sale_void','service_invoice','service_void','payment','payment_void','refund','refund_void','sale_return','sale_return_void','balance_adjustment'))");

        DB::statement('ALTER TABLE supplier_ledger_entries DROP CONSTRAINT chk_supplier_ledger_type');
        DB::statement("ALTER TABLE supplier_ledger_entries ADD CONSTRAINT chk_supplier_ledger_type CHECK (entry_type IN ('opening_balance','purchase','purchase_void','payment','payment_void','purchase_return','purchase_return_void','refund_received','refund_received_void','balance_adjustment'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_ledger_entries DROP CONSTRAINT chk_supplier_ledger_type');
        DB::statement("ALTER TABLE supplier_ledger_entries ADD CONSTRAINT chk_supplier_ledger_type CHECK (entry_type IN ('opening_balance','purchase','purchase_void','payment','payment_void','purchase_return','refund_received','balance_adjustment'))");
        DB::statement('ALTER TABLE customer_ledger_entries DROP CONSTRAINT chk_customer_ledger_type');
        DB::statement("ALTER TABLE customer_ledger_entries ADD CONSTRAINT chk_customer_ledger_type CHECK (entry_type IN ('opening_balance','sale','sale_void','service_invoice','service_void','payment','payment_void','refund','refund_void','sale_return','balance_adjustment'))");
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT chk_movement_type');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_movement_type CHECK (movement_type IN ('.self::MOVEMENT_TYPES_OLD.'))');

        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
