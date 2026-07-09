<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use ESolution\LaravelAccounting\Database\Seeders\Concerns\LoadsLocalizedSeederData;
use ESolution\LaravelAccounting\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    use LoadsLocalizedSeederData;

    public function run(): void
    {
        $services = $this->loadLocalizedSeederData('services.php');

        foreach ($services as $service) {
            Service::updateOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'service_name' => $service['service_name'],
                    'module_name' => $service['module_name'],
                    'description' => $service['description'] ?? null,
                    'is_active' => $service['is_active'] ?? true,
                ],
            );
        }
    }
}
