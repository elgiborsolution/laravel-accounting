<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use ESolution\LaravelAccounting\Database\Seeders\Concerns\LoadsLocalizedSeederData;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use ESolution\LaravelAccounting\Models\ReportMapping;
use Illuminate\Database\Seeder;
use RuntimeException;

class ReportMappingSeeder extends Seeder
{
    use LoadsLocalizedSeederData;

    public function run(): void
    {
        $categories = AccountCategory::query()->get()->keyBy('category_code');
        $accountsByCategory = Account::query()->orderBy('code')->get()->groupBy('category_id');
        $mappings = $this->loadLocalizedSeederData('report_mappings.php');

        foreach ($mappings as $mapping) {
            $category = $categories->get($mapping['category_code'] ?? '');

            if (! $category) {
                throw new RuntimeException("Default report mapping seeding failed. Category code [{$mapping['category_code']}] is not available.");
            }

            $categoryAccounts = $accountsByCategory->get($category->id, collect())->values();

            foreach ($categoryAccounts as $index => $account) {
                ReportMapping::updateOrCreate(
                    ['account_id' => $account->id],
                    [
                        'report_type' => $mapping['report_type'],
                        'report_group' => $mapping['report_group'],
                        'report_subgroup' => $mapping['report_subgroup'] ?? null,
                        'sequence_no' => ($mapping['sequence_no'] ?? 0) + $index,
                        'is_active' => $mapping['is_active'] ?? true,
                    ],
                );
            }
        }
    }
}
