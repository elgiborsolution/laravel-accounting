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
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1001', 'name' => 'Cash', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1002', 'name' => 'Bank', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1003', 'name' => 'Petty Cash', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1101', 'name' => 'Accounts Receivable', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1201', 'name' => 'Inventory', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1301', 'name' => 'Prepaid Expense', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1501', 'name' => 'Fixed Asset', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1502', 'name' => 'Accumulated Depreciation', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1601', 'name' => 'Input VAT', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2001', 'name' => 'Accounts Payable', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2101', 'name' => 'Salary Payable', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2201', 'name' => 'Tax Payable', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2301', 'name' => 'Output VAT', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3001', 'name' => 'Opening Balance Equity', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3101', 'name' => 'Retained Earnings', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3201', 'name' => 'Revaluation Reserve', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3301', 'name' => 'Income Summary', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4001', 'name' => 'Sales Revenue', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4101', 'name' => 'Other Income', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4201', 'name' => 'Inventory Gain', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['COGS']->id, 'code' => '5001', 'name' => 'Cost Of Goods Sold', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5101', 'name' => 'Salary Expense', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5201', 'name' => 'Operational Expense', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5301', 'name' => 'Inventory Loss', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5401', 'name' => 'Depreciation Expense', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5501', 'name' => 'Bad Debt Expense', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '5601', 'name' => 'Sales Discount', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '5701', 'name' => 'Sales Return', 'level' => 1, 'is_postable' => true],

            // ASSET - Current Asset
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1000', 'name' => 'Kas', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1010', 'name' => 'Bank BCA', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1011', 'name' => 'Bank Mandiri', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1012', 'name' => 'Bank BNI', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1020', 'name' => 'E-Wallet', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1031', 'name' => 'Piutang Karyawan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1041', 'name' => 'Persediaan Konsinyasi', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1050', 'name' => 'Uang Muka Pembelian', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1060', 'name' => 'Pajak Dibayar Dimuka', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1080', 'name' => 'Deposit', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1090', 'name' => 'Inventory In Transit', 'level' => 1, 'is_postable' => true],

            // ASSET - Fixed Asset
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1200', 'name' => 'Tanah', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1210', 'name' => 'Bangunan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1220', 'name' => 'Kendaraan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1230', 'name' => 'Peralatan Kantor', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1240', 'name' => 'Mesin', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1251', 'name' => 'Akumulasi Penyusutan Kendaraan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1252', 'name' => 'Akumulasi Penyusutan Peralatan', 'level' => 1, 'is_postable' => true],

            // LIABILITY - Current Liability
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2000', 'name' => 'Hutang Dagang', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2010', 'name' => 'Hutang Pajak', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2030', 'name' => 'Hutang Operasional', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2040', 'name' => 'Hutang Jangka Pendek', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2050', 'name' => 'Uang Muka Penjualan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2060', 'name' => 'Titipan Customer', 'level' => 1, 'is_postable' => true],

            // LIABILITY - Long Term Liability
            ['category_id' => $categories['LONG_TERM_LIABILITY']->id, 'code' => '2200', 'name' => 'Hutang Bank', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['LONG_TERM_LIABILITY']->id, 'code' => '2210', 'name' => 'Hutang Leasing', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['LONG_TERM_LIABILITY']->id, 'code' => '2220', 'name' => 'Hutang Jangka Panjang', 'level' => 1, 'is_postable' => true],

            // EQUITY
            ['category_id' => $categories['EQUITY']->id, 'code' => '3000', 'name' => 'Modal Pemilik', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3020', 'name' => 'Prive', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3030', 'name' => 'Saldo Laba Tahun Berjalan', 'level' => 1, 'is_postable' => true],

            // REVENUE
            ['category_id' => $categories['REVENUE']->id, 'code' => '4000', 'name' => 'Penjualan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4010', 'name' => 'Penjualan Konsinyasi', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4020', 'name' => 'Pendapatan Jasa', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4030', 'name' => 'Pendapatan Lain-Lain', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4040', 'name' => 'Pendapatan Bunga', 'level' => 1, 'is_postable' => true],

            // EXPENSE - Cost Of Goods Sold
            ['category_id' => $categories['COGS']->id, 'code' => '5000', 'name' => 'Harga Pokok Penjualan', 'level' => 1, 'is_postable' => true],

            // EXPENSE - Operating Expense
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5110', 'name' => 'Beban Listrik', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5120', 'name' => 'Beban Air', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5130', 'name' => 'Beban Internet', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5140', 'name' => 'Beban Telepon', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5150', 'name' => 'Beban Sewa', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5160', 'name' => 'Beban Transportasi', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5170', 'name' => 'Beban Konsumsi', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5180', 'name' => 'Beban Perlengkapan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5190', 'name' => 'Beban Administrasi Bank', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5210', 'name' => 'Beban Pajak', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5220', 'name' => 'Beban Marketing', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5230', 'name' => 'Beban Operasional Lain', 'level' => 1, 'is_postable' => true],

            // EXPENSE - Other Expense
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5300', 'name' => 'Beban Bunga', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5310', 'name' => 'Kerugian Selisih Kurs', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OTHER_EXPENSE']->id, 'code' => '5320', 'name' => 'Kerugian Lain-Lain', 'level' => 1, 'is_postable' => true],
        ];

        foreach ($accounts as $account) {
            Account::updateOrCreate(
                ['code' => $account['code']],
                $account
            );
        }
    }
}
