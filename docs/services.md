# Available Services

This package ships a default ERP service catalog in `acc_services` and seeds default mapping templates in `acc_service_accounts`.

All account examples below refer to leaf posting accounts. Hierarchy belongs to `acc_account_categories`, not to `acc_accounts`.

## Source Of Truth

- Service codes: [`src/Enums/AccountingServiceCode.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Enums/AccountingServiceCode.php)
- Default service registry: [`src/Support/ServiceCatalog.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Support/ServiceCatalog.php)
- Default mapping templates: [`src/Support/ServiceAccountTemplateRegistry.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Support/ServiceAccountTemplateRegistry.php)
- Seeder for services: [`database/seeders/DefaultAccountingServicesSeeder.php`](/c:/laragon/www/package-custom/laravel-accounting/database/seeders/DefaultAccountingServicesSeeder.php)
- Seeder for mappings: [`database/seeders/DefaultServiceAccountMappingsSeeder.php`](/c:/laragon/www/package-custom/laravel-accounting/database/seeders/DefaultServiceAccountMappingsSeeder.php)

## How To Read The Templates

- `mapping_key` is the stable key used by `JournalService::journalByMapping()`
- `mapping_name` is the human-readable label
- `position` is `D` or `K`
- `account_code` is resolved to `account_id` at seed time
- `sequence_no` controls ordering
- `is_dynamic` means the account may be supplied at runtime
- `is_required` means the mapping must be present for the service to post

## SALES

### `SALES_CASH`

- Service code: `SALES_CASH`
- Description: Cash sales transaction.
- Business purpose: Sales settled immediately in cash or cash equivalent.
- Default journal template:
  - Debit: Cash/Bank, Cost Of Goods Sold
  - Credit: Sales Revenue, Inventory
- Required mappings:
  - `sales_cash_cash_d`
  - `sales_cash_sales_k`
  - `sales_cash_cogs_d`
  - `sales_cash_inventory_k`
- Dynamic mappings:
  - `sales_cash_cash_d`
- Example:

```php
app(\ESolution\LaravelAccounting\Services\JournalService::class)->journalByMapping([
    'service_code' => 'SALES_CASH',
    'trx_date' => '2026-01-15',
    'items' => [
        ['mapping_key' => 'sales_cash_cash_d', 'amount' => 100000],
        ['mapping_key' => 'sales_cash_sales_k', 'amount' => 100000],
        ['mapping_key' => 'sales_cash_cogs_d', 'amount' => 60000],
        ['mapping_key' => 'sales_cash_inventory_k', 'amount' => 60000],
    ],
]);
```

### `SALES_CREDIT`

- Service code: `SALES_CREDIT`
- Description: Credit sales transaction.
- Business purpose: Sales recognized on account receivable.
- Default journal template:
  - Debit: Accounts Receivable, Cost Of Goods Sold
  - Credit: Sales Revenue, Inventory
- Required mappings:
  - `sales_credit_ar_d`
  - `sales_credit_sales_k`
  - `sales_credit_cogs_d`
  - `sales_credit_inventory_k`
- Dynamic mappings: none

### `SALES_RETURN`

- Service code: `SALES_RETURN`
- Description: Sales return transaction.
- Business purpose: Reverse prior sales and inventory movement.
- Default journal template:
  - Debit: Sales Return, Inventory
  - Credit: Accounts Receivable / Cash, Cost Of Goods Sold
- Required mappings:
  - `sales_return_sales_return_d`
  - `sales_return_receivable_k`
  - `sales_return_inventory_d`
  - `sales_return_cogs_k`
- Dynamic mappings:
  - `sales_return_receivable_k`

### `SALES_DISCOUNT`

- Service code: `SALES_DISCOUNT`
- Description: Sales discount transaction.
- Business purpose: Record discount granted to a customer.
- Default journal template:
  - Debit: Sales Discount
  - Credit: Accounts Receivable / Cash
- Required mappings:
  - `sales_discount_discount_d`
  - `sales_discount_receivable_k`
- Dynamic mappings:
  - `sales_discount_receivable_k`

### `SALES_WRITE_OFF`

- Service code: `SALES_WRITE_OFF`
- Description: Sales write-off transaction.
- Business purpose: Write off uncollectible customer balances.
- Default journal template:
  - Debit: Bad Debt Expense
  - Credit: Accounts Receivable
- Required mappings:
  - `sales_writeoff_bad_debt_d`
  - `sales_writeoff_ar_k`
- Dynamic mappings: none

## PURCHASE

### `PURCHASE_CASH`

- Service code: `PURCHASE_CASH`
- Description: Cash purchase transaction.
- Business purpose: Purchase inventory paid immediately.
- Default journal template:
  - Debit: Inventory
  - Credit: Cash/Bank
- Required mappings:
  - `purchase_cash_inventory_d`
  - `purchase_cash_cash_k`
- Dynamic mappings:
  - `purchase_cash_cash_k`

### `PURCHASE_CREDIT`

- Service code: `PURCHASE_CREDIT`
- Description: Credit purchase transaction.
- Business purpose: Purchase inventory on account payable.
- Default journal template:
  - Debit: Inventory
  - Credit: Accounts Payable
- Required mappings:
  - `purchase_credit_inventory_d`
  - `purchase_credit_ap_k`
- Dynamic mappings: none

### `PURCHASE_RETURN`

- Service code: `PURCHASE_RETURN`
- Description: Purchase return transaction.
- Business purpose: Return inventory to vendor and reduce payables.
- Default journal template:
  - Debit: Accounts Payable
  - Credit: Inventory
- Required mappings:
  - `purchase_return_ap_d`
  - `purchase_return_inventory_k`
- Dynamic mappings: none

## INVENTORY

### `STOCK_OPENING`

- Service code: `STOCK_OPENING`
- Description: Opening inventory balance.
- Business purpose: Seed opening stock.
- Default journal template:
  - Debit: Inventory
  - Credit: Opening Balance Equity
- Required mappings:
  - `stock_opening_inventory_d`
  - `stock_opening_opening_balance_k`
- Dynamic mappings: none

### `STOCK_ADJUSTMENT_PLUS`

- Service code: `STOCK_ADJUSTMENT_PLUS`
- Description: Positive stock adjustment.
- Business purpose: Record inventory gains from stock count adjustments.
- Default journal template:
  - Debit: Inventory
  - Credit: Inventory Gain
- Required mappings:
  - `stock_adjustment_plus_inventory_d`
  - `stock_adjustment_plus_gain_k`
- Dynamic mappings: none

### `STOCK_ADJUSTMENT_MINUS`

- Service code: `STOCK_ADJUSTMENT_MINUS`
- Description: Negative stock adjustment.
- Business purpose: Record inventory loss from stock count adjustments.
- Default journal template:
  - Debit: Inventory Loss
  - Credit: Inventory
- Required mappings:
  - `stock_adjustment_minus_loss_d`
  - `stock_adjustment_minus_inventory_k`
- Dynamic mappings: none

### `STOCK_TRANSFER`

- Service code: `STOCK_TRANSFER`
- Description: Stock transfer.
- Business purpose: Move stock between locations.
- Default journal template: none
- Required mappings: none
- Dynamic mappings: none
- Notes: intentionally has no default journal template so integrators can define location-specific logic later.

### `STOCK_OPNAME_GAIN`

- Service code: `STOCK_OPNAME_GAIN`
- Description: Stock opname gain.
- Business purpose: Record surplus stock discovered during a stock count.
- Default journal template:
  - Debit: Inventory
  - Credit: Inventory Gain
- Required mappings:
  - `stock_opname_gain_inventory_d`
  - `stock_opname_gain_gain_k`
- Dynamic mappings: none

### `STOCK_OPNAME_LOSS`

- Service code: `STOCK_OPNAME_LOSS`
- Description: Stock opname loss.
- Business purpose: Record stock shortage discovered during a stock count.
- Default journal template:
  - Debit: Inventory Loss
  - Credit: Inventory
- Required mappings:
  - `stock_opname_loss_loss_d`
  - `stock_opname_loss_inventory_k`
- Dynamic mappings: none

## FINANCE

### `CASH_IN`

- Service code: `CASH_IN`
- Description: Cash in transaction.
- Business purpose: Record miscellaneous receipts.
- Default journal template:
  - Debit: Cash/Bank
  - Credit: Other Income
- Required mappings:
  - `cash_in_cash_d`
  - `cash_in_other_income_k`
- Dynamic mappings:
  - `cash_in_cash_d`

### `CASH_OUT`

- Service code: `CASH_OUT`
- Description: Cash out transaction.
- Business purpose: Record general expenses paid from cash/bank.
- Default journal template:
  - Debit: Operational Expense
  - Credit: Cash/Bank
- Required mappings:
  - `cash_out_expense_d`
  - `cash_out_cash_k`
- Dynamic mappings:
  - `cash_out_expense_d`
  - `cash_out_cash_k`

### `BANK_TRANSFER`

- Service code: `BANK_TRANSFER`
- Description: Bank transfer transaction.
- Business purpose: Move funds between bank accounts.
- Default journal template:
  - Debit: Destination Bank
  - Credit: Source Bank
- Required mappings:
  - `bank_transfer_destination_bank_d`
  - `bank_transfer_source_bank_k`
- Dynamic mappings:
  - `bank_transfer_destination_bank_d`
  - `bank_transfer_source_bank_k`

### `JOURNAL_MANUAL`

- Service code: `JOURNAL_MANUAL`
- Description: Manual journal service.
- Business purpose: Manually entered journal adjustments.
- Default journal template: none
- Required mappings: none
- Dynamic mappings: none

### `PETTY_CASH`

- Service code: `PETTY_CASH`
- Description: Petty cash transaction.
- Business purpose: Fund or replenish petty cash.
- Default journal template:
  - Debit: Petty Cash
  - Credit: Cash/Bank
- Required mappings:
  - `petty_cash_fund_d`
  - `petty_cash_cash_k`
- Dynamic mappings:
  - `petty_cash_cash_k`

## EXPENSE

### `EXPENSE`

- Service code: `EXPENSE`
- Description: Expense transaction.
- Business purpose: Generic operational expense posting.
- Default journal template:
  - Debit: Expense Account
  - Credit: Cash/Bank
- Required mappings:
  - `expense_expense_d`
  - `expense_cash_k`
- Dynamic mappings:
  - `expense_expense_d`
  - `expense_cash_k`

### `PREPAID_EXPENSE`

- Service code: `PREPAID_EXPENSE`
- Description: Prepaid expense transaction.
- Business purpose: Acquire prepaid expense and later amortize it.
- Default journal template:
  - Acquisition:
    - Debit: Prepaid Expense
    - Credit: Cash/Bank
  - Amortization:
    - Debit: Expense
    - Credit: Prepaid Expense
- Required mappings:
  - `prepaid_expense_asset_d`
  - `prepaid_expense_cash_k`
  - `prepaid_expense_amortization_d`
  - `prepaid_expense_asset_k`
- Dynamic mappings:
  - `prepaid_expense_amortization_d`
  - `prepaid_expense_asset_k`

## PAYROLL

### `PAYROLL`

- Service code: `PAYROLL`
- Description: Payroll payment transaction.
- Business purpose: Pay employees from cash/bank.
- Default journal template:
  - Debit: Salary Expense
  - Credit: Cash/Bank
- Required mappings:
  - `payroll_salary_expense_d`
  - `payroll_cash_k`
- Dynamic mappings:
  - `payroll_cash_k`

### `PAYROLL_ACCRUAL`

- Service code: `PAYROLL_ACCRUAL`
- Description: Payroll accrual transaction.
- Business purpose: Recognize salary expense and salary payable.
- Default journal template:
  - Debit: Salary Expense
  - Credit: Salary Payable
- Required mappings:
  - `payroll_accrual_expense_d`
  - `payroll_accrual_payable_k`
- Dynamic mappings: none

## ASSET

### `ASSET_PURCHASE`

- Service code: `ASSET_PURCHASE`
- Description: Fixed asset purchase transaction.
- Business purpose: Capitalize a fixed asset purchase.
- Default journal template:
  - Debit: Fixed Asset
  - Credit: Cash/Bank/AP
- Required mappings:
  - `asset_purchase_asset_d`
  - `asset_purchase_cash_k`
- Dynamic mappings:
  - `asset_purchase_asset_d`
  - `asset_purchase_cash_k`

### `ASSET_DEPRECIATION`

- Service code: `ASSET_DEPRECIATION`
- Description: Asset depreciation transaction.
- Business purpose: Periodic depreciation expense.
- Default journal template:
  - Debit: Depreciation Expense
  - Credit: Accumulated Depreciation
- Required mappings:
  - `asset_depreciation_expense_d`
  - `asset_depreciation_accumulated_k`
- Dynamic mappings: none

### `ASSET_DISPOSAL`

- Service code: `ASSET_DISPOSAL`
- Description: Asset disposal transaction.
- Business purpose: Remove disposed asset and accumulated depreciation.
- Default journal template:
  - Debit: Accumulated Depreciation
  - Credit: Fixed Asset
- Required mappings:
  - `asset_disposal_accumulated_d`
  - `asset_disposal_asset_k`
- Dynamic mappings: none

### `ASSET_REVALUATION`

- Service code: `ASSET_REVALUATION`
- Description: Asset revaluation transaction.
- Business purpose: Record approved asset revaluation.
- Default journal template:
  - Debit: Fixed Asset
  - Credit: Revaluation Reserve
- Required mappings:
  - `asset_revaluation_asset_d`
  - `asset_revaluation_reserve_k`
- Dynamic mappings: none

## RECEIVABLE

### `CUSTOMER_RECEIVABLE_PAYMENT`

- Service code: `CUSTOMER_RECEIVABLE_PAYMENT`
- Description: Customer receivable payment.
- Business purpose: Customer settles an invoice.
- Default journal template:
  - Debit: Cash/Bank
  - Credit: Accounts Receivable
- Required mappings:
  - `receivable_payment_cash_d`
  - `receivable_payment_ar_k`
- Dynamic mappings:
  - `receivable_payment_cash_d`

### `CUSTOMER_RECEIVABLE_WRITE_OFF`

- Service code: `CUSTOMER_RECEIVABLE_WRITE_OFF`
- Description: Customer receivable write-off.
- Business purpose: Remove uncollectible customer balances.
- Default journal template:
  - Debit: Bad Debt Expense
  - Credit: Accounts Receivable
- Required mappings:
  - `receivable_writeoff_bad_debt_d`
  - `receivable_writeoff_ar_k`
- Dynamic mappings: none

## PAYABLE

### `VENDOR_PAYMENT`

- Service code: `VENDOR_PAYMENT`
- Description: Vendor payment.
- Business purpose: Pay suppliers against AP.
- Default journal template:
  - Debit: Accounts Payable
  - Credit: Cash/Bank
- Required mappings:
  - `vendor_payment_ap_d`
  - `vendor_payment_cash_k`
- Dynamic mappings:
  - `vendor_payment_cash_k`

### `VENDOR_PAYABLE_WRITE_OFF`

- Service code: `VENDOR_PAYABLE_WRITE_OFF`
- Description: Vendor payable write-off.
- Business purpose: Write off payable balances after reconciliation.
- Default journal template:
  - Debit: Accounts Payable
  - Credit: Other Income
- Required mappings:
  - `vendor_writeoff_ap_d`
  - `vendor_writeoff_income_k`
- Dynamic mappings: none

## TAX

### `TAX_OUTPUT`

- Service code: `TAX_OUTPUT`
- Description: Output tax transaction.
- Business purpose: Record tax output from taxable sales.
- Default journal template:
  - Debit: Accounts Receivable / Cash
  - Credit: Output VAT
- Required mappings:
  - `tax_output_receivable_d`
  - `tax_output_vat_k`
- Dynamic mappings:
  - `tax_output_receivable_d`

### `TAX_INPUT`

- Service code: `TAX_INPUT`
- Description: Input tax transaction.
- Business purpose: Record input VAT on taxable purchases or expenses.
- Default journal template:
  - Debit: Input VAT
  - Credit: Accounts Payable / Cash
- Required mappings:
  - `tax_input_vat_d`
  - `tax_input_payable_k`
- Dynamic mappings:
  - `tax_input_payable_k`

### `TAX_PAYMENT`

- Service code: `TAX_PAYMENT`
- Description: Tax payment transaction.
- Business purpose: Pay tax liabilities.
- Default journal template:
  - Debit: Tax Payable
  - Credit: Cash/Bank
- Required mappings:
  - `tax_payment_payable_d`
  - `tax_payment_cash_k`
- Dynamic mappings:
  - `tax_payment_cash_k`

## CLOSING

### `MONTH_END_CLOSING`

- Service code: `MONTH_END_CLOSING`
- Description: Month-end closing transaction.
- Business purpose: Move revenue and expense balances into income summary.
- Default journal template:
  - Debit: Revenue Accounts
  - Credit: Income Summary
  - Debit: Income Summary
  - Credit: Expense Accounts
- Required mappings:
  - `month_closing_revenue_d`
  - `month_closing_income_summary_k`
  - `month_closing_income_summary_d`
  - `month_closing_expense_k`
- Dynamic mappings:
  - `month_closing_revenue_d`
  - `month_closing_expense_k`

### `YEAR_END_CLOSING`

- Service code: `YEAR_END_CLOSING`
- Description: Year-end closing transaction.
- Business purpose: Transfer income summary to retained earnings.
- Default journal template:
  - Debit: Income Summary
  - Credit: Retained Earnings
- Required mappings:
  - `year_closing_income_summary_d`
  - `year_closing_retained_earnings_k`
- Dynamic mappings: none

