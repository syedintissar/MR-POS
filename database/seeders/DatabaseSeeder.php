<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data only. No users are created here: the first administrator is created by
     * `php artisan pos:install`, which asks for a password (no default password is ever shipped).
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            DocumentSequenceSeeder::class,
            CatalogueSeeder::class,
            PaymentMethodSeeder::class,
        ]);
    }
}
