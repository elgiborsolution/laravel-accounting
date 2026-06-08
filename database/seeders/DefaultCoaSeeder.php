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
            // ASSET - Current Asset
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1000', 'name' => 'Kas', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1001', 'name' => 'Kas Kecil', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1010', 'name' => 'Bank BCA', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1011', 'name' => 'Bank Mandiri', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1012', 'name' => 'Bank BNI', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1020', 'name' => 'E-Wallet', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1030', 'name' => 'Piutang Dagang', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1031', 'name' => 'Piutang Karyawan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1040', 'name' => 'Persediaan Barang', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1041', 'name' => 'Persediaan Konsinyasi', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1050', 'name' => 'Uang Muka Pembelian', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1060', 'name' => 'Pajak Dibayar Dimuka', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1070', 'name' => 'Biaya Dibayar Dimuka', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1080', 'name' => 'Deposit', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_ASSET']->id, 'code' => '1090', 'name' => 'Inventory In Transit', 'level' => 1, 'is_postable' => true],

            // ASSET - Fixed Asset
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1200', 'name' => 'Tanah', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1210', 'name' => 'Bangunan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1220', 'name' => 'Kendaraan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1230', 'name' => 'Peralatan Kantor', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1240', 'name' => 'Mesin', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1250', 'name' => 'Akumulasi Penyusutan Bangunan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1251', 'name' => 'Akumulasi Penyusutan Kendaraan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['FIXED_ASSET']->id, 'code' => '1252', 'name' => 'Akumulasi Penyusutan Peralatan', 'level' => 1, 'is_postable' => true],

            // LIABILITY - Current Liability
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2000', 'name' => 'Hutang Dagang', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2001', 'name' => 'Hutang Konsinyasi', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2010', 'name' => 'Hutang Pajak', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['CURRENT_LIABILITY']->id, 'code' => '2020', 'name' => 'Hutang Gaji', 'level' => 1, 'is_postable' => true],
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
            ['category_id' => $categories['EQUITY']->id, 'code' => '3010', 'name' => 'Laba Ditahan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3020', 'name' => 'Prive', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['EQUITY']->id, 'code' => '3030', 'name' => 'Saldo Laba Tahun Berjalan', 'level' => 1, 'is_postable' => true],

            // REVENUE
            ['category_id' => $categories['REVENUE']->id, 'code' => '4000', 'name' => 'Penjualan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4010', 'name' => 'Penjualan Konsinyasi', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4020', 'name' => 'Pendapatan Jasa', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4030', 'name' => 'Pendapatan Lain-Lain', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4040', 'name' => 'Pendapatan Bunga', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4050', 'name' => 'Diskon Penjualan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['REVENUE']->id, 'code' => '4060', 'name' => 'Retur Penjualan', 'level' => 1, 'is_postable' => true],

            // EXPENSE - Cost Of Goods Sold
            ['category_id' => $categories['COGS']->id, 'code' => '5000', 'name' => 'Harga Pokok Penjualan', 'level' => 1, 'is_postable' => true],

            // EXPENSE - Operating Expense
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5100', 'name' => 'Beban Gaji', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5110', 'name' => 'Beban Listrik', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5120', 'name' => 'Beban Air', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5130', 'name' => 'Beban Internet', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5140', 'name' => 'Beban Telepon', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5150', 'name' => 'Beban Sewa', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5160', 'name' => 'Beban Transportasi', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5170', 'name' => 'Beban Konsumsi', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5180', 'name' => 'Beban Perlengkapan', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5190', 'name' => 'Beban Administrasi Bank', 'level' => 1, 'is_postable' => true],
            ['category_id' => $categories['OPERATING_EXPENSE']->id, 'code' => '5200', 'name' => 'Beban Penyusutan', 'level' => 1, 'is_postable' => true],
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
