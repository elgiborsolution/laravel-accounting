<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            AccountCategorySeeder::class,
            DefaultCoaSeeder::class,
        ]);
    }
}
