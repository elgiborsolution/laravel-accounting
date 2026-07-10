<?php

return [
    ['service_code' => 'SALES_CASH', 'mapping_key' => 'sales_cash_cash_d', 'mapping_name' => 'Cash Sales - Cash/Bank', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH', 'mapping_key' => 'sales_cash_sales_k', 'mapping_name' => 'Cash Sales - Sales Revenue', 'position' => 'K', 'account_code' => '4001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH', 'mapping_key' => 'sales_cash_cogs_d', 'mapping_name' => 'Cash Sales - Cost of Goods Sold', 'position' => 'D', 'account_code' => '5001', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH', 'mapping_key' => 'sales_cash_inventory_k', 'mapping_name' => 'Cash Sales - Inventory', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_cash_d', 'mapping_name' => 'Cash Sales with VAT 11% - Cash/Bank', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_sales_k', 'mapping_name' => 'Cash Sales with VAT 11% - Sales Revenue', 'position' => 'K', 'account_code' => '4001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_vat_k', 'mapping_name' => 'Cash Sales with VAT 11% - Output VAT', 'position' => 'K', 'account_code' => '2301', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_cogs_d', 'mapping_name' => 'Cash Sales with VAT 11% - Cost Of Goods Sold', 'position' => 'D', 'account_code' => '5001', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CASH_VAT', 'mapping_key' => 'sales_cash_vat_inventory_k', 'mapping_name' => 'Cash Sales with VAT 11% - Inventory', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 5, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_CREDIT', 'mapping_key' => 'sales_credit_ar_d', 'mapping_name' => 'Credit Sales - Accounts Receivable', 'position' => 'D', 'account_code' => '1101', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT', 'mapping_key' => 'sales_credit_sales_k', 'mapping_name' => 'Credit Sales - Sales Revenue', 'position' => 'K', 'account_code' => '4001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT', 'mapping_key' => 'sales_credit_cogs_d', 'mapping_name' => 'Credit Sales - Cost of Goods Sold', 'position' => 'D', 'account_code' => '5001', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT', 'mapping_key' => 'sales_credit_inventory_k', 'mapping_name' => 'Credit Sales - Inventory', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_ar_d', 'mapping_name' => 'Credit Sales with VAT 11% - Accounts Receivable', 'position' => 'D', 'account_code' => '1101', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_sales_k', 'mapping_name' => 'Credit Sales with VAT 11% - Sales Revenue', 'position' => 'K', 'account_code' => '4001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_vat_k', 'mapping_name' => 'Credit Sales with VAT 11% - Output VAT', 'position' => 'K', 'account_code' => '2301', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_cogs_d', 'mapping_name' => 'Credit Sales with VAT 11% - Cost Of Goods Sold', 'position' => 'D', 'account_code' => '5001', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_CREDIT_VAT', 'mapping_key' => 'sales_credit_vat_inventory_k', 'mapping_name' => 'Credit Sales with VAT 11% - Inventory', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 5, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_RETURN', 'mapping_key' => 'sales_return_sales_return_d', 'mapping_name' => 'Sales Return - Sales Return', 'position' => 'D', 'account_code' => '5701', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_RETURN', 'mapping_key' => 'sales_return_receivable_k', 'mapping_name' => 'Sales Return - Receivable/Cash', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_RETURN', 'mapping_key' => 'sales_return_inventory_d', 'mapping_name' => 'Sales Return - Inventory', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_RETURN', 'mapping_key' => 'sales_return_cogs_k', 'mapping_name' => 'Sales Return - Cost of Goods Sold', 'position' => 'K', 'account_code' => '5001', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_DISCOUNT', 'mapping_key' => 'sales_discount_discount_d', 'mapping_name' => 'Sales Discount - Sales Discount', 'position' => 'D', 'account_code' => '5601', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_DISCOUNT', 'mapping_key' => 'sales_discount_receivable_k', 'mapping_name' => 'Sales Discount - Receivable/Cash', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'SALES_WRITE_OFF', 'mapping_key' => 'sales_writeoff_bad_debt_d', 'mapping_name' => 'Sales Write Off - Bad Debt Expense', 'position' => 'D', 'account_code' => '5501', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'SALES_WRITE_OFF', 'mapping_key' => 'sales_writeoff_ar_k', 'mapping_name' => 'Sales Write Off - Accounts Receivable', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PURCHASE_CASH', 'mapping_key' => 'purchase_cash_inventory_d', 'mapping_name' => 'Cash Purchase - Inventory', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PURCHASE_CASH', 'mapping_key' => 'purchase_cash_cash_k', 'mapping_name' => 'Cash Purchase - Cash/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PURCHASE_CREDIT', 'mapping_key' => 'purchase_credit_inventory_d', 'mapping_name' => 'Credit Purchase - Inventory', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PURCHASE_CREDIT', 'mapping_key' => 'purchase_credit_ap_k', 'mapping_name' => 'Credit Purchase - Accounts Payable', 'position' => 'K', 'account_code' => '2001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PURCHASE_RETURN', 'mapping_key' => 'purchase_return_ap_d', 'mapping_name' => 'Purchase Return - Accounts Payable', 'position' => 'D', 'account_code' => '2001', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PURCHASE_RETURN', 'mapping_key' => 'purchase_return_inventory_k', 'mapping_name' => 'Purchase Return - Inventory', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_OPENING', 'mapping_key' => 'stock_opening_inventory_d', 'mapping_name' => 'Stock Opening - Inventory', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_OPENING', 'mapping_key' => 'stock_opening_opening_balance_k', 'mapping_name' => 'Stock Opening - Opening Balance Equity', 'position' => 'K', 'account_code' => '3001', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_ADJUSTMENT_PLUS', 'mapping_key' => 'stock_adjustment_plus_inventory_d', 'mapping_name' => 'Stock Adjustment Plus - Inventory', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_ADJUSTMENT_PLUS', 'mapping_key' => 'stock_adjustment_plus_gain_k', 'mapping_name' => 'Stock Adjustment Plus - Inventory Gain', 'position' => 'K', 'account_code' => '4201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_ADJUSTMENT_MINUS', 'mapping_key' => 'stock_adjustment_minus_loss_d', 'mapping_name' => 'Stock Adjustment Minus - Inventory Loss', 'position' => 'D', 'account_code' => '5301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_ADJUSTMENT_MINUS', 'mapping_key' => 'stock_adjustment_minus_inventory_k', 'mapping_name' => 'Stock Adjustment Minus - Inventory', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_OPNAME_GAIN', 'mapping_key' => 'stock_opname_gain_inventory_d', 'mapping_name' => 'Stock Opname Gain - Inventory', 'position' => 'D', 'account_code' => '1201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_OPNAME_GAIN', 'mapping_key' => 'stock_opname_gain_gain_k', 'mapping_name' => 'Stock Opname Gain - Inventory Gain', 'position' => 'K', 'account_code' => '4201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'STOCK_OPNAME_LOSS', 'mapping_key' => 'stock_opname_loss_loss_d', 'mapping_name' => 'Stock Opname Loss - Inventory Loss', 'position' => 'D', 'account_code' => '5301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'STOCK_OPNAME_LOSS', 'mapping_key' => 'stock_opname_loss_inventory_k', 'mapping_name' => 'Stock Opname Loss - Inventory', 'position' => 'K', 'account_code' => '1201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'CASH_IN', 'mapping_key' => 'cash_in_cash_d', 'mapping_name' => 'Cash In - Cash/Bank', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'CASH_IN', 'mapping_key' => 'cash_in_other_income_k', 'mapping_name' => 'Cash In - Other Income', 'position' => 'K', 'account_code' => '4101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'CASH_OUT', 'mapping_key' => 'cash_out_expense_d', 'mapping_name' => 'Cash Out - Expense Account', 'position' => 'D', 'account_code' => '5201', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'CASH_OUT', 'mapping_key' => 'cash_out_cash_k', 'mapping_name' => 'Cash Out - Cash/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'BANK_TRANSFER', 'mapping_key' => 'bank_transfer_destination_bank_d', 'mapping_name' => 'Bank Transfer - Destination Bank', 'position' => 'D', 'account_code' => '1002', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'BANK_TRANSFER', 'mapping_key' => 'bank_transfer_source_bank_k', 'mapping_name' => 'Bank Transfer - Source Bank', 'position' => 'K', 'account_code' => '1002', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'JOURNAL_MANUAL', 'mapping_key' => 'manual_journal', 'mapping_name' => 'Manual Journal', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => false, 'is_active' => true],

    ['service_code' => 'PETTY_CASH', 'mapping_key' => 'petty_cash_fund_d', 'mapping_name' => 'Petty Cash - Petty Cash', 'position' => 'D', 'account_code' => '1003', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PETTY_CASH', 'mapping_key' => 'petty_cash_cash_k', 'mapping_name' => 'Petty Cash - Cash/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'EXPENSE', 'mapping_key' => 'expense_expense_d', 'mapping_name' => 'Expense - Expense Account', 'position' => 'D', 'account_code' => '5201', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'EXPENSE', 'mapping_key' => 'expense_cash_k', 'mapping_name' => 'Expense - Cash/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PREPAID_EXPENSE', 'mapping_key' => 'prepaid_expense_asset_d', 'mapping_name' => 'Prepaid Expense - Prepaid Expense', 'position' => 'D', 'account_code' => '1301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PREPAID_EXPENSE', 'mapping_key' => 'prepaid_expense_cash_k', 'mapping_name' => 'Prepaid Expense - Cash/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PREPAID_EXPENSE', 'mapping_key' => 'prepaid_expense_amortization_d', 'mapping_name' => 'Prepaid Expense - Expense', 'position' => 'D', 'account_code' => '5201', 'sequence_no' => 3, 'is_dynamic' => true, 'is_required' => false, 'is_active' => true],
    ['service_code' => 'PREPAID_EXPENSE', 'mapping_key' => 'prepaid_expense_asset_k', 'mapping_name' => 'Prepaid Expense - Prepaid Expense Reclass', 'position' => 'K', 'account_code' => '1301', 'sequence_no' => 4, 'is_dynamic' => false, 'is_required' => false, 'is_active' => true],

    ['service_code' => 'PAYROLL', 'mapping_key' => 'payroll_salary_expense_d', 'mapping_name' => 'Payroll - Salary Expense', 'position' => 'D', 'account_code' => '5101', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PAYROLL', 'mapping_key' => 'payroll_cash_k', 'mapping_name' => 'Payroll - Cash/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'PAYROLL_ACCRUAL', 'mapping_key' => 'payroll_accrual_expense_d', 'mapping_name' => 'Payroll Accrual - Salary Expense', 'position' => 'D', 'account_code' => '5101', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'PAYROLL_ACCRUAL', 'mapping_key' => 'payroll_accrual_payable_k', 'mapping_name' => 'Payroll Accrual - Salary Payable', 'position' => 'K', 'account_code' => '2101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'ASSET_PURCHASE', 'mapping_key' => 'asset_purchase_asset_d', 'mapping_name' => 'Asset Purchase - Asset Account', 'position' => 'D', 'account_code' => '1501', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'ASSET_PURCHASE', 'mapping_key' => 'asset_purchase_cash_k', 'mapping_name' => 'Asset Purchase - Cash/Bank/AP', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'ASSET_DEPRECIATION', 'mapping_key' => 'asset_depreciation_expense_d', 'mapping_name' => 'Asset Depreciation - Depreciation Expense', 'position' => 'D', 'account_code' => '5401', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'ASSET_DEPRECIATION', 'mapping_key' => 'asset_depreciation_accumulated_k', 'mapping_name' => 'Asset Depreciation - Accumulated Depreciation', 'position' => 'K', 'account_code' => '1502', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'ASSET_DISPOSAL', 'mapping_key' => 'asset_disposal_accumulated_d', 'mapping_name' => 'Asset Disposal - Accumulated Depreciation', 'position' => 'D', 'account_code' => '1502', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'ASSET_DISPOSAL', 'mapping_key' => 'asset_disposal_asset_k', 'mapping_name' => 'Asset Disposal - Fixed Asset', 'position' => 'K', 'account_code' => '1501', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'ASSET_REVALUATION', 'mapping_key' => 'asset_revaluation_asset_d', 'mapping_name' => 'Asset Revaluation - Fixed Asset', 'position' => 'D', 'account_code' => '1501', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'ASSET_REVALUATION', 'mapping_key' => 'asset_revaluation_reserve_k', 'mapping_name' => 'Asset Revaluation - Revaluation Reserve', 'position' => 'K', 'account_code' => '3201', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'CUSTOMER_RECEIVABLE_PAYMENT', 'mapping_key' => 'receivable_payment_cash_d', 'mapping_name' => 'Receivable Payment - Cash/Bank', 'position' => 'D', 'account_code' => '1001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'CUSTOMER_RECEIVABLE_PAYMENT', 'mapping_key' => 'receivable_payment_ar_k', 'mapping_name' => 'Receivable Payment - Accounts Receivable', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'CUSTOMER_RECEIVABLE_WRITE_OFF', 'mapping_key' => 'receivable_writeoff_bad_debt_d', 'mapping_name' => 'Receivable Write Off - Bad Debt Expense', 'position' => 'D', 'account_code' => '5501', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'CUSTOMER_RECEIVABLE_WRITE_OFF', 'mapping_key' => 'receivable_writeoff_ar_k', 'mapping_name' => 'Receivable Write Off - Accounts Receivable', 'position' => 'K', 'account_code' => '1101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'VENDOR_PAYMENT', 'mapping_key' => 'vendor_payment_ap_d', 'mapping_name' => 'Vendor Payment - Accounts Payable', 'position' => 'D', 'account_code' => '2001', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'VENDOR_PAYMENT', 'mapping_key' => 'vendor_payment_cash_k', 'mapping_name' => 'Vendor Payment - Cash/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'VENDOR_PAYABLE_WRITE_OFF', 'mapping_key' => 'vendor_writeoff_ap_d', 'mapping_name' => 'Vendor Payable Write Off - Accounts Payable', 'position' => 'D', 'account_code' => '2001', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'VENDOR_PAYABLE_WRITE_OFF', 'mapping_key' => 'vendor_writeoff_income_k', 'mapping_name' => 'Vendor Payable Write Off - Other Income', 'position' => 'K', 'account_code' => '4101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'TAX_OUTPUT', 'mapping_key' => 'tax_output_receivable_d', 'mapping_name' => 'Tax Output - Cash/Receivable', 'position' => 'D', 'account_code' => '1101', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'TAX_OUTPUT', 'mapping_key' => 'tax_output_vat_k', 'mapping_name' => 'Tax Output - Output VAT', 'position' => 'K', 'account_code' => '2301', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'TAX_INPUT', 'mapping_key' => 'tax_input_vat_d', 'mapping_name' => 'Tax Input - Input VAT', 'position' => 'D', 'account_code' => '1601', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'TAX_INPUT', 'mapping_key' => 'tax_input_payable_k', 'mapping_name' => 'Tax Input - Cash/AP', 'position' => 'K', 'account_code' => '2001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'VAT_PAYMENT', 'mapping_key' => 'vat_payment_vat_d', 'mapping_name' => 'VAT Payment - Output VAT', 'position' => 'D', 'account_code' => '2301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'VAT_PAYMENT', 'mapping_key' => 'vat_payment_cash_k', 'mapping_name' => 'VAT Payment - Cash/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'TAX_PAYMENT', 'mapping_key' => 'tax_payment_payable_d', 'mapping_name' => 'Tax Payment - Tax Payable', 'position' => 'D', 'account_code' => '2201', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'TAX_PAYMENT', 'mapping_key' => 'tax_payment_cash_k', 'mapping_name' => 'Tax Payment - Cash/Bank', 'position' => 'K', 'account_code' => '1001', 'sequence_no' => 2, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'MONTH_END_CLOSING', 'mapping_key' => 'month_closing_revenue_d', 'mapping_name' => 'Month End Closing - Revenue Accounts', 'position' => 'D', 'account_code' => '4001', 'sequence_no' => 1, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'MONTH_END_CLOSING', 'mapping_key' => 'month_closing_income_summary_k', 'mapping_name' => 'Month End Closing - Income Summary', 'position' => 'K', 'account_code' => '3301', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'MONTH_END_CLOSING', 'mapping_key' => 'month_closing_income_summary_d', 'mapping_name' => 'Month End Closing - Income Summary', 'position' => 'D', 'account_code' => '3301', 'sequence_no' => 3, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'MONTH_END_CLOSING', 'mapping_key' => 'month_closing_expense_k', 'mapping_name' => 'Month End Closing - Expense Accounts', 'position' => 'K', 'account_code' => '5201', 'sequence_no' => 4, 'is_dynamic' => true, 'is_required' => true, 'is_active' => true],

    ['service_code' => 'YEAR_END_CLOSING', 'mapping_key' => 'year_closing_income_summary_d', 'mapping_name' => 'Year End Closing - Income Summary', 'position' => 'D', 'account_code' => '3301', 'sequence_no' => 1, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
    ['service_code' => 'YEAR_END_CLOSING', 'mapping_key' => 'year_closing_retained_earnings_k', 'mapping_name' => 'Year End Closing - Retained Earnings', 'position' => 'K', 'account_code' => '3101', 'sequence_no' => 2, 'is_dynamic' => false, 'is_required' => true, 'is_active' => true],
];
