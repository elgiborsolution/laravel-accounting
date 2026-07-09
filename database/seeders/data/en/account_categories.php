<?php

return [
    ['type' => 'ASSET', 'category_code' => 'ASSET', 'category_name' => 'Asset', 'report_type' => 'BS', 'sequence_no' => 10, 'parent_code' => null],
    ['type' => 'LIABILITY', 'category_code' => 'LIABILITY', 'category_name' => 'Liability', 'report_type' => 'BS', 'sequence_no' => 20, 'parent_code' => null],
    ['type' => 'EQUITY', 'category_code' => 'EQUITY', 'category_name' => 'Equity', 'report_type' => 'BS', 'sequence_no' => 30, 'parent_code' => null],
    ['type' => 'REVENUE', 'category_code' => 'REVENUE', 'category_name' => 'Revenue', 'report_type' => 'PL', 'sequence_no' => 40, 'parent_code' => null],
    ['type' => 'EXPENSE', 'category_code' => 'EXPENSE', 'category_name' => 'Expense', 'report_type' => 'PL', 'sequence_no' => 50, 'parent_code' => null],

    ['type' => 'ASSET', 'category_code' => 'CURRENT_ASSET', 'category_name' => 'Current Asset', 'report_type' => 'BS', 'sequence_no' => 10, 'parent_code' => 'ASSET'],
    ['type' => 'ASSET', 'category_code' => 'FIXED_ASSET', 'category_name' => 'Fixed Asset', 'report_type' => 'BS', 'sequence_no' => 20, 'parent_code' => 'ASSET'],
    ['type' => 'ASSET', 'category_code' => 'OTHER_ASSET', 'category_name' => 'Other Asset', 'report_type' => 'BS', 'sequence_no' => 30, 'parent_code' => 'ASSET'],

    ['type' => 'LIABILITY', 'category_code' => 'CURRENT_LIABILITY', 'category_name' => 'Current Liability', 'report_type' => 'BS', 'sequence_no' => 10, 'parent_code' => 'LIABILITY'],
    ['type' => 'LIABILITY', 'category_code' => 'LONG_TERM_LIABILITY', 'category_name' => 'Long Term Liability', 'report_type' => 'BS', 'sequence_no' => 20, 'parent_code' => 'LIABILITY'],

    ['type' => 'REVENUE', 'category_code' => 'SALES_REVENUE', 'category_name' => 'Sales Revenue', 'report_type' => 'PL', 'sequence_no' => 10, 'parent_code' => 'REVENUE'],
    ['type' => 'REVENUE', 'category_code' => 'SERVICE_REVENUE', 'category_name' => 'Service Revenue', 'report_type' => 'PL', 'sequence_no' => 20, 'parent_code' => 'REVENUE'],
    ['type' => 'REVENUE', 'category_code' => 'OTHER_REVENUE', 'category_name' => 'Other Revenue', 'report_type' => 'PL', 'sequence_no' => 30, 'parent_code' => 'REVENUE'],

    ['type' => 'EXPENSE', 'category_code' => 'COST_OF_GOODS_SOLD', 'category_name' => 'Cost of Goods Sold', 'report_type' => 'PL', 'sequence_no' => 10, 'parent_code' => 'EXPENSE'],
    ['type' => 'EXPENSE', 'category_code' => 'OPERATING_EXPENSE', 'category_name' => 'Operating Expense', 'report_type' => 'PL', 'sequence_no' => 20, 'parent_code' => 'EXPENSE'],
    ['type' => 'EXPENSE', 'category_code' => 'OTHER_EXPENSE', 'category_name' => 'Other Expense', 'report_type' => 'PL', 'sequence_no' => 30, 'parent_code' => 'EXPENSE'],

    ['type' => 'ASSET', 'category_code' => 'CASH_CASH_EQUIVALENT', 'category_name' => 'Cash and Cash Equivalents', 'report_type' => 'BS', 'sequence_no' => 10, 'parent_code' => 'CURRENT_ASSET'],
    ['type' => 'ASSET', 'category_code' => 'ACCOUNT_RECEIVABLE', 'category_name' => 'Accounts Receivable', 'report_type' => 'BS', 'sequence_no' => 20, 'parent_code' => 'CURRENT_ASSET'],
    ['type' => 'ASSET', 'category_code' => 'INVENTORY', 'category_name' => 'Inventory', 'report_type' => 'BS', 'sequence_no' => 30, 'parent_code' => 'CURRENT_ASSET'],
    ['type' => 'ASSET', 'category_code' => 'PREPAID_EXPENSE', 'category_name' => 'Prepaid Expense', 'report_type' => 'BS', 'sequence_no' => 40, 'parent_code' => 'CURRENT_ASSET'],
];
