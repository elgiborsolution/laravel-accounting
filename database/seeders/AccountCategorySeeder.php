<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use ESolution\LaravelAccounting\Database\Seeders\Concerns\LoadsLocalizedSeederData;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Database\Seeder;

class AccountCategorySeeder extends Seeder
{
    use LoadsLocalizedSeederData;

    public function run(): void
    {
        $categories = $this->loadLocalizedSeederData('account_categories.php');

        foreach ($categories as $category) {
            $parentId = $category['parent_code']
                ? AccountCategory::where('category_code', $category['parent_code'])->value('id')
                : null;

            AccountCategory::updateOrCreate(
                ['category_code' => $category['category_code']],
                [
                    'parent_id' => $parentId,
                    'type' => $category['type'],
                    'category_name' => $category['category_name'],
                    'report_type' => $category['report_type'],
                    'sequence_no' => $category['sequence_no'],
                    'status' => $category['status'] ?? true,
                ]
            );
        }
    }
}
