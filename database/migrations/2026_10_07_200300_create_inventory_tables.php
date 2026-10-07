<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 30)->unique();
            $table->date('adjustment_date');
            $table->string('reason', 30);                            // opening, count_correction, damage, loss, found, expiry, other
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('posted');         // posted | voided
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('adjustment_date');
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);                      // signed: + adds stock, - removes it
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->string('notes', 255)->nullable();

            $table->index('product_id');
        });

        // The stock ledger. Append-only: the truth about stock; products.current_stock is only a cache of it.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('movement_type', 30);
            $table->decimal('quantity', 12, 3);                      // signed
            $table->decimal('qty_before', 12, 3);
            $table->decimal('qty_after', 12, 3);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('avg_cost_after', 15, 4)->default(0);
            $table->string('source_type', 30)->nullable();           // morph alias of the document that caused it
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 6);

            $table->index(['product_id', 'id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['movement_type', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT chk_adjustment_status CHECK (status IN ('posted','voided'))");
        DB::statement('ALTER TABLE stock_adjustment_items ADD CONSTRAINT chk_adjustment_qty CHECK (quantity <> 0)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_movement_qty CHECK (quantity <> 0)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_movement_balance CHECK (qty_after = qty_before + quantity)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_movement_type CHECK (movement_type IN ('opening','purchase','purchase_return','purchase_void','sale','sale_return','sale_void','service_consumption','service_return','adjustment_in','adjustment_out'))");

        DB::unprepared("CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'");
        DB::unprepared("CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
