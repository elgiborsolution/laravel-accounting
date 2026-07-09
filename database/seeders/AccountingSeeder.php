<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            AccountCategorySeeder::class,
            AccountSeeder::class,
            ServiceSeeder::class,
            ServiceAccountSeeder::class,
            ReportMappingSeeder::class,
        ] as $seederClass) {
            $seeder = app($seederClass);

            if (method_exists($seeder, 'setContainer')) {
                $seeder->setContainer(app());
            }

            $seeder->run();
        }
    }
}
