<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A receipt taken at the till belongs to the sale it was taken for. A payment collected later from a customer (settling one or
        // several invoices) has no sale of its own. Cancelling a sale cancels ITS receipts, never a collected payment that happened to
        // settle it among others.
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('customer_id')->constrained('sales')->restrictOnDelete();
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
