<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('code', 30)->unique();
            $table->string('kind', 20);                              // cash | bank | card | wallet | customer_credit | other
            $table->boolean('requires_reference')->default(false);   // e.g. a bank or wallet transaction id
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);            // cannot be deleted or renamed away (Cash, Customer Credit)
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Default methods, so an installation that only runs the migration works at once. Names stay editable afterwards.
        $order = 0;
        foreach ([
            ['cash', 'Cash', 'cash', false, true], ['bank_transfer', 'Bank Transfer', 'bank', true, false], ['card', 'Card', 'card', false, false],
            ['easypaisa', 'Easypaisa', 'wallet', true, false], ['jazzcash', 'JazzCash', 'wallet', true, false], ['cheque', 'Cheque', 'bank', true, false],
            ['customer_credit', 'Customer Credit', 'customer_credit', false, true],
        ] as [$code, $name, $kind, $needsReference, $system]) {
            DB::table('payment_methods')->insert([
                'code' => $code, 'name' => $name, 'kind' => $kind, 'requires_reference' => $needsReference, 'is_active' => true,
                'is_system' => $system, 'sort_order' => ($order += 10), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT chk_payment_method_kind CHECK (kind IN ('cash','bank','card','wallet','customer_credit','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
