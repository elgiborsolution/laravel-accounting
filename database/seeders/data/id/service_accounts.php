<?php

return [
    ['service_code' => 'SALES_CASH', 'mapping_key' => 'sales_cash_cash_d', 'mapping_name' => 'Penjualan Tunai - Kas/Bank', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH', 'mapping_key' => 'sales_cash_sales_k', 'mapping_name' => 'Penjualan Tunai - Pendapatan Penjualan', 'position' => 'K', 'account_code' => '4001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH', 'mapping_key' => 'sales_cash_cogs_d', 'mapping_name' => 'Penjualan Tunai - Harga Pokok Penjualan', 'position' => 'D', 'account_code' => '5001', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH', 'mapping_key' => 'sales_cash_inventory_k', 'mapping_name' => 'Penjualan Tunai - Persediaan', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_cash_d', 'mapping_name' => 'Penjualan Tunai PPN 11% - Kas/Bank', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_sales_k', 'mapping_name' => 'Penjualan Tunai PPN 11% - Penjualan', 'position' => 'K', 'account_code' => '4001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_vat_k', 'mapping_name' => 'Penjualan Tunai PPN 11% - Hutang PPN Keluaran', 'position' => 'K', 'account_code' => '2301', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_cogs_d', 'mapping_name' => 'Penjualan Tunai PPN 11% - Harga Pokok Penjualan', 'position' => 'D', 'account_code' => '5001', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_inventory_k', 'mapping_name' => 'Penjualan Tunai PPN 11% - Persediaan', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 5, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_CREDIT', 'mapping_key' => 'sales_credit_ar_d', 'mapping_name' => 'Penjualan Kredit - Piutang Usaha', 'position' => 'D', 'account_code' => '1101', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT', 'mapping_key' => 'sales_credit_sales_k', 'mapping_name' => 'Penjualan Kredit - Pendapatan Penjualan', 'position' => 'K', 'account_code' => '4001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT', 'mapping_key' => 'sales_credit_cogs_d', 'mapping_name' => 'Penjualan Kredit - Harga Pokok Penjualan', 'position' => 'D', 'account_code' => '5001', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT', 'mapping_key' => 'sales_credit_inventory_k', 'mapping_name' => 'Penjualan Kredit - Persediaan', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_ar_d', 'mapping_name' => 'Penjualan Kredit PPN 11% - Piutang Usaha', 'position' => 'D', 'account_code' => '1101', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_sales_k', 'mapping_name' => 'Penjualan Kredit PPN 11% - Penjualan', 'position' => 'K', 'account_code' => '4001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_vat_k', 'mapping_name' => 'Penjualan Kredit PPN 11% - Hutang PPN Keluaran', 'position' => 'K', 'account_code' => '2301', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_cogs_d', 'mapping_name' => 'Penjualan Kredit PPN 11% - Harga Pokok Penjualan', 'position' => 'D', 'account_code' => '5001', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_inventory_k', 'mapping_name' => 'Penjualan Kredit PPN 11% - Persediaan', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 5, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_RETURN', 'mapping_key' => 'sales_return_sales_return_d', 'mapping_name' => 'Retur Penjualan - Retur Penjualan', 'position' => 'D', 'account_code' => '5701', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_RETURN', 'mapping_key' => 'sales_return_receivable_k', 'mapping_name' => 'Retur Penjualan - Piutang/Kas', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_RETURN', 'mapping_key' => 'sales_return_inventory_d', 'mapping_name' => 'Retur Penjualan - Persediaan', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_RETURN', 'mapping_key' => 'sales_return_cogs_k', 'mapping_name' => 'Retur Penjualan - Harga Pokok Penjualan', 'position' => 'K', 'account_code' => '5001', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_DISCOUNT', 'mapping_key' => 'sales_discount_discount_d', 'mapping_name' => 'Diskon Penjualan - Diskon Penjualan', 'position' => 'D', 'account_code' => '5601', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_DISCOUNT', 'mapping_key' => 'sales_discount_receivable_k', 'mapping_name' => 'Diskon Penjualan - Piutang/Kas', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_WRITE_OFF', 'mapping_key' => 'sales_writeoff_bad_debt_d', 'mapping_name' => 'Penghapusan Penjualan - Beban Piutang Tak Tertagih', 'position' => 'D', 'account_code' => '5501', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_WRITE_OFF', 'mapping_key' => 'sales_writeoff_ar_k', 'mapping_name' => 'Penghapusan Penjualan - Piutang Usaha', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PURCHASE_CASH', 'mapping_key' => 'purchase_cash_inventory_d', 'mapping_name' => 'Pembelian Tunai - Persediaan', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PURCHASE_CASH', 'mapping_key' => 'purchase_cash_cash_k', 'mapping_name' => 'Pembelian Tunai - Kas/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PURCHASE_CREDIT', 'mapping_key' => 'purchase_credit_inventory_d', 'mapping_name' => 'Pembelian Kredit - Persediaan', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PURCHASE_CREDIT', 'mapping_key' => 'purchase_credit_ap_k', 'mapping_name' => 'Pembelian Kredit - Hutang Usaha', 'position' => 'K', 'account_code' => '2001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PURCHASE_RETURN', 'mapping_key' => 'purchase_return_ap_d', 'mapping_name' => 'Retur Pembelian - Hutang Usaha', 'position' => 'D', 'account_code' => '2001', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PURCHASE_RETURN', 'mapping_key' => 'purchase_return_inventory_k', 'mapping_name' => 'Retur Pembelian - Persediaan', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_OPENING', 'mapping_key' => 'stock_opening_inventory_d', 'mapping_name' => 'Saldo Awal Persediaan - Persediaan', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_OPENING', 'mapping_key' => 'stock_opening_opening_balance_k', 'mapping_name' => 'Saldo Awal Persediaan - Ekuitas Saldo Awal', 'position' => 'K', 'account_code' => '3001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_ADJUSTMENT_PLUS', 'mapping_key' => 'stock_adjustment_plus_inventory_d', 'mapping_name' => 'Penyesuaian Persediaan Plus - Persediaan', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_ADJUSTMENT_PLUS', 'mapping_key' => 'stock_adjustment_plus_gain_k', 'mapping_name' => 'Penyesuaian Persediaan Plus - Keuntungan Persediaan', 'position' => 'K', 'account_code' => '4201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_ADJUSTMENT_MINUS', 'mapping_key' => 'stock_adjustment_minus_loss_d', 'mapping_name' => 'Penyesuaian Persediaan Minus - Kerugian Persediaan', 'position' => 'D', 'account_code' => '5301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_ADJUSTMENT_MINUS', 'mapping_key' => 'stock_adjustment_minus_inventory_k', 'mapping_name' => 'Penyesuaian Persediaan Minus - Persediaan', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_OPNAME_GAIN', 'mapping_key' => 'stock_opname_gain_inventory_d', 'mapping_name' => 'Selisih Persediaan Lebih - Persediaan', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_OPNAME_GAIN', 'mapping_key' => 'stock_opname_gain_gain_k', 'mapping_name' => 'Selisih Persediaan Lebih - Keuntungan Persediaan', 'position' => 'K', 'account_code' => '4201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_OPNAME_LOSS', 'mapping_key' => 'stock_opname_loss_loss_d', 'mapping_name' => 'Selisih Persediaan Kurang - Kerugian Persediaan', 'position' => 'D', 'account_code' => '5301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_OPNAME_LOSS', 'mapping_key' => 'stock_opname_loss_inventory_k', 'mapping_name' => 'Selisih Persediaan Kurang - Persediaan', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'CASH_IN', 'mapping_key' => 'cash_in_cash_d', 'mapping_name' => 'Penerimaan Kas - Kas/Bank', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'CASH_IN', 'mapping_key' => 'cash_in_other_income_k', 'mapping_name' => 'Penerimaan Kas - Pendapatan Lain', 'position' => 'K', 'account_code' => '4101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'CASH_OUT', 'mapping_key' => 'cash_out_expense_d', 'mapping_name' => 'Pengeluaran Kas - Beban', 'position' => 'D', 'account_code' => '5201', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'CASH_OUT', 'mapping_key' => 'cash_out_cash_k', 'mapping_name' => 'Pengeluaran Kas - Kas/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'BANK_TRANSFER', 'mapping_key' => 'bank_transfer_destination_bank_d', 'mapping_name' => 'Transfer Bank - Bank Tujuan', 'position' => 'D', 'account_code' => '1002', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'BANK_TRANSFER', 'mapping_key' => 'bank_transfer_source_bank_k', 'mapping_name' => 'Transfer Bank - Bank Sumber', 'position' => 'K', 'account_code' => '1002', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'JOURNAL_MANUAL', 'mapping_key' => 'manual_journal', 'mapping_name' => 'Jurnal Manual', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => false, 'is_active' => true],

    ['service_code' => 'PETTY_CASH', 'mapping_key' => 'petty_cash_fund_d', 'mapping_name' => 'Kas Kecil - Kas Kecil', 'position' => 'D', 'account_code' => '1003', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PETTY_CASH', 'mapping_key' => 'petty_cash_cash_k', 'mapping_name' => 'Kas Kecil - Kas/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'EXPENSE', 'mapping_key' => 'expense_expense_d', 'mapping_name' => 'Beban - Akun Beban', 'position' => 'D', 'account_code' => '5201', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'EXPENSE', 'mapping_key' => 'expense_cash_k', 'mapping_name' => 'Beban - Kas/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PREPAID_EXPENSE', 'mapping_key' => 'prepaid_expense_asset_d', 'mapping_name' => 'Beban Dibayar Dimuka - Beban Dibayar Dimuka', 'position' => 'D', 'account_code' => '1301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PREPAID_EXPENSE', 'mapping_key' => 'prepaid_expense_cash_k', 'mapping_name' => 'Beban Dibayar Dimuka - Kas/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PREPAID_EXPENSE', 'mapping_key' => 'prepaid_expense_amortization_d', 'mapping_name' => 'Beban Dibayar Dimuka - Beban', 'position' => 'D', 'account_code' => '5201', 'sequence_no' => 3, 'is_dynamic' => true, 'is_required' => false, 'is_active' => true],
    ['service_code' => 'PREPAID_EXPENSE', 'mapping_key' => 'prepaid_expense_asset_k', 'mapping_name' => 'Beban Dibayar Dimuka - Reklas Beban Dibayar Dimuka', 'position' => 'K', 'account_code' => '1301', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => false, 'is_active' => true],

    ['service_code' => 'PAYROLL', 'mapping_key' => 'payroll_salary_expense_d', 'mapping_name' => 'Pembayaran Gaji - Beban Gaji', 'position' => 'D', 'account_code' => '5101', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PAYROLL', 'mapping_key' => 'payroll_cash_k', 'mapping_name' => 'Pembayaran Gaji - Kas/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PAYROLL_ACCRUAL', 'mapping_key' => 'payroll_accrual_expense_d', 'mapping_name' => 'Akrual Gaji - Beban Gaji', 'position' => 'D', 'account_code' => '5101', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PAYROLL_ACCRUAL', 'mapping_key' => 'payroll_accrual_payable_k', 'mapping_name' => 'Akrual Gaji - Gaji Terutang', 'position' => 'K', 'account_code' => '2101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'ASSET_PURCHASE', 'mapping_key' => 'asset_purchase_asset_d', 'mapping_name' => 'Pembelian Aset - Akun Aset', 'position' => 'D', 'account_code' => '1501', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'ASSET_PURCHASE', 'mapping_key' => 'asset_purchase_cash_k', 'mapping_name' => 'Pembelian Aset - Kas/Bank/Utang', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'ASSET_DEPRECIATION', 'mapping_key' => 'asset_depreciation_expense_d', 'mapping_name' => 'Penyusutan Aset - Beban Penyusutan', 'position' => 'D', 'account_code' => '5401', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'ASSET_DEPRECIATION', 'mapping_key' => 'asset_depreciation_accumulated_k', 'mapping_name' => 'Penyusutan Aset - Akumulasi Penyusutan', 'position' => 'K', 'account_code' => '1502', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'ASSET_DISPOSAL', 'mapping_key' => 'asset_disposal_accumulated_d', 'mapping_name' => 'Pelepasan Aset - Akumulasi Penyusutan', 'position' => 'D', 'account_code' => '1502', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'ASSET_DISPOSAL', 'mapping_key' => 'asset_disposal_asset_k', 'mapping_name' => 'Pelepasan Aset - Aset Tetap', 'position' => 'K', 'account_code' => '1501', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'ASSET_REVALUATION', 'mapping_key' => 'asset_revaluation_asset_d', 'mapping_name' => 'Revaluasi Aset - Aset Tetap', 'position' => 'D', 'account_code' => '1501', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'ASSET_REVALUATION', 'mapping_key' => 'asset_revaluation_reserve_k', 'mapping_name' => 'Revaluasi Aset - Cadangan Revaluasi', 'position' => 'K', 'account_code' => '3201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'CUSTOMER_RECEIVABLE_PAYMENT', 'mapping_key' => 'receivable_payment_cash_d', 'mapping_name' => 'Pembayaran Piutang - Kas/Bank', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'CUSTOMER_RECEIVABLE_PAYMENT', 'mapping_key' => 'receivable_payment_ar_k', 'mapping_name' => 'Pembayaran Piutang - Piutang Usaha', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'CUSTOMER_RECEIVABLE_WRITE_OFF', 'mapping_key' => 'receivable_writeoff_bad_debt_d', 'mapping_name' => 'Penghapusan Piutang - Beban Piutang Tak Tertagih', 'position' => 'D', 'account_code' => '5501', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'CUSTOMER_RECEIVABLE_WRITE_OFF', 'mapping_key' => 'receivable_writeoff_ar_k', 'mapping_name' => 'Penghapusan Piutang - Piutang Usaha', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'VENDOR_PAYMENT', 'mapping_key' => 'vendor_payment_ap_d', 'mapping_name' => 'Pembayaran Vendor - Hutang Usaha', 'position' => 'D', 'account_code' => '2001', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'VENDOR_PAYMENT', 'mapping_key' => 'vendor_payment_cash_k', 'mapping_name' => 'Pembayaran Vendor - Kas/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'VENDOR_PAYABLE_WRITE_OFF', 'mapping_key' => 'vendor_writeoff_ap_d', 'mapping_name' => 'Penghapusan Hutang Vendor - Hutang Usaha', 'position' => 'D', 'account_code' => '2001', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'VENDOR_PAYABLE_WRITE_OFF', 'mapping_key' => 'vendor_writeoff_income_k', 'mapping_name' => 'Penghapusan Hutang Vendor - Pendapatan Lain', 'position' => 'K', 'account_code' => '4101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'TAX_OUTPUT', 'mapping_key' => 'tax_output_receivable_d', 'mapping_name' => 'Pajak Keluaran - Kas/Piutang', 'position' => 'D', 'account_code' => '1101', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'TAX_OUTPUT', 'mapping_key' => 'tax_output_vat_k', 'mapping_name' => 'Pajak Keluaran - PPN Keluaran', 'position' => 'K', 'account_code' => '2301', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'TAX_INPUT', 'mapping_key' => 'tax_input_vat_d', 'mapping_name' => 'Pajak Masukan - PPN Masukan', 'position' => 'D', 'account_code' => '1601', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'TAX_INPUT', 'mapping_key' => 'tax_input_payable_k', 'mapping_name' => 'Pajak Masukan - Kas/Hutang', 'position' => 'K', 'account_code' => '2001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'VAT_PAYMENT', 'mapping_key' => 'vat_payment_vat_d', 'mapping_name' => 'Pembayaran PPN - Hutang PPN Keluaran', 'position' => 'D', 'account_code' => '2301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'VAT_PAYMENT', 'mapping_key' => 'vat_payment_cash_k', 'mapping_name' => 'Pembayaran PPN - Kas/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'TAX_PAYMENT', 'mapping_key' => 'tax_payment_payable_d', 'mapping_name' => 'Pembayaran Pajak - Utang Pajak', 'position' => 'D', 'account_code' => '2201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'TAX_PAYMENT', 'mapping_key' => 'tax_payment_cash_k', 'mapping_name' => 'Pembayaran Pajak - Kas/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'MONTH_END_CLOSING', 'mapping_key' => 'month_closing_revenue_d', 'mapping_name' => 'Tutup Buku Bulan - Akun Pendapatan', 'position' => 'D', 'account_code' => '4001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'MONTH_END_CLOSING', 'mapping_key' => 'month_closing_income_summary_k', 'mapping_name' => 'Tutup Buku Bulan - Ikhtisar Laba Rugi', 'position' => 'K', 'account_code' => '3301', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'MONTH_END_CLOSING', 'mapping_key' => 'month_closing_income_summary_d', 'mapping_name' => 'Tutup Buku Bulan - Ikhtisar Laba Rugi', 'position' => 'D', 'account_code' => '3301', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'MONTH_END_CLOSING', 'mapping_key' => 'month_closing_expense_k', 'mapping_name' => 'Tutup Buku Bulan - Akun Beban', 'position' => 'K', 'account_code' => '5201', 'sequence_no' => 4, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'YEAR_END_CLOSING', 'mapping_key' => 'year_closing_income_summary_d', 'mapping_name' => 'Tutup Buku Tahun - Ikhtisar Laba Rugi', 'position' => 'D', 'account_code' => '3301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'YEAR_END_CLOSING', 'mapping_key' => 'year_closing_retained_earnings_k', 'mapping_name' => 'Tutup Buku Tahun - Laba Ditahan', 'position' => 'K', 'account_code' => '3101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
];
