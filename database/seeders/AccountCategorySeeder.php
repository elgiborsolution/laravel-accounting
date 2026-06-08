<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Database\Seeder;

class AccountCategorySeeder extends Seeder
{
    public function run()
    {
        $categories = [
            [
                'type' => 'asset',
                'category_code' => 'CURRENT_ASSET',
                'category_name' => 'Current Asset',
                'report_type' => 'BS',
                'sequence_no' => 1,
            ],
            [
                'type' => 'asset',
                'category_code' => 'FIXED_ASSET',
                'category_name' => 'Fixed Asset',
                'report_type' => 'BS',
                'sequence_no' => 2,
            ],
            [
                'type' => 'liability',
                'category_code' => 'CURRENT_LIABILITY',
                'category_name' => 'Current Liability',
                'report_type' => 'BS',
                'sequence_no' => 3,
            ],
            [
                'type' => 'liability',
                'category_code' => 'LONG_TERM_LIABILITY',
                'category_name' => 'Long Term Liability',
                'report_type' => 'BS',
                'sequence_no' => 4,
            ],
            [
                'type' => 'equity',
                'category_code' => 'EQUITY',
                'category_name' => 'Equity',
                'report_type' => 'BS',
                'sequence_no' => 5,
            ],
            [
                'type' => 'revenue',
                'category_code' => 'REVENUE',
                'category_name' => 'Revenue',
                'report_type' => 'PL',
                'sequence_no' => 6,
            ],
            [
                'type' => 'expense',
                'category_code' => 'COGS',
                'category_name' => 'Cost Of Goods Sold',
                'report_type' => 'PL',
                'sequence_no' => 7,
            ],
            [
                'type' => 'expense',
                'category_code' => 'OPERATING_EXPENSE',
                'category_name' => 'Operating Expense',
                'report_type' => 'PL',
                'sequence_no' => 8,
            ],
            [
                'type' => 'expense',
                'category_code' => 'OTHER_EXPENSE',
                'category_name' => 'Other Expense',
                'report_type' => 'PL',
                'sequence_no' => 9,
            ],
        ];

        foreach ($categories as $category) {
            AccountCategory::updateOrCreate(
                ['category_code' => $category['category_code']],
                $category
            );
        }
    }
}
