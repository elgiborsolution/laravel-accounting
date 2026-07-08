<?php

namespace ESolution\LaravelAccounting\Database\Seeders;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;
use Illuminate\Database\Seeder;

class DefaultCoaSeeder extends Seeder
{
    public function run()
    {
        $categories = AccountCategory::all()->keyBy('category_code');

        $accounts = [
            // Current Asset > Cash & Cash Equivalent
            ['category_id' => $categories['CASH_CASH_EQUIVALENT']->id, 'code' => '1000', 'name' => 'Kas', 'is_postable' => true],
            ['category_id' => $categories['CASH_CASH_EQUIVALENT']->id, 'code' => '1001', 'name' => 'Cash / Bank', 'is_postable' => true],
            ['category_id' => $categories['CASH_CASH_EQUIVALENT']->id, 'code' => '1002', 'name' => 'Bank Transfer', 'is_postable' => true],
            ['category_id' => $categories['CASH_CASH_EQUIVALENT']->id, 'code' => '1003', 'name' => 'Petty Cash', 'is_postable' => true],
            ['category_id' => $categories['CASH_CASH_EQUIVALENT']->id, 'code' => '1010', 'name' => 'Bank BCA', 'is_postable' => true],
            ['category_id' => $categories['CASH_CASH_EQUIVALENT']->id, 'code' => '1011', 'name' => 'Bank Mandiri', 'is_postable' => true],
            ['category_id' => $categories['CASH_CASH_EQUIVALENT']->id, 'code' => '1012', 'name' => 'Bank BNI', 'is_postable' => true],
            ['category_id' => $categories['CASH_CASH_EQUIVALENT']->id, 'code' => '1020', 'name' => 'E-Wallet', 'is_postable' => true],

            // Current Asset > Account Receivable
            ['category_id' => $categories['ACCOUNT_RECEIVABLE']->id, 'code' => '1031', 'name' => 'Piutang Karyawan', 'is_postable' => true],
            ['category_id' => $categories['ACCOUNT_RECEIVABLE']->id, 'code' => '1101', 'name' => 'Accounts Receivable', 'is_postable' => true],

            // Current Asset > Inventory
            ['category_id' => $categories['INVENTORY']->id, 'code' => '1041', 'name' => 'Persediaan Konsinyasi', 'is_postable' => true],
            ['category_id' => $categories['INVENTORY']->id, 'code' => '1201', 'name' => 'Inventory', 'is_postable' => true],
            ['category_id' => $categories['INVENTORY']->id, 'code' => '1090', 'name' => 'Inventory In Transit', 'is_postable' => true],

            // Current Asset > Prepaid Expense
            ['category_id' => $categories['PREPAID_EXPENSE']->id, 'code' => '1050', 'name' => 'Uang Muka Pembelian', 'is_postable' => true],
            ['category_id' => $categories['PREPAID_EXPENSE']->id, 'code' => '1060', 'name' => 'Pajak Dibayar Dimuka', 'is_postable' => true],
            ['category_id' => $categories['PREPAID_EXPENSE']->id, 'code' => '1080', 'name' => 'Deposit', 'is_postable' => true],
            ['category_id' => $categories['PREPAID_EXPENSE']->id, 'code' => '1301', 'name' => 'Prepaid Expense', 'is_postable' => true],

            // Fixed Asset
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1200', 'name' => 'Tanah', 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1210', 'name' => 'Bangunan', 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1220', 'name' => 'Kendaraan', 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1230', 'name' => 'Peralatan Kantor', 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1240', 'name' => 'Mesin', 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1251', 'name' => 'Akumulasi Penyusutan Kendaraan', 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1252', 'name' => 'Akumulasi Penyusutan Peralatan', 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1501', 'name' => 'Fixed Asset', 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1502', 'name' => 'Accumulated Depreciation', 'is_postable' => true],

            // Other Asset
            ['category_id' => $categories['OTHER_ASSET']->id, 'code' => '1601', 'name' => 'Input VAT', 'is_postable' => true],

            // Current Liability
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2000', 'name' => 'Hutang Dagang', 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2001', 'name' => 'Accounts Payable', 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2010', 'name' => 'Hutang Pajak', 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2030', 'name' => 'Hutang Operasional', 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2040', 'name' => 'Hutang Jangka Pendek', 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2050', 'name' => 'Uang Muka Penjualan', 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2060', 'name' => 'Titipan Customer', 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2101', 'name' => 'Salary Payable', 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2201', 'name' => 'Tax Payable', 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2301', 'name' => 'Output VAT', 'is_postable' => true],

            // Long Term Liability
            ['category_id' => $categories['LONG_TERM_LIABILITY']->id, 'code' => '2200', 'name' => 'Hutang Bank', 'is_postable' => true],
            ['category_id' => $categories['LONG_TERM_LIABILITY']->id, 'code' => '2210', 'name' => 'Hutang Leasing', 'is_postable' => true],
            ['category_id' => $categories['LONG_TERM_LIABILITY']->id, 'code' => '2220', 'name' => 'Hutang Jangka Panjang', 'is_postable' => true],

            // Equity
            ['category_id' => $categories['EQUITY']->id, 'code' => '3000', 'name' => 'Modal Pemilik', 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3001', 'name' => 'Opening Balance Equity', 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3020', 'name' => 'Prive', 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3030', 'name' => 'Saldo Laba Tahun Berjalan', 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3101', 'name' => 'Retained Earnings', 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3201', 'name' => 'Revaluation Reserve', 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3301', 'name' => 'Income Summary', 'is_postable' => true],

            // Revenue > Sales Revenue
            ['category_id' => $categories['SALES_REVENUE']->id, 'code' => '4000', 'name' => 'Penjualan', 'is_postable' => true],
            ['category_id' => $categories['SALES_REVENUE']->id, 'code' => '4001', 'name' => 'Sales Revenue', 'is_postable' => true],
            ['category_id' => $categories['SALES_REVENUE']->id, 'code' => '4010', 'name' => 'Penjualan Konsinyasi', 'is_postable' => true],

            // Revenue > Service Revenue
            ['category_id' => $categories['SERVICE_REVENUE']->id, 'code' => '4020', 'name' => 'Pendapatan Jasa', 'is_postable' => true],

            // Revenue > Other Revenue
            ['category_id' => $categories['OTHER_REVENUE']->id, 'code' => '4030', 'name' => 'Pendapatan Lain-Lain', 'is_postable' => true],
            ['category_id' => $categories['OTHER_REVENUE']->id, 'code' => '4040', 'name' => 'Pendapatan Bunga', 'is_postable' => true],
            ['category_id' => $categories['OTHER_REVENUE']->id, 'code' => '4101', 'name' => 'Other Income', 'is_postable' => true],
            ['category_id' => $categories['OTHER_REVENUE']->id, 'code' => '4201', 'name' => 'Inventory Gain', 'is_postable' => true],
            ['category_id' => $categories['OTHER_REVENUE']->id, 'code' => '5601', 'name' => 'Sales Discount', 'is_postable' => true],
            ['category_id' => $categories['OTHER_REVENUE']->id, 'code' => '5701', 'name' => 'Sales Return', 'is_postable' => true],

            // Expense > Cost Of Goods Sold
            ['category_id' => $categories['COST_OF_GOODS_SOLD']->id, 'code' => '5000', 'name' => 'Harga Pokok Penjualan', 'is_postable' => true],
            ['category_id' => $categories['COST_OF_GOODS_SOLD']->id, 'code' => '5001', 'name' => 'Cost Of Goods Sold', 'is_postable' => true],

            // Expense > Operating Expense
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5101', 'name' => 'Salary Expense', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5110', 'name' => 'Beban Listrik', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5120', 'name' => 'Beban Air', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5130', 'name' => 'Beban Internet', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5140', 'name' => 'Beban Telepon', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5150', 'name' => 'Beban Sewa', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5160', 'name' => 'Beban Transportasi', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5170', 'name' => 'Beban Konsumsi', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5180', 'name' => 'Beban Perlengkapan', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5190', 'name' => 'Beban Administrasi Bank', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5201', 'name' => 'Operational Expense', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5210', 'name' => 'Beban Pajak', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5220', 'name' => 'Beban Marketing', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5230', 'name' => 'Beban Operasional Lain', 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5401', 'name' => 'Depreciation Expense', 'is_postable' => true],

            // Expense > Other Expense
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5300', 'name' => 'Beban Bunga', 'is_postable' => true],
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5301', 'name' => 'Inventory Loss', 'is_postable' => true],
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5310', 'name' => 'Kerugian Selisih Kurs', 'is_postable' => true],
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5320', 'name' => 'Kerugian Lain-Lain', 'is_postable' => true],
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5501', 'name' => 'Bad Debt Expense', 'is_postable' => true],
        ];

        foreach ($accounts as $account) {
            Account::updateOrCreate(
                ['code' => $account['code']],
                $account + ['status' => true]
            );
        }
    }
}
