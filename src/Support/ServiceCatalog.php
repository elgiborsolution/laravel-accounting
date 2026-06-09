<?php

namespace ESolution\LaravelAccounting\Support;

use ESolution\LaravelAccounting\Enums\AccountingServiceCode;
use Illuminate\Support\Collection;

class ServiceCatalog
{
    public function all(): array
    {
        return array_merge(
            $this->sales(),
            $this->purchase(),
            $this->inventory(),
            $this->finance(),
            $this->expense(),
            $this->payroll(),
            $this->asset(),
            $this->receivable(),
            $this->payable(),
            $this->tax(),
            $this->closing(),
        );
    }

    public function sales(): array
    {
        return [
            $this->definition(AccountingServiceCode::SALES_CASH, 'Cash Sales', 'SALES', 'Records sales transactions that are paid immediately in cash or cash equivalent.'),
            $this->definition(AccountingServiceCode::SALES_CREDIT, 'Credit Sales', 'SALES', 'Records sales invoices that create customer receivables.'),
            $this->definition(AccountingServiceCode::SALES_RETURN, 'Sales Return', 'SALES', 'Records goods returned by customers and reverses related sales value.'),
            $this->definition(AccountingServiceCode::SALES_DISCOUNT, 'Sales Discount', 'SALES', 'Records discounts granted to customers on completed sales transactions.'),
            $this->definition(AccountingServiceCode::SALES_WRITE_OFF, 'Sales Write Off', 'SALES', 'Records sales-related balances that must be written off based on policy approval.'),
        ];
    }

    public function purchase(): array
    {
        return [
            $this->definition(AccountingServiceCode::PURCHASE_CASH, 'Cash Purchase', 'PURCHASE', 'Records purchases settled immediately using cash or bank funds.'),
            $this->definition(AccountingServiceCode::PURCHASE_CREDIT, 'Credit Purchase', 'PURCHASE', 'Records supplier purchases that create payable balances.'),
            $this->definition(AccountingServiceCode::PURCHASE_RETURN, 'Purchase Return', 'PURCHASE', 'Records returned goods to vendors and reverses related purchase value.'),
        ];
    }

    public function inventory(): array
    {
        return [
            $this->definition(AccountingServiceCode::STOCK_OPENING, 'Stock Opening', 'INVENTORY', 'Records beginning inventory balances during setup or opening period migration.'),
            $this->definition(AccountingServiceCode::STOCK_ADJUSTMENT_PLUS, 'Stock Adjustment Plus', 'INVENTORY', 'Records inventory increases from adjustments outside normal purchasing flow.'),
            $this->definition(AccountingServiceCode::STOCK_ADJUSTMENT_MINUS, 'Stock Adjustment Minus', 'INVENTORY', 'Records inventory decreases from adjustments outside normal sales flow.'),
            $this->definition(AccountingServiceCode::STOCK_TRANSFER, 'Stock Transfer', 'INVENTORY', 'Records inventory movements between warehouses, locations, or stock segments.'),
            $this->definition(AccountingServiceCode::STOCK_OPNAME_GAIN, 'Stock Opname Gain', 'INVENTORY', 'Records surplus inventory discovered during stock opname or stock count.'),
            $this->definition(AccountingServiceCode::STOCK_OPNAME_LOSS, 'Stock Opname Loss', 'INVENTORY', 'Records inventory shortages discovered during stock opname or stock count.'),
        ];
    }

    public function finance(): array
    {
        return [
            $this->definition(AccountingServiceCode::CASH_IN, 'Cash In', 'FINANCE', 'Records non-sales cash receipts such as miscellaneous income or owner funding.'),
            $this->definition(AccountingServiceCode::CASH_OUT, 'Cash Out', 'FINANCE', 'Records non-purchase cash disbursements such as operational payouts.'),
            $this->definition(AccountingServiceCode::BANK_TRANSFER, 'Bank Transfer', 'FINANCE', 'Records transfers between cash and bank accounts or between banks.'),
            $this->definition(AccountingServiceCode::JOURNAL_MANUAL, 'Manual Journal', 'FINANCE', 'Represents manual journal adjustments initiated by accounting staff.'),
            $this->definition(AccountingServiceCode::PETTY_CASH, 'Petty Cash', 'FINANCE', 'Records petty cash funding, usage, and replenishment transactions.'),
        ];
    }

