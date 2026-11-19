<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe seeding: roles/permissions and the default chart of accounts (idempotent). No fake business data.
 * Create the first administrator with:  php artisan erp:create-admin
 * Local demo data (never in production):  php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, ChartOfAccountsSeeder::class]);
    }
}
