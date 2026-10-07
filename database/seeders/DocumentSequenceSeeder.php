<?php

namespace Database\Seeders;

use App\Models\DocumentSequence;
use Illuminate\Database\Seeder;

class DocumentSequenceSeeder extends Seeder
{
    /** doc_type => [label, prefix] */
    public const TYPES = [
        'sale' => ['Sales invoice', 'INV-'],
        'service_job' => ['Service job card', 'JOB-'],
        'service_invoice' => ['Service invoice', 'SRV-'],
        'purchase' => ['Purchase', 'PUR-'],
        'customer_payment' => ['Customer payment receipt', 'RCP-'],
        'supplier_payment' => ['Supplier payment', 'PAY-'],
        'sale_return' => ['Sales return', 'SRN-'],
        'purchase_return' => ['Purchase return', 'PRN-'],
        'expense' => ['Expense', 'EXP-'],
        'stock_adjustment' => ['Stock adjustment', 'ADJ-'],
        'customer' => ['Customer code', 'CUS-'],
        'supplier' => ['Supplier code', 'SUP-'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type => [$label, $prefix]) {
            // Only creates missing rows: a customised prefix or a running counter is never reset.
            DocumentSequence::query()->firstOrCreate(
                ['doc_type' => $type],
                ['label' => $label, 'prefix' => $prefix, 'next_number' => 1, 'padding' => 6, 'reset_yearly' => false],
            );
        }
    }
}