    public function expense(): array
    {
        return [
            $this->definition(AccountingServiceCode::EXPENSE, 'Expense', 'EXPENSE', 'Records standard operating expense recognition transactions.'),
            $this->definition(AccountingServiceCode::PREPAID_EXPENSE, 'Prepaid Expense', 'EXPENSE', 'Records prepaid expense acquisition and amortization-related mappings.'),
        ];
    }

    public function payroll(): array
    {
        return [
            $this->definition(AccountingServiceCode::PAYROLL, 'Payroll Payment', 'PAYROLL', 'Records payroll disbursement transactions to employees and statutory parties.'),
            $this->definition(AccountingServiceCode::PAYROLL_ACCRUAL, 'Payroll Accrual', 'PAYROLL', 'Records payroll expenses and liabilities before settlement.'),
        ];
    }

    public function asset(): array
    {
        return [
            $this->definition(AccountingServiceCode::ASSET_PURCHASE, 'Asset Purchase', 'ASSET', 'Records acquisition of fixed assets and related capitalization.'),
            $this->definition(AccountingServiceCode::ASSET_DEPRECIATION, 'Asset Depreciation', 'ASSET', 'Records periodic depreciation expense and accumulated depreciation.'),
            $this->definition(AccountingServiceCode::ASSET_DISPOSAL, 'Asset Disposal', 'ASSET', 'Records sale, retirement, or disposal of fixed assets.'),
            $this->definition(AccountingServiceCode::ASSET_REVALUATION, 'Asset Revaluation', 'ASSET', 'Records approved upward or downward revaluation of asset carrying values.'),
        ];
    }

    public function receivable(): array
    {
        return [
            $this->definition(AccountingServiceCode::CUSTOMER_RECEIVABLE_PAYMENT, 'Customer Receivable Payment', 'ACCOUNT_RECEIVABLE', 'Records incoming payments against outstanding customer receivables.'),
            $this->definition(AccountingServiceCode::CUSTOMER_RECEIVABLE_WRITE_OFF, 'Customer Receivable Write Off', 'ACCOUNT_RECEIVABLE', 'Records customer receivables that are written off as uncollectible.'),
        ];
    }

    public function payable(): array
    {
        return [
            $this->definition(AccountingServiceCode::VENDOR_PAYMENT, 'Vendor Payment', 'ACCOUNT_PAYABLE', 'Records outgoing payments against vendor payable balances.'),
            $this->definition(AccountingServiceCode::VENDOR_PAYABLE_WRITE_OFF, 'Vendor Payable Write Off', 'ACCOUNT_PAYABLE', 'Records vendor payable balances written off after reconciliation or approval.'),
        ];
    }

    public function tax(): array
    {
        return [
            $this->definition(AccountingServiceCode::TAX_OUTPUT, 'Tax Output', 'TAX', 'Records output tax generated from taxable sales transactions.'),
            $this->definition(AccountingServiceCode::TAX_INPUT, 'Tax Input', 'TAX', 'Records input tax generated from taxable purchases or expenses.'),
            $this->definition(AccountingServiceCode::TAX_PAYMENT, 'Tax Payment', 'TAX', 'Records settlement of tax liabilities to tax authorities.'),
        ];
    }

    public function closing(): array
    {
        return [
            $this->definition(AccountingServiceCode::MONTH_END_CLOSING, 'Month End Closing', 'CLOSING', 'Records accounting adjustments and transfers required during monthly closing.'),
            $this->definition(AccountingServiceCode::YEAR_END_CLOSING, 'Year End Closing', 'CLOSING', 'Records year-end closing entries including retained earnings transfer.'),
        ];
    }

    public function find(string|AccountingServiceCode $service): ?array
    {
        return Collection::make($this->all())
            ->firstWhere('service_code', $this->normalizeCode($service));
    }

    public function normalizeCode(string|AccountingServiceCode $service): string
    {
        return $service instanceof AccountingServiceCode ? $service->value : $service;
    }

    protected function definition(
        AccountingServiceCode $serviceCode,
        string $serviceName,
        string $moduleName,
        string $description,
        bool $isActive = true,
    ): array {
        return [
            'service_code' => $serviceCode->value,
            'service_name' => $serviceName,
            'module_name' => $moduleName,
            'description' => $description,
            'is_active' => $isActive,
        ];
    }
}
