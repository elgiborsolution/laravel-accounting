<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Support\ServiceCatalog;
use Illuminate\Database\Seeder;

class DefaultAccountingServicesSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = app(ServiceCatalog::class);

        foreach ($catalog->all() as $service) {
            Service::updateOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'service_name' => $service['service_name'],
                    'module_name' => $service['module_name'],
                    'description' => $service['description'],
                    'is_active' => $service['is_active'],
                ],
            );
        }
    }
}
