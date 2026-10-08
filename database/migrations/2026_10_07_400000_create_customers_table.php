<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150)->index();
            $table->string('company', 150)->nullable();
            $table->string('phone', 30)->nullable()->index();           // not unique: families and companies share numbers
            $table->string('whatsapp', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('price_tier', 20)->default('retail');         // retail | wholesale
            $table->boolean('allow_credit')->default(false);
            $table->decimal('credit_limit', 15, 2)->nullable();          // NULL = unlimited (when credit is allowed)
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);               // cache of the customer ledger (Phase 6): + = customer owes the shop
            $table->boolean('is_walk_in')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // At most ONE walk-in customer, enforced by the database: the generated column is 1 for the walk-in row and NULL for everyone else,
        // and a UNIQUE index allows many NULLs but only one 1.
        DB::statement('ALTER TABLE customers ADD COLUMN walk_in_key TINYINT AS (CASE WHEN is_walk_in = 1 THEN 1 ELSE NULL END) VIRTUAL');
        DB::statement('ALTER TABLE customers ADD UNIQUE INDEX customers_walk_in_key_unique (walk_in_key)');

        DB::statement("ALTER TABLE customers ADD CONSTRAINT chk_customer_tier CHECK (price_tier IN ('retail','wholesale'))");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customer_credit_limit CHECK (credit_limit IS NULL OR credit_limit >= 0)');
        // BR-11: the walk-in customer cannot buy on credit and cannot hold a balance.
        DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_walk_in_no_credit CHECK (is_walk_in = 0 OR (allow_credit = 0 AND balance = 0 AND opening_balance = 0))');

        DB::table('customers')->insert([
            'code' => 'WALK-IN', 'name' => 'Walk-in customer', 'price_tier' => 'retail', 'allow_credit' => false,
            'is_walk_in' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
