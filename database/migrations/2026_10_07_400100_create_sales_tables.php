<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 30)->unique();
            $table->char('idempotency_key', 36)->unique();                // one per checkout attempt: a double click or a retry cannot sell twice
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();          // the cashier
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();   // manager approval, if one was needed
            $table->dateTime('sale_date');
            $table->string('price_tier', 20)->default('retail');         // snapshot
            $table->string('status', 20)->default('completed');          // completed | voided
            $table->boolean('tax_inclusive')->default(false);            // snapshot: were the entered prices tax-inclusive?
            $table->decimal('items_total', 15, 2)->default(0);           // SUM(qty x price) before any discount
            $table->decimal('line_discount_total', 15, 2)->default(0);
            $table->decimal('invoice_discount_amount', 15, 2)->default(0);
            $table->decimal('invoice_discount_percent', 5, 2)->nullable();   // if the cashier typed a percentage
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('round_off', 15, 2)->default(0);             // may be negative
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);           // cache: payments of this sale that stand
            $table->decimal('returned_amount', 15, 2)->default(0);       // cache: filled by sales returns (Phase 8)
            $table->decimal('due_amount', 15, 2)->default(0);            // cache
            $table->string('payment_status', 20)->default('unpaid');     // unpaid | partial | paid
            $table->decimal('amount_tendered', 15, 2)->nullable();       // cash handling, for the receipt
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('print_notes')->default(false);
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->timestamps();

            $table->index('sale_date');
            $table->index(['customer_id', 'sale_date']);
            $table->index(['user_id', 'sale_date']);
            $table->index(['status', 'payment_status']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name', 200);                         // snapshots: an invoice never changes when a product is renamed
            $table->string('sku', 50);
            $table->decimal('quantity', 12, 3);
            $table->decimal('list_price', 15, 2);                        // the price list's price (to report overrides)
            $table->decimal('unit_price', 15, 2);                        // the price charged
            $table->decimal('discount_amount', 15, 2)->default(0);       // line discount (total for the line)
            $table->decimal('invoice_discount_share', 15, 2)->default(0); // this line's share of the invoice discount
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);            // excluding tax, after both discounts: the revenue
            $table->decimal('line_total', 15, 2)->default(0);            // net_amount + tax_amount
            $table->decimal('unit_cost', 15, 4)->default(0);             // average cost at the moment of sale (0 for services)
            $table->string('serial_number', 100)->nullable();
            $table->date('warranty_until')->nullable();
            $table->decimal('returned_quantity', 12, 3)->default(0);     // cache: filled by sales returns (Phase 8)

            $table->index(['product_id', 'sale_id']);
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 30)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->dateTime('payment_date');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('type', 20)->default('receipt');              // receipt (money in) | refund (money out)
            $table->decimal('amount', 15, 2);
            $table->string('reference_no', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->string('status', 20)->default('posted');             // posted | voided
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'payment_date']);
            $table->index('payment_method_id');
        });

        // Which sale(s) a payment settles. Every payment taken at the till is one receipt settling exactly one sale.
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained('customer_payments')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['customer_payment_id', 'sale_id']);
            $table->index('sale_id');
        });

        // A held cart is not a document: no number, no stock reserved, deleted when resumed or discarded.
        Schema::create('held_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('label', 100);
            $table->json('cart');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE sales ADD CONSTRAINT chk_sale_status CHECK (status IN ('completed','voided'))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT chk_sale_payment_status CHECK (payment_status IN ('unpaid','partial','paid'))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT chk_sale_tier CHECK (price_tier IN ('retail','wholesale'))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT chk_sale_amounts CHECK (items_total >= 0 AND line_discount_total >= 0 AND invoice_discount_amount >= 0 AND tax_total >= 0 AND grand_total >= 0 AND paid_amount >= 0 AND returned_amount >= 0 AND due_amount >= 0 AND change_amount >= 0)');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT chk_sale_void CHECK ((status = 'completed' AND voided_at IS NULL) OR (status = 'voided' AND voided_at IS NOT NULL))");
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT chk_sale_item CHECK (quantity > 0 AND list_price >= 0 AND unit_price >= 0 AND discount_amount >= 0 AND invoice_discount_share >= 0 AND tax_rate >= 0 AND tax_amount >= 0 AND net_amount >= 0 AND line_total >= 0 AND unit_cost >= 0 AND returned_quantity >= 0 AND returned_quantity <= quantity)');
        DB::statement("ALTER TABLE customer_payments ADD CONSTRAINT chk_customer_payment CHECK (amount > 0 AND type IN ('receipt','refund') AND status IN ('posted','voided'))");
        DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT chk_sale_payment_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('held_sales');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
