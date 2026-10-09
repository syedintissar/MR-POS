<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypeSeeder extends Seeder
{
    public const TYPES = ['Printer repair', 'Printer servicing', 'Toner refill', 'Cartridge refill', 'Cartridge repair', 'Drum replacement', 'Blade replacement', 'Printer installation', 'Troubleshooting', 'Maintenance', 'Other'];

    /** Safe to run again: only missing types are created; renamed, re-priced or switched-off types are left alone. */
    public function run(): void
    {
        foreach (self::TYPES as $i => $name) {
            ServiceType::query()->firstOrCreate(['name' => $name], ['is_active' => true, 'sort_order' => ($i + 1) * 10]);
        }
    }
}
