<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_no', 30)->nullable()->unique();     // assigned when received; drafts have none
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('supplier_invoice_no', 50)->nullable();
            // The same supplier invoice number may not be entered twice, but a CANCELLED purchase frees its number
            // so the corrected bill can be entered again.
            $table->string('active_invoice_key', 50)->nullable()
                ->virtualAs("CASE WHEN status = 'voided' THEN NULL ELSE supplier_invoice_no END");
            $table->date('purchase_date');
            $table->string('status', 20)->default('draft');              // draft | received | voided
            $table->decimal('subtotal', 15, 2)->default(0);              // sum of the lines (qty x cost - line discount + line tax)
            $table->decimal('discount_amount', 15, 2)->default(0);       // invoice-level discount
            $table->decimal('tax_amount', 15, 2)->default(0);            // invoice-level tax
            $table->decimal('additional_cost', 15, 2)->default(0);       // freight, loading ...
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);           // cache: sum of effective payment allocations
            $table->decimal('returned_amount', 15, 2)->default(0);       // cache: filled by purchase returns (later phase)
            $table->decimal('due_amount', 15, 2)->default(0);            // cache: grand_total - returned - paid
            $table->string('payment_status', 20)->default('unpaid');     // unpaid | partial | paid
            $table->text('notes')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'active_invoice_key']);
            $table->index(['supplier_id', 'purchase_date']);
            $table->index(['status', 'payment_status']);
            $table->index('purchase_date');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('discount_amount', 15, 2)->default(0);       // line discount (total for the line)
            $table->decimal('tax_amount', 15, 2)->default(0);            // line tax (total for the line)
            $table->decimal('line_total', 15, 2)->default(0);
            $table->decimal('landed_unit_cost', 15, 4)->nullable();      // set when received: cost incl. share of discount/tax/freight
            $table->decimal('returned_quantity', 12, 3)->default(0);     // cache: filled by purchase returns (later phase)

            $table->index('product_id');
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 30)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('payment_date');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('type', 20)->default('payment');              // payment (we pay) | refund (supplier pays us back)
            $table->decimal('amount', 15, 2);
            $table->string('reference_no', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->string('status', 20)->default('posted');             // posted | voided
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'payment_date']);
        });

        // Which purchase(s) a payment settles. One payment can settle many bills; what is left over is an advance.
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['supplier_payment_id', 'purchase_id']);
            $table->index('purchase_id');
        });

        DB::statement("ALTER TABLE purchases ADD CONSTRAINT chk_purchase_status CHECK (status IN ('draft','received','voided'))");
        DB::statement("ALTER TABLE purchases ADD CONSTRAINT chk_purchase_payment_status CHECK (payment_status IN ('unpaid','partial','paid'))");
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT chk_purchase_amounts CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND additional_cost >= 0 AND grand_total >= 0 AND paid_amount >= 0 AND returned_amount >= 0)');
        DB::statement("ALTER TABLE purchases ADD CONSTRAINT chk_purchase_number CHECK (status = 'draft' OR purchase_no IS NOT NULL)");
        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT chk_purchase_item_qty CHECK (quantity > 0 AND unit_cost >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND returned_quantity >= 0 AND returned_quantity <= quantity)');
        DB::statement("ALTER TABLE supplier_payments ADD CONSTRAINT chk_supplier_payment CHECK (amount > 0 AND type IN ('payment','refund') AND status IN ('posted','voided'))");
        DB::statement('ALTER TABLE purchase_payments ADD CONSTRAINT chk_purchase_payment_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
