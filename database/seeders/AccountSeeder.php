<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use ESolution\LaravelAccounting\Database\Seeders\Concerns\LoadsLocalizedSeederData;
use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Database\Seeder;
use RuntimeException;

class AccountSeeder extends Seeder
{
    use LoadsLocalizedSeederData;

    public function run(): void
    {
        $categories = AccountCategory::query()->get()->keyBy('category_code');
        $accounts = $this->loadLocalizedSeederData('accounts.php');

        foreach ($accounts as $account) {
            $category = $categories->get($account['category_code'] ?? '');

            if (! $category) {
                throw new RuntimeException("Default accounting seeding failed. Category code [{$account['category_code']}] is not available.");
            }

            Account::updateOrCreate(
                ['code' => $account['code']],
                [
                    'category_id' => $category->id,
                    'name' => $account['name'],
                    'is_postable' => $account['is_postable'] ?? true,
                    'status' => $account['status'] ?? true,
                ],
            );
        }
    }
}
