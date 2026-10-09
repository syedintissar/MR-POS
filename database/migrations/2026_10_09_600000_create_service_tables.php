<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const STATUSES = ['received', 'inspection_pending', 'diagnosing', 'waiting_for_approval', 'approved', 'in_progress', 'waiting_for_parts', 'completed', 'delivered', 'cancelled'];

    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->decimal('default_charge', 15, 2)->nullable();       // suggested labour / refill charge, added to a new job when ticked
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE service_types ADD CONSTRAINT chk_service_type_charge CHECK (default_charge IS NULL OR default_charge >= 0)');

        $now = now();
        foreach (['Printer repair', 'Printer servicing', 'Toner refill', 'Cartridge refill', 'Cartridge repair', 'Drum replacement', 'Blade replacement', 'Printer installation', 'Troubleshooting', 'Maintenance', 'Other'] as $i => $name) {
            DB::table('service_types')->insert(['name' => $name, 'default_charge' => null, 'is_active' => true, 'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_no', 30)->unique();
            $table->string('invoice_no', 30)->nullable()->unique();     // assigned at delivery
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('contact_name', 150)->nullable();            // who to call (a walk-in customer has no name of its own)
            $table->string('contact_phone', 30)->nullable();
            $table->foreignId('service_type_id')->constrained('service_types')->restrictOnDelete();
            $table->string('device_type', 20)->default('printer');      // printer | toner | cartridge | other
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable()->index();
            $table->string('cartridge_model', 100)->nullable();
            $table->text('reported_problem');
            $table->text('diagnosis')->nullable();
            $table->string('accessories_received', 255)->nullable();
            $table->dateTime('received_at');
            $table->dateTime('expected_delivery_at')->nullable();
            $table->foreignId('technician_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('priority', 10)->default('normal');          // normal | urgent
            $table->string('status', 30)->default('received');
            $table->decimal('estimate_amount', 15, 2)->nullable();
            $table->decimal('approved_amount', 15, 2)->nullable();      // the estimate the customer agreed to
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('approval_method', 20)->nullable();          // in_person | phone | whatsapp
            // caches: only ServiceJobService writes them, the integrity check recomputes them
            $table->decimal('service_charges', 15, 2)->default(0);      // charge lines, after line discounts
            $table->decimal('parts_amount', 15, 2)->default(0);         // billed part lines, after line discounts
            $table->decimal('parts_cost', 15, 2)->default(0);           // what the parts cost the shop (internal)
            $table->decimal('discount_amount', 15, 2)->default(0);      // discount on the whole job
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('due_amount', 15, 2)->default(0);
            $table->string('payment_status', 20)->default('unpaid');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('invoiced_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('customer_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'technician_id']);
            $table->index('customer_id');
            $table->index('received_at');
        });

        $statuses = "'".implode("','", self::STATUSES)."'";
        DB::statement("ALTER TABLE service_jobs ADD CONSTRAINT chk_service_job_status CHECK (status IN ({$statuses}))");
        DB::statement("ALTER TABLE service_jobs ADD CONSTRAINT chk_service_job_enums CHECK (priority IN ('normal','urgent') AND device_type IN ('printer','toner','cartridge','other') AND payment_status IN ('unpaid','partial','paid') AND (approval_method IS NULL OR approval_method IN ('in_person','phone','whatsapp')))");
        DB::statement('ALTER TABLE service_jobs ADD CONSTRAINT chk_service_job_amounts CHECK (service_charges >= 0 AND parts_amount >= 0 AND parts_cost >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND grand_total >= 0 AND paid_amount >= 0 AND due_amount >= 0 AND (estimate_amount IS NULL OR estimate_amount >= 0) AND (approved_amount IS NULL OR approved_amount >= 0))');
        // a job has an invoice exactly when it was delivered, and is cancelled exactly when it says so: no half-finished states
        DB::statement("ALTER TABLE service_jobs ADD CONSTRAINT chk_service_job_terminal CHECK (((status = 'delivered') = (invoice_no IS NOT NULL AND delivered_at IS NOT NULL AND invoiced_at IS NOT NULL)) AND ((status = 'cancelled') = (cancelled_at IS NOT NULL)))");

        Schema::create('service_job_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->string('line_type', 10);                            // part | charge
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 15, 2);                       // billed
            $table->decimal('unit_cost', 15, 4)->default(0);            // snapshot of the average cost when the part was taken (internal)
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('line_total', 15, 2);                       // quantity x price, minus the line discount
            $table->string('status', 10)->default('active');            // active | removed (lines are never deleted)
            $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('added_at');
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('removed_at')->nullable();
            $table->string('removal_disposition', 20)->nullable();      // returned_to_stock | written_off
            $table->string('removal_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['service_job_id', 'status']);
        });
        DB::statement("ALTER TABLE service_job_items ADD CONSTRAINT chk_service_item_shape CHECK (line_type IN ('part','charge') AND ((line_type = 'part') = (product_id IS NOT NULL)) AND quantity > 0 AND unit_price >= 0 AND unit_cost >= 0 AND discount_amount >= 0 AND line_total >= 0 AND status IN ('active','removed') AND ((status = 'removed') = (removed_at IS NOT NULL)) AND (removal_disposition IS NULL OR (status = 'removed' AND removal_disposition IN ('returned_to_stock','written_off'))))");

        Schema::create('service_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('note', 255)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('changed_at', 6);

            $table->index(['service_job_id', 'id']);
        });
        DB::unprepared("CREATE TRIGGER service_history_no_update BEFORE UPDATE ON service_status_history FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'service_status_history is append-only'");
        DB::unprepared("CREATE TRIGGER service_history_no_delete BEFORE DELETE ON service_status_history FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'service_status_history is append-only'");

        // which receipt pays (part of) which job: advances taken while the job is open, and the payment taken at delivery
        Schema::create('service_job_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained('customer_payments')->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['customer_payment_id', 'service_job_id']);
        });
        DB::statement('ALTER TABLE service_job_payments ADD CONSTRAINT chk_service_job_payment_amount CHECK (amount > 0)');

        // a refund (money handed back) says which receipt it gives back, so the customer's credit stays exact
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->foreignId('refund_of_id')->nullable()->after('sale_id')->constrained('customer_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_of_id');
        });
        Schema::dropIfExists('service_job_payments');
        DB::unprepared('DROP TRIGGER IF EXISTS service_history_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS service_history_no_delete');
        Schema::dropIfExists('service_status_history');
        Schema::dropIfExists('service_job_items');
        Schema::dropIfExists('service_jobs');
        Schema::dropIfExists('service_types');
    }
};
