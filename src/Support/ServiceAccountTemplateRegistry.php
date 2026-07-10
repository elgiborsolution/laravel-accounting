<?php

namespace ESolution\LaravelAccounting\Support;

use ESolution\LaravelAccounting\Enums\AccountingServiceCode;

class ServiceAccountTemplateRegistry
{
    public function all(): array
    {
        return [
            AccountingServiceCode::SALES_CASH_VAT->value => [
                $this->template('sales_cash_vat_cash_d', 'Cash Sales with VAT 11% - Cash/Bank', 'D', '1001', 1, true),
                $this->template('sales_cash_vat_sales_k', 'Cash Sales with VAT 11% - Sales Revenue', 'K', '4001', 2),
                $this->template('sales_cash_vat_vat_k', 'Cash Sales with VAT 11% - Output VAT', 'K', '2301', 3),
                $this->template('sales_cash_vat_cogs_d', 'Cash Sales with VAT 11% - Cost Of Goods Sold', 'D', '5001', 4),
                $this->template('sales_cash_vat_inventory_k', 'Cash Sales with VAT 11% - Inventory', 'K', '1201', 5),
            ],
            AccountingServiceCode::SALES_CREDIT_VAT->value => [
                $this->template('sales_credit_vat_ar_d', 'Credit Sales with VAT 11% - Accounts Receivable', 'D', '1101', 1),
                $this->template('sales_credit_vat_sales_k', 'Credit Sales with VAT 11% - Sales Revenue', 'K', '4001', 2),
                $this->template('sales_credit_vat_vat_k', 'Credit Sales with VAT 11% - Output VAT', 'K', '2301', 3),
                $this->template('sales_credit_vat_cogs_d', 'Credit Sales with VAT 11% - Cost Of Goods Sold', 'D', '5001', 4),
                $this->template('sales_credit_vat_inventory_k', 'Credit Sales with VAT 11% - Inventory', 'K', '1201', 5),
            ],
            AccountingServiceCode::SALES_CASH->value => [
                $this->template('sales_cash_cash_d', 'Sales Cash - Cash/Bank', 'D', '1001', 1, true),
                $this->template('sales_cash_sales_k', 'Sales Cash - Sales Revenue', 'K', '4001', 2),
                $this->template('sales_cash_cogs_d', 'Sales Cash - Cost Of Goods Sold', 'D', '5001', 3),
                $this->template('sales_cash_inventory_k', 'Sales Cash - Inventory', 'K', '1201', 4),
            ],
            AccountingServiceCode::SALES_CREDIT->value => [
                $this->template('sales_credit_ar_d', 'Sales Credit - Accounts Receivable', 'D', '1101', 1),
                $this->template('sales_credit_sales_k', 'Sales Credit - Sales Revenue', 'K', '4001', 2),
                $this->template('sales_credit_cogs_d', 'Sales Credit - Cost Of Goods Sold', 'D', '5001', 3),
                $this->template('sales_credit_inventory_k', 'Sales Credit - Inventory', 'K', '1201', 4),
            ],
            AccountingServiceCode::SALES_RETURN->value => [
                $this->template('sales_return_sales_return_d', 'Sales Return - Sales Return', 'D', '5701', 1),
                $this->template('sales_return_receivable_k', 'Sales Return - Receivable/Cash', 'K', '1101', 2, true),
                $this->template('sales_return_inventory_d', 'Sales Return - Inventory', 'D', '1201', 3),
                $this->template('sales_return_cogs_k', 'Sales Return - Cost Of Goods Sold', 'K', '5001', 4),
            ],
            AccountingServiceCode::SALES_DISCOUNT->value => [
                $this->template('sales_discount_discount_d', 'Sales Discount - Sales Discount', 'D', '5601', 1),
                $this->template('sales_discount_receivable_k', 'Sales Discount - Receivable/Cash', 'K', '1101', 2, true),
            ],
            AccountingServiceCode::SALES_WRITE_OFF->value => [
                $this->template('sales_writeoff_bad_debt_d', 'Sales Write Off - Bad Debt Expense', 'D', '5501', 1),
                $this->template('sales_writeoff_ar_k', 'Sales Write Off - Accounts Receivable', 'K', '1101', 2),
            ],
            AccountingServiceCode::PURCHASE_CASH->value => [
                $this->template('purchase_cash_inventory_d', 'Purchase Cash - Inventory', 'D', '1201', 1),
                $this->template('purchase_cash_cash_k', 'Purchase Cash - Cash/Bank', 'K', '1001', 2, true),
            ],
            AccountingServiceCode::PURCHASE_CREDIT->value => [
                $this->template('purchase_credit_inventory_d', 'Purchase Credit - Inventory', 'D', '1201', 1),
                $this->template('purchase_credit_ap_k', 'Purchase Credit - Accounts Payable', 'K', '2001', 2),
            ],
            AccountingServiceCode::PURCHASE_RETURN->value => [
                $this->template('purchase_return_ap_d', 'Purchase Return - Accounts Payable', 'D', '2001', 1),
                $this->template('purchase_return_inventory_k', 'Purchase Return - Inventory', 'K', '1201', 2),
            ],
            AccountingServiceCode::STOCK_OPENING->value => [
                $this->template('stock_opening_inventory_d', 'Stock Opening - Inventory', 'D', '1201', 1),
                $this->template('stock_opening_opening_balance_k', 'Stock Opening - Opening Balance Equity', 'K', '3001', 2),
            ],
            AccountingServiceCode::STOCK_ADJUSTMENT_PLUS->value => [
                $this->template('stock_adjustment_plus_inventory_d', 'Stock Adjustment Plus - Inventory', 'D', '1201', 1),
                $this->template('stock_adjustment_plus_gain_k', 'Stock Adjustment Plus - Inventory Gain', 'K', '4201', 2),
            ],
            AccountingServiceCode::STOCK_ADJUSTMENT_MINUS->value => [
                $this->template('stock_adjustment_minus_loss_d', 'Stock Adjustment Minus - Inventory Loss', 'D', '5301', 1),
                $this->template('stock_adjustment_minus_inventory_k', 'Stock Adjustment Minus - Inventory', 'K', '1201', 2),
            ],
            AccountingServiceCode::STOCK_TRANSFER->value => [],
            AccountingServiceCode::STOCK_OPNAME_GAIN->value => [
                $this->template('stock_opname_gain_inventory_d', 'Stock Opname Gain - Inventory', 'D', '1201', 1),
                $this->template('stock_opname_gain_gain_k', 'Stock Opname Gain - Inventory Gain', 'K', '4201', 2),
            ],
            AccountingServiceCode::STOCK_OPNAME_LOSS->value => [
                $this->template('stock_opname_loss_loss_d', 'Stock Opname Loss - Inventory Loss', 'D', '5301', 1),
                $this->template('stock_opname_loss_inventory_k', 'Stock Opname Loss - Inventory', 'K', '1201', 2),
            ],
            AccountingServiceCode::CASH_IN->value => [
                $this->template('cash_in_cash_d', 'Cash In - Cash/Bank', 'D', '1001', 1, true),
                $this->template('cash_in_other_income_k', 'Cash In - Other Income', 'K', '4101', 2),
            ],
            AccountingServiceCode::CASH_OUT->value => [
                $this->template('cash_out_expense_d', 'Cash Out - Expense Account', 'D', '5201', 1, true),
                $this->template('cash_out_cash_k', 'Cash Out - Cash/Bank', 'K', '1001', 2, true),
            ],
            AccountingServiceCode::BANK_TRANSFER->value => [
                $this->template('bank_transfer_destination_bank_d', 'Bank Transfer - Destination Bank', 'D', '1002', 1, true),
                $this->template('bank_transfer_source_bank_k', 'Bank Transfer - Source Bank', 'K', '1002', 2, true),
            ],
            AccountingServiceCode::JOURNAL_MANUAL->value => [],
            AccountingServiceCode::PETTY_CASH->value => [
                $this->template('petty_cash_fund_d', 'Petty Cash - Petty Cash', 'D', '1003', 1),
                $this->template('petty_cash_cash_k', 'Petty Cash - Cash/Bank', 'K', '1001', 2, true),
            ],
            AccountingServiceCode::EXPENSE->value => [
                $this->template('expense_expense_d', 'Expense - Expense Account', 'D', '5201', 1, true),
                $this->template('expense_cash_k', 'Expense - Cash/Bank', 'K', '1001', 2, true),
            ],
            AccountingServiceCode::PREPAID_EXPENSE->value => [
                $this->template('prepaid_expense_asset_d', 'Prepaid Expense - Prepaid Expense', 'D', '1301', 1),
                $this->template('prepaid_expense_cash_k', 'Prepaid Expense - Cash/Bank', 'K', '1001', 2, true),
                $this->template('prepaid_expense_amortization_d', 'Prepaid Expense - Expense', 'D', '5201', 3, true, false),
                $this->template('prepaid_expense_asset_k', 'Prepaid Expense - Prepaid Expense Reclass', 'K', '1301', 4, false, false),
            ],
            AccountingServiceCode::PAYROLL->value => [
                $this->template('payroll_salary_expense_d', 'Payroll - Salary Expense', 'D', '5101', 1),
                $this->template('payroll_cash_k', 'Payroll - Cash/Bank', 'K', '1001', 2, true),
            ],
            AccountingServiceCode::PAYROLL_ACCRUAL->value => [
                $this->template('payroll_accrual_expense_d', 'Payroll Accrual - Salary Expense', 'D', '5101', 1),
                $this->template('payroll_accrual_payable_k', 'Payroll Accrual - Salary Payable', 'K', '2101', 2),
            ],
            AccountingServiceCode::ASSET_PURCHASE->value => [
                $this->template('asset_purchase_asset_d', 'Asset Purchase - Asset Account', 'D', '1501', 1, true),
                $this->template('asset_purchase_cash_k', 'Asset Purchase - Cash/Bank/AP', 'K', '1001', 2, true),
            ],
            AccountingServiceCode::ASSET_DEPRECIATION->value => [
                $this->template('asset_depreciation_expense_d', 'Asset Depreciation - Depreciation Expense', 'D', '5401', 1),
                $this->template('asset_depreciation_accumulated_k', 'Asset Depreciation - Accumulated Depreciation', 'K', '1502', 2),
            ],
            AccountingServiceCode::ASSET_DISPOSAL->value => [
                $this->template('asset_disposal_accumulated_d', 'Asset Disposal - Accumulated Depreciation', 'D', '1502', 1),
                $this->template('asset_disposal_asset_k', 'Asset Disposal - Fixed Asset', 'K', '1501', 2),
            ],
            AccountingServiceCode::ASSET_REVALUATION->value => [
                $this->template('asset_revaluation_asset_d', 'Asset Revaluation - Fixed Asset', 'D', '1501', 1),
                $this->template('asset_revaluation_reserve_k', 'Asset Revaluation - Revaluation Reserve', 'K', '3201', 2),
            ],
            AccountingServiceCode::CUSTOMER_RECEIVABLE_PAYMENT->value => [
                $this->template('receivable_payment_cash_d', 'Receivable Payment - Cash/Bank', 'D', '1001', 1, true),
                $this->template('receivable_payment_ar_k', 'Receivable Payment - Accounts Receivable', 'K', '1101', 2),
            ],
            AccountingServiceCode::CUSTOMER_RECEIVABLE_WRITE_OFF->value => [
                $this->template('receivable_writeoff_bad_debt_d', 'Receivable Write Off - Bad Debt Expense', 'D', '5501', 1),
                $this->template('receivable_writeoff_ar_k', 'Receivable Write Off - Accounts Receivable', 'K', '1101', 2),
            ],
            AccountingServiceCode::VENDOR_PAYMENT->value => [
                $this->template('vendor_payment_ap_d', 'Vendor Payment - Accounts Payable', 'D', '2001', 1),
                $this->template('vendor_payment_cash_k', 'Vendor Payment - Cash/Bank', 'K', '1001', 2, true),
            ],
            AccountingServiceCode::VENDOR_PAYABLE_WRITE_OFF->value => [
                $this->template('vendor_writeoff_ap_d', 'Vendor Payable Write Off - Accounts Payable', 'D', '2001', 1),
                $this->template('vendor_writeoff_income_k', 'Vendor Payable Write Off - Other Income', 'K', '4101', 2),
            ],
            AccountingServiceCode::TAX_OUTPUT->value => [
                $this->template('tax_output_receivable_d', 'Tax Output - Cash/Receivable', 'D', '1101', 1, true),
                $this->template('tax_output_vat_k', 'Tax Output - Output VAT', 'K', '2301', 2),
            ],
            AccountingServiceCode::TAX_INPUT->value => [
                $this->template('tax_input_vat_d', 'Tax Input - Input VAT', 'D', '1601', 1),
                $this->template('tax_input_payable_k', 'Tax Input - Cash/AP', 'K', '2001', 2, true),
            ],
            AccountingServiceCode::VAT_PAYMENT->value => [
                $this->template('vat_payment_vat_d', 'VAT Payment - Output VAT', 'D', '2301', 1),
                $this->template('vat_payment_cash_k', 'VAT Payment - Cash/Bank', 'K', '1001', 2, true),
            ],
            AccountingServiceCode::TAX_PAYMENT->value => [
                $this->template('tax_payment_payable_d', 'Tax Payment - Tax Payable', 'D', '2201', 1),
                $this->template('tax_payment_cash_k', 'Tax Payment - Cash/Bank', 'K', '1001', 2, true),
            ],
            AccountingServiceCode::MONTH_END_CLOSING->value => [
                $this->template('month_closing_revenue_d', 'Month End Closing - Revenue Accounts', 'D', '4001', 1, true),
                $this->template('month_closing_income_summary_k', 'Month End Closing - Income Summary', 'K', '3301', 2),
                $this->template('month_closing_income_summary_d', 'Month End Closing - Income Summary', 'D', '3301', 3),
                $this->template('month_closing_expense_k', 'Month End Closing - Expense Accounts', 'K', '5201', 4, true),
            ],
            AccountingServiceCode::YEAR_END_CLOSING->value => [
                $this->template('year_closing_income_summary_d', 'Year End Closing - Income Summary', 'D', '3301', 1),
                $this->template('year_closing_retained_earnings_k', 'Year End Closing - Retained Earnings', 'K', '3101', 2),
            ],
        ];
    }

    public function forService(string|AccountingServiceCode $serviceCode): array
    {
        $serviceCode = $serviceCode instanceof AccountingServiceCode ? $serviceCode->value : $serviceCode;

        return $this->all()[$serviceCode] ?? [];
    }

    protected function template(
        string $mappingKey,
        string $mappingName,
        string $position,
        string $accountCode,
        int $sequenceNo,
        bool $isDynamic = false,
        bool $isRequired = true,
        bool $isActive = true,
    ): array {
        return [
            'mapping_key' => $mappingKey,
            'mapping_name' => $mappingName,
            'position' => $position,
            'account_code' => $accountCode,
            'sequence_no' => $sequenceNo,
            'is_dynamic' => $isDynamic,
            'is_required' => $isRequired,
            'is_active' => $isActive,
        ];
    }
}
