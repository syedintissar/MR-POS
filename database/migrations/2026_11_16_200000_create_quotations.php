<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A price offer to a customer or an office. It never touches stock or any account: it becomes a sale only when it is
        // opened in the till and paid for (or sold on credit) there.
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_no', 30)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();   // or just a typed name
            $table->string('customer_name', 150);
            $table->string('customer_phone', 30)->nullable();
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('open');               // open | accepted | cancelled ("expired" is open past its date)
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->text('notes')->nullable();                           // printed: delivery, warranty, payment terms
            $table->foreignId('sale_id')->nullable()->constrained('sales')->restrictOnDelete();          // the invoice it became
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'quote_date']);
            $table->index('customer_id');
        });
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT chk_quotation_status CHECK (status IN ('open','accepted','cancelled') AND subtotal >= 0 AND discount_amount >= 0 AND grand_total >= 0)");

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();    // NULL: something typed, not in the product list
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
        });
        DB::statement('ALTER TABLE quotation_items ADD CONSTRAINT chk_quotation_item CHECK (quantity > 0 AND unit_price >= 0 AND line_total >= 0)');

        DB::table('document_sequences')->insertOrIgnore([
            'doc_type' => 'quotation', 'label' => 'Quotation', 'prefix' => 'QTN-', 'next_number' => 1, 'padding' => 6, 'reset_yearly' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        DB::table('document_sequences')->where('doc_type', 'quotation')->delete();
    }
};
