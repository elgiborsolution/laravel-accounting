<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AccountCategorySeeder::class,
            DefaultCoaSeeder::class,
            DefaultAccountingServicesSeeder::class,
            DefaultServiceAccountMappingsSeeder::class,
        ]);
    }
}
