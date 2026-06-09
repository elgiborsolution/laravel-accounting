<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Models\ServiceAccount;
use ESolution\LaravelAccounting\Support\ServiceAccountTemplateRegistry;
use Illuminate\Database\Seeder;
use RuntimeException;

class DefaultServiceAccountMappingsSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(ServiceAccountTemplateRegistry::class);
        $services = Service::query()->get()->keyBy('service_code');
        $accounts = Account::query()->get()->keyBy('code');

        foreach ($registry->all() as $serviceCode => $templates) {
            $service = $services->get($serviceCode);

            if (! $service) {
                throw new RuntimeException("Default service mapping seeding failed. Service code [{$serviceCode}] is not available.");
            }

            foreach ($templates as $template) {
                $account = $accounts->get($template['account_code']);

                if (! $account) {
                    throw new RuntimeException("Default service mapping seeding failed. Account code [{$template['account_code']}] is not available for mapping [{$template['mapping_key']}].");
                }

                ServiceAccount::updateOrCreate(
                    ['mapping_key' => $template['mapping_key']],
                    [
                        'service_id' => $service->id,
                        'mapping_name' => $template['mapping_name'],
                        'position' => $template['position'],
                        'account_id' => $account->id,
                        'sequence_no' => $template['sequence_no'],
                        'is_dynamic' => $template['is_dynamic'],
                        'is_required' => $template['is_required'],
                        'is_active' => $template['is_active'],
                    ],
                );
            }
        }
    }
}
