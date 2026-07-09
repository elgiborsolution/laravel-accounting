<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use ESolution\LaravelAccounting\Database\Seeders\Concerns\LoadsLocalizedSeederData;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Models\ServiceAccount;
use Illuminate\Database\Seeder;
use RuntimeException;

class ServiceAccountSeeder extends Seeder
{
    use LoadsLocalizedSeederData;

    public function run(): void
    {
        $services = Service::query()->get()->keyBy('service_code');
        $accounts = Account::query()->get()->keyBy('code');
        $mappings = $this->loadLocalizedSeederData('service_accounts.php');

        foreach ($mappings as $template) {
            $serviceCode = $template['service_code'] ?? null;
            $accountCode = $template['account_code'] ?? null;

            $service = $services->get($serviceCode);

            if (! $service) {
                throw new RuntimeException("Default service mapping seeding failed. Service code [{$serviceCode}] is not available.");
            }

            $account = $accountCode ? $accounts->get($accountCode) : null;
            if (! $account && ! empty($template['account_code'])) {
                throw new RuntimeException("Default service mapping seeding failed. Account code [{$template['account_code']}] is not available for mapping [{$template['mapping_key']}].");
            }

            ServiceAccount::updateOrCreate(
                ['mapping_key' => $template['mapping_key']],
                [
                    'service_id' => $service->id,
                    'mapping_name' => $template['mapping_name'],
                    'position' => $template['position'],
                    'account_id' => $account?->id,
                    'sequence_no' => $template['sequence_no'] ?? 0,
                    'is_dynamic' => $template['is_dynamic'] ?? false,
                    'is_required' => $template['is_required'] ?? true,
                    'is_active' => $template['is_active'] ?? true,
                ],
            );
        }
    }
}
