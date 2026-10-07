<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /** code => [name, kind, requires a reference, system method] */
    public const METHODS = [
        'cash' => ['Cash', 'cash', false, true],
        'bank_transfer' => ['Bank Transfer', 'bank', true, false],
        'card' => ['Card', 'card', false, false],
        'easypaisa' => ['Easypaisa', 'wallet', true, false],
        'jazzcash' => ['JazzCash', 'wallet', true, false],
        'cheque' => ['Cheque', 'bank', true, false],
        'customer_credit' => ['Customer Credit', 'customer_credit', false, true],
    ];

    /** Safe to run again: only missing methods are created, nothing the shop has renamed or switched off is touched. */
    public function run(): void
    {
        $order = 0;
        foreach (self::METHODS as $code => [$name, $kind, $needsRef, $system]) {
            $order += 10;
            PaymentMethod::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'kind' => $kind, 'requires_reference' => $needsRef, 'is_active' => true, 'is_system' => $system, 'sort_order' => $order,
            ]);
        }
    }
}
