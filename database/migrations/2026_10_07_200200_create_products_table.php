<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('stock');           // stock | service (service has no inventory)
            $table->string('name', 200);
            $table->string('sku', 50)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('model', 100)->nullable();
            $table->text('description')->nullable();

            // Cost fields are maintained by the system (purchases, opening stock, returns), never typed in.
            $table->decimal('last_purchase_cost', 15, 4)->default(0);
            $table->decimal('average_cost', 15, 4)->default(0);

            $table->decimal('sale_price', 15, 2)->default(0);
            $table->decimal('wholesale_price', 15, 2)->nullable();
            $table->decimal('min_sale_price', 15, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();           // NULL = use the default rate from Settings

            // current_stock is a cache of the stock_movements ledger. Only InventoryService may change it.
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('min_stock_level', 12, 3)->nullable();   // NULL = use the global low-stock level

            $table->string('rack_location', 50)->nullable();
            $table->foreignId('default_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->string('warranty_terms', 255)->nullable();
            $table->string('image_path', 255)->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'category_id']);
            $table->index('brand_id');
            $table->index('model');
            $table->index('name');
            $table->fullText(['name', 'model']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT chk_products_type CHECK (type IN ('stock','service'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_prices CHECK (sale_price >= 0 AND (wholesale_price IS NULL OR wholesale_price >= 0) AND (min_sale_price IS NULL OR min_sale_price >= 0))');
        DB::statement("ALTER TABLE products ADD CONSTRAINT chk_products_service_no_stock CHECK (type = 'stock' OR current_stock = 0)");
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_costs CHECK (last_purchase_cost >= 0 AND average_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
