<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A part bought outside (from the market) for one job: billed like a part, never in stock. It is a charge line that
        // carries what the shop paid for it, so the job's profit is right.
        Schema::table('service_job_items', function (Blueprint $table) {
            $table->boolean('outside_part')->default(false)->after('line_type');
            $table->string('outside_source', 100)->nullable()->after('outside_part');   // where it was bought (optional)
        });
        DB::statement("ALTER TABLE service_job_items ADD CONSTRAINT chk_service_item_outside CHECK (outside_part = 0 OR line_type = 'charge')");

        // Toner powder is bought by the kilogram packet and used by the gram: the product is kept in its small unit and may
        // name the bigger unit it is bought in, with how many small units one of those holds.
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('purchase_unit_id')->nullable()->after('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('purchase_unit_factor', 12, 3)->nullable()->after('purchase_unit_id');
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_purchase_unit CHECK ((purchase_unit_id IS NULL) = (purchase_unit_factor IS NULL) AND (purchase_unit_factor IS NULL OR purchase_unit_factor > 0))');

        // When the rest of a credit bill is to be paid: the reminders on the dashboard are built from these.
        Schema::table('purchases', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('purchase_date');
            $table->index(['status', 'due_date']);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('sale_date');   // when the customer promised to pay what is left on account
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date']);
            $table->dropColumn('due_date');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date']);
            $table->dropColumn('due_date');
        });
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_purchase_unit');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_unit_id');
            $table->dropColumn('purchase_unit_factor');
        });
        DB::statement('ALTER TABLE service_job_items DROP CONSTRAINT chk_service_item_outside');
        Schema::table('service_job_items', function (Blueprint $table) {
            $table->dropColumn(['outside_part', 'outside_source']);
        });
    }
};
