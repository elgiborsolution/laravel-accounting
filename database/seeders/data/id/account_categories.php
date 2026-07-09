<?php

return [
    ['type' => 'ASSET', 'category_code' => 'ASSET', 'category_name' => 'Aset', 'report_type' => 'BS', 'sequence_no' => 10, 'parent_code' => null],
    ['type' => 'LIABILITY', 'category_code' => 'LIABILITY', 'category_name' => 'Kewajiban', 'report_type' => 'BS', 'sequence_no' => 20, 'parent_code' => null],
    ['type' => 'EQUITY', 'category_code' => 'EQUITY', 'category_name' => 'Ekuitas', 'report_type' => 'BS', 'sequence_no' => 30, 'parent_code' => null],
    ['type' => 'REVENUE', 'category_code' => 'REVENUE', 'category_name' => 'Pendapatan', 'report_type' => 'PL', 'sequence_no' => 40, 'parent_code' => null],
    ['type' => 'EXPENSE', 'category_code' => 'EXPENSE', 'category_name' => 'Beban', 'report_type' => 'PL', 'sequence_no' => 50, 'parent_code' => null],

    ['type' => 'ASSET', 'category_code' => 'CURRENT_ASSET', 'category_name' => 'Aset Lancar', 'report_type' => 'BS', 'sequence_no' => 10, 'parent_code' => 'ASSET'],
    ['type' => 'ASSET', 'category_code' => 'FIXED_ASSET', 'category_name' => 'Aset Tetap', 'report_type' => 'BS', 'sequence_no' => 20, 'parent_code' => 'ASSET'],
    ['type' => 'ASSET', 'category_code' => 'OTHER_ASSET', 'category_name' => 'Aset Lainnya', 'report_type' => 'BS', 'sequence_no' => 30, 'parent_code' => 'ASSET'],

    ['type' => 'LIABILITY', 'category_code' => 'CURRENT_LIABILITY', 'category_name' => 'Kewajiban Lancar', 'report_type' => 'BS', 'sequence_no' => 10, 'parent_code' => 'LIABILITY'],
    ['type' => 'LIABILITY', 'category_code' => 'LONG_TERM_LIABILITY', 'category_name' => 'Kewajiban Jangka Panjang', 'report_type' => 'BS', 'sequence_no' => 20, 'parent_code' => 'LIABILITY'],

    ['type' => 'REVENUE', 'category_code' => 'SALES_REVENUE', 'category_name' => 'Pendapatan Penjualan', 'report_type' => 'PL', 'sequence_no' => 10, 'parent_code' => 'REVENUE'],
    ['type' => 'REVENUE', 'category_code' => 'SERVICE_REVENUE', 'category_name' => 'Pendapatan Jasa', 'report_type' => 'PL', 'sequence_no' => 20, 'parent_code' => 'REVENUE'],
    ['type' => 'REVENUE', 'category_code' => 'OTHER_REVENUE', 'category_name' => 'Pendapatan Lainnya', 'report_type' => 'PL', 'sequence_no' => 30, 'parent_code' => 'REVENUE'],

    ['type' => 'EXPENSE', 'category_code' => 'COST_OF_GOODS_SOLD', 'category_name' => 'Harga Pokok Penjualan', 'report_type' => 'PL', 'sequence_no' => 10, 'parent_code' => 'EXPENSE'],
    ['type' => 'EXPENSE', 'category_code' => 'OPERATING_EXPENSE', 'category_name' => 'Beban Operasional', 'report_type' => 'PL', 'sequence_no' => 20, 'parent_code' => 'EXPENSE'],
    ['type' => 'EXPENSE', 'category_code' => 'OTHER_EXPENSE', 'category_name' => 'Beban Lainnya', 'report_type' => 'PL', 'sequence_no' => 30, 'parent_code' => 'EXPENSE'],

    ['type' => 'ASSET', 'category_code' => 'CASH_CASH_EQUIVALENT', 'category_name' => 'Kas dan Setara Kas', 'report_type' => 'BS', 'sequence_no' => 10, 'parent_code' => 'CURRENT_ASSET'],
    ['type' => 'ASSET', 'category_code' => 'ACCOUNT_RECEIVABLE', 'category_name' => 'Piutang Usaha', 'report_type' => 'BS', 'sequence_no' => 20, 'parent_code' => 'CURRENT_ASSET'],
    ['type' => 'ASSET', 'category_code' => 'INVENTORY', 'category_name' => 'Persediaan', 'report_type' => 'BS', 'sequence_no' => 30, 'parent_code' => 'CURRENT_ASSET'],
    ['type' => 'ASSET', 'category_code' => 'PREPAID_EXPENSE', 'category_name' => 'Beban Dibayar Dimuka', 'report_type' => 'BS', 'sequence_no' => 40, 'parent_code' => 'CURRENT_ASSET'],
];
