<?php

return [
    ['service_code' => 'SALES_CASH', 'service_name' => 'Cash Sales', 'module_name' => 'SALES', 'description' => 'Records sales transactions that are paid immediately in cash or cash equivalent.', 'is_active' => true],
    ['service_code' => 'SALES_CREDIT', 'service_name' => 'Credit Sales', 'module_name' => 'SALES', 'description' => 'Records sales invoices that create customer receivables.', 'is_active' => true],
    ['service_code' => 'SALES_RETURN', 'service_name' => 'Sales Return', 'module_name' => 'SALES', 'description' => 'Records goods returned by customers and reverses related sales value.', 'is_active' => true],
    ['service_code' => 'SALES_DISCOUNT', 'service_name' => 'Sales Discount', 'module_name' => 'SALES', 'description' => 'Records discounts granted to customers on completed sales transactions.', 'is_active' => true],
    ['service_code' => 'SALES_WRITE_OFF', 'service_name' => 'Sales Write Off', 'module_name' => 'SALES', 'description' => 'Records sales-related balances that must be written off based on policy approval.', 'is_active' => true],

    ['service_code' => 'PURCHASE_CASH', 'service_name' => 'Cash Purchase', 'module_name' => 'PURCHASE', 'description' => 'Records purchases settled immediately using cash or bank funds.', 'is_active' => true],
    ['service_code' => 'PURCHASE_CREDIT', 'service_name' => 'Credit Purchase', 'module_name' => 'PURCHASE', 'description' => 'Records supplier purchases that create payable balances.', 'is_active' => true],
    ['service_code' => 'PURCHASE_RETURN', 'service_name' => 'Purchase Return', 'module_name' => 'PURCHASE', 'description' => 'Records returned goods to vendors and reverses related purchase value.', 'is_active' => true],

    ['service_code' => 'STOCK_OPENING', 'service_name' => 'Stock Opening', 'module_name' => 'INVENTORY', 'description' => 'Records beginning inventory balances during setup or opening period migration.', 'is_active' => true],
    ['service_code' => 'STOCK_ADJUSTMENT_PLUS', 'service_name' => 'Stock Adjustment Plus', 'module_name' => 'INVENTORY', 'description' => 'Records inventory increases from adjustments outside normal purchasing flow.', 'is_active' => true],
    ['service_code' => 'STOCK_ADJUSTMENT_MINUS', 'service_name' => 'Stock Adjustment Minus', 'module_name' => 'INVENTORY', 'description' => 'Records inventory decreases from adjustments outside normal sales flow.', 'is_active' => true],
    ['service_code' => 'STOCK_TRANSFER', 'service_name' => 'Stock Transfer', 'module_name' => 'INVENTORY', 'description' => 'Records inventory movements between warehouses, locations, or stock segments.', 'is_active' => true],
    ['service_code' => 'STOCK_OPNAME_GAIN', 'service_name' => 'Stock Opname Gain', 'module_name' => 'INVENTORY', 'description' => 'Records surplus inventory discovered during stock opname or stock count.', 'is_active' => true],
    ['service_code' => 'STOCK_OPNAME_LOSS', 'service_name' => 'Stock Opname Loss', 'module_name' => 'INVENTORY', 'description' => 'Records inventory shortages discovered during stock opname or stock count.', 'is_active' => true],

    ['service_code' => 'CASH_IN', 'service_name' => 'Cash In', 'module_name' => 'FINANCE', 'description' => 'Records non-sales cash receipts such as miscellaneous income or owner funding.', 'is_active' => true],
    ['service_code' => 'CASH_OUT', 'service_name' => 'Cash Out', 'module_name' => 'FINANCE', 'description' => 'Records non-purchase cash disbursements such as operational payouts.', 'is_active' => true],
    ['service_code' => 'BANK_TRANSFER', 'service_name' => 'Bank Transfer', 'module_name' => 'FINANCE', 'description' => 'Records transfers between cash and bank accounts or between banks.', 'is_active' => true],
    ['service_code' => 'JOURNAL_MANUAL', 'service_name' => 'Manual Journal', 'module_name' => 'FINANCE', 'description' => 'Represents manual journal adjustments initiated by accounting staff.', 'is_active' => true],
    ['service_code' => 'PETTY_CASH', 'service_name' => 'Petty Cash', 'module_name' => 'FINANCE', 'description' => 'Records petty cash funding, usage, and replenishment transactions.', 'is_active' => true],

    ['service_code' => 'EXPENSE', 'service_name' => 'Expense', 'module_name' => 'EXPENSE', 'description' => 'Records standard operating expense recognition transactions.', 'is_active' => true],
    ['service_code' => 'PREPAID_EXPENSE', 'service_name' => 'Prepaid Expense', 'module_name' => 'EXPENSE', 'description' => 'Records prepaid expense acquisition and amortization-related mappings.', 'is_active' => true],

    ['service_code' => 'PAYROLL', 'service_name' => 'Payroll Payment', 'module_name' => 'PAYROLL', 'description' => 'Records payroll disbursement transactions to employees and statutory parties.', 'is_active' => true],
    ['service_code' => 'PAYROLL_ACCRUAL', 'service_name' => 'Payroll Accrual', 'module_name' => 'PAYROLL', 'description' => 'Records payroll expenses and liabilities before settlement.', 'is_active' => true],

    ['service_code' => 'ASSET_PURCHASE', 'service_name' => 'Asset Purchase', 'module_name' => 'ASSET', 'description' => 'Records acquisition of fixed assets and related capitalization.', 'is_active' => true],
    ['service_code' => 'ASSET_DEPRECIATION', 'service_name' => 'Asset Depreciation', 'module_name' => 'ASSET', 'description' => 'Records periodic depreciation expense and accumulated depreciation.', 'is_active' => true],
    ['service_code' => 'ASSET_DISPOSAL', 'service_name' => 'Asset Disposal', 'module_name' => 'ASSET', 'description' => 'Records sale, retirement, or disposal of fixed assets.', 'is_active' => true],
    ['service_code' => 'ASSET_REVALUATION', 'service_name' => 'Asset Revaluation', 'module_name' => 'ASSET', 'description' => 'Records approved upward or downward revaluation of asset carrying values.', 'is_active' => true],

    ['service_code' => 'CUSTOMER_RECEIVABLE_PAYMENT', 'service_name' => 'Customer Receivable Payment', 'module_name' => 'ACCOUNT_RECEIVABLE', 'description' => 'Records incoming payments against outstanding customer receivables.', 'is_active' => true],
    ['service_code' => 'CUSTOMER_RECEIVABLE_WRITE_OFF', 'service_name' => 'Customer Receivable Write Off', 'module_name' => 'ACCOUNT_RECEIVABLE', 'description' => 'Records customer receivables that are written off as uncollectible.', 'is_active' => true],

    ['service_code' => 'VENDOR_PAYMENT', 'service_name' => 'Vendor Payment', 'module_name' => 'ACCOUNT_PAYABLE', 'description' => 'Records outgoing payments against vendor payable balances.', 'is_active' => true],
    ['service_code' => 'VENDOR_PAYABLE_WRITE_OFF', 'service_name' => 'Vendor Payable Write Off', 'module_name' => 'ACCOUNT_PAYABLE', 'description' => 'Records vendor payable balances written off after reconciliation or approval.', 'is_active' => true],

    ['service_code' => 'TAX_OUTPUT', 'service_name' => 'Tax Output', 'module_name' => 'TAX', 'description' => 'Records output tax generated from taxable sales transactions.', 'is_active' => true],
    ['service_code' => 'TAX_INPUT', 'service_name' => 'Tax Input', 'module_name' => 'TAX', 'description' => 'Records input tax generated from taxable purchases or expenses.', 'is_active' => true],
    ['service_code' => 'TAX_PAYMENT', 'service_name' => 'Tax Payment', 'module_name' => 'TAX', 'description' => 'Records settlement of tax liabilities to tax authorities.', 'is_active' => true],

    ['service_code' => 'MONTH_END_CLOSING', 'service_name' => 'Month End Closing', 'module_name' => 'CLOSING', 'description' => 'Records accounting adjustments and transfers required during monthly closing.', 'is_active' => true],
    ['service_code' => 'YEAR_END_CLOSING', 'service_name' => 'Year End Closing', 'module_name' => 'CLOSING', 'description' => 'Records year-end closing entries including retained earnings transfer.', 'is_active' => true],
];
