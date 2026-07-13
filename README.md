# Laravel Accounting Package

`elgibor-solution/laravel-accounting` is a Laravel 11/12 accounting package that provides category-tree based chart-of-accounts management, journal entry storage, service-to-account mappings, monthly closing, and financial reporting.

For the full technical reference, start with [`docs/README.md`](./docs/README.md). It documents the normalized chart-of-accounts design, architecture, public APIs, services, journal engine, mapping engine, and extension points.

## Overview

The package is built around a small set of accounting entities:

- Account categories as a reporting tree
- Posting accounts
- Business services and account mappings
- Journal entries and journal entry details
- Fiscal periods
- Monthly balances

The package also ships with API controllers, reusable services, seeders, a factory, and package migrations. Tables are prefixed by default with `acc_`.

## Features

- Chart of accounts with hierarchical account categories and leaf posting accounts
- Account category management with status toggling
- Business service definitions with debit/credit mappings
- Journal storage with validation and auto-posting
- Journal number generation using a configurable format
- Fiscal period locking to prevent posting into closed periods
- Monthly closing and reopening
- General ledger, trial balance, profit & loss, balance sheet, and cash flow reports aggregated through the category tree
- Tenant-aware routes when a `{tenantId}` segment is used

## Technical Design Summary

This documentation uses a normalized accounting design:

- `acc_account_categories` owns the hierarchy through `parent_id`.
- Root category `type` values are `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, and `EXPENSE`.
- `category_name` is fully custom.
- `acc_accounts` contains posting accounts only.
- `acc_accounts.parent_id` and `acc_accounts.level` are removed from the design.
- Every account must have `category_id`.
- Financial reports aggregate posting balances through the category tree, not through account hierarchy.

Example category tree:

```text
ASSET
`-- Current Asset
    |-- Cash & Cash Equivalent
    |-- Account Receivable
    |-- Inventory
    `-- Prepaid Expense

ASSET
`-- Fixed Asset
    |-- Land
    |-- Building
    |-- Vehicle
    `-- Equipment

REVENUE
`-- Sales Revenue

EXPENSE
`-- Operating Expense
    |-- Salary Expense
    |-- Electricity Expense
    `-- Water Expense
```

Relationship diagram:

```text
acc_account_categories (Tree)
        |
        |-- parent_id -> acc_account_categories.id
        |
        `-- 1 : N
             |
             v
        acc_accounts
             |
             v
   acc_journal_entry_details
             |
             v
      acc_monthly_balances
             |
             v
      Financial Reports
```

### What is not in this package

The current codebase does not include invoice, tax, AR/AP, or payment controllers/routes. Those concepts may exist in a larger application, but they are not implemented here.

## Installation

1. Require the package in your Laravel application.

```bash
composer require elgibor-solution/laravel-accounting
```

2. Publish the configuration file if you want to customize defaults.

```bash
php artisan vendor:publish --tag=accounting-config
```

3. Publish the package migrations if you want them in your application database folder.

```bash
php artisan vendor:publish --tag=accounting-migrations
```

4. Run migrations.

```bash
php artisan migrate --path database/migrations/accounting
```

5. Seed the default chart of accounts, account categories, and ERP accounting services.

```bash
php artisan db:seed --class="ESolution\LaravelAccounting\Database\Seeders\AccountingSeeder"
```

The service provider also loads the package migrations automatically when Laravel is running in console.

`AccountingSeeder` now includes the default ERP accounting service catalog, so a fresh installation starts with standard transaction services in `acc_services`.

## Configuration

The package configuration lives in [`config/accounting.php`](./config/accounting.php).

### Available options

```php
return [
    'table_prefix' => 'acc_',
    'master_data' => [
        'use_shared_database' => env('ACCOUNTING_USE_SHARED_DATABASE', false),
        'connection' => env('ACCOUNTING_MASTER_CONNECTION'),
    ],
    'journal' => [
        'auto_post' => true,
        'number_format' => 'JV/{YEAR}/{MONTH}/{SEQ}',
    ],
    'fiscal' => [
        'start_month' => 1,
    ],
    'route' => [
        'prefix' => 'api/accounting',
        'middleware' => ['api'],
    ],
];
```

- `table_prefix` controls every package table name.
- `master_data.use_shared_database` enables a shared master database for `acc_account_categories`, `acc_accounts`, `acc_services`, and `acc_service_accounts`.
- `master_data.connection` points to the master database connection name used when shared master mode is enabled.
- `journal.auto_post` determines whether a created journal is immediately posted.
- `journal.number_format` is used by `JournalService::generateJournalNo()` to build the journal number.
- `fiscal.start_month` is present for fiscal-year configuration.
- `route.prefix` and `route.middleware` control package API routing.

Shared master mode does not change the public API. When it is disabled, the package keeps using the active application or tenant connection exactly as before.

## Usage

### Resolve the high-level service

[`src/Services/AccountingService.php`](./src/Services/AccountingService.php) is a convenience wrapper that exposes the package services:

```php
use ESolution\LaravelAccounting\Facades\Accounting;
use ESolution\LaravelAccounting\Services\AccountingService;

$accounting = app(AccountingService::class);

$journalService = $accounting->journal();
$coaService = $accounting->coa();
$mappingService = $accounting->mapping();
$closingService = $accounting->closing();
$reportService = $accounting->report();

$salesCashService = Accounting::service('SALES_CASH');
$catalog = Accounting::catalog();
```

### Use enum-backed service codes

The package now ships with an enum for standard accounting service codes so package logic can avoid hardcoded strings.

```php
use ESolution\LaravelAccounting\Enums\AccountingServiceCode;
use ESolution\LaravelAccounting\Facades\Accounting;

$service = Accounting::service(AccountingServiceCode::SALES_CASH);
$salesServices = Accounting::catalog()->sales();
```

### Create a manual journal

[`JournalService::journalManual()`](./src/Services/JournalService.php) accepts balanced debit/credit lines, validates account state and fiscal period, and creates a posted journal entry with details.

```php
use ESolution\LaravelAccounting\Services\JournalService;

$journal = app(JournalService::class)->journalManual([
    'trx_date' => '2026-01-15',
    'reference_no' => 'JU-20260115-0001',
    'description' => 'Office expense payment',
    'details' => [
        [
            'account_id' => 'uuid-account-1',
            'type' => 'D',
            'amount' => 150000,
            'description' => 'Office supplies',
        ],
        [
            'account_id' => 'uuid-account-2',
            'type' => 'K',
            'amount' => 150000,
            'description' => 'Cash payment',
        ],
    ],
]);
```

### Create a journal from a mapped service

[`JournalService::journalByMapping()`](./src/Services/JournalService.php) resolves the service and its mappings through repositories, validates `service_code`, checks mapping keys, and can auto-post when enabled in configuration. The method does not rely on cross-connection eager loading or `whereHas()` against master tables.

```php
use ESolution\LaravelAccounting\Enums\AccountingServiceCode;
use ESolution\LaravelAccounting\Services\JournalService;

$journal = app(JournalService::class)->journalByMapping([
    'service_code' => AccountingServiceCode::SALES_CREDIT,
    'trx_date' => '2026-01-15',
    'reference_no' => 'INV-0001',
    'description' => 'Sales invoice INV-0001',
    'items' => [
        [
            'mapping_key' => 'cash_debit',
            'amount' => 1000000,
        ],
        [
            'mapping_key' => 'revenue_credit',
            'amount' => 1000000,
        ],
    ],
]);
```

### Read the chart of accounts tree

[`CoaService::getTree()`](./src/Services/CoaService.php) is documented as returning the category tree, with posting accounts grouped under their assigned categories. Report hierarchy lives in categories, not in accounts.

```php
use ESolution\LaravelAccounting\Services\CoaService;

$tree = app(CoaService::class)->getTree();
```

### Close a month

[`ClosingService::closeMonth()`](./src/Services/ClosingService.php) validates the fiscal period, checks journal balance, builds monthly balances, and marks the period as closed.

```php
use ESolution\LaravelAccounting\Services\ClosingService;

app(ClosingService::class)->closeMonth(2026, 1, auth()->id());
```

### Close through current month

[`ClosingService::closeThroughCurrentMonth()`](./src/Services/ClosingService.php) closes every open fiscal period up to the current month and returns the closed periods.

```php
use ESolution\LaravelAccounting\Services\ClosingService;

$closedPeriods = app(ClosingService::class)->closeThroughCurrentMonth(auth()->id());
```

If a fiscal period does not exist yet, the package now creates it automatically from the earliest journal date through the current month before closing.

### Generate reports

[`ReportService`](./src/Services/ReportService.php) provides report methods that are also exposed through the API. General Ledger stays account-based for detail lookup, while Trial Balance, Profit Loss, Balance Sheet, and Cash Flow are documented as category-tree driven reports.

```php
use ESolution\LaravelAccounting\Services\ReportService;

$reportService = app(ReportService::class);

$generalLedger = $reportService->generalLedger($accountId, '2026-01-01', '2026-01-31');
$trialBalance = $reportService->trialBalance(2026, 1);
$profitLoss = $reportService->profitLoss(2026, 1);
$balanceSheet = $reportService->balanceSheet(2026, 1);
$cashFlow = $reportService->cashFlow(2026, 1);
```

## Default ERP Accounting Services

`DefaultAccountingServicesSeeder` seeds `acc_services` with a standard ERP-ready service catalog. The seeder is idempotent and uses `updateOrCreate()` so rerunning seeds updates definitions without creating duplicates.

### Module grouping

| Module | Service Codes |
| --- | --- |
| SALES | `SALES_CASH`, `SALES_CASH_VAT`, `SALES_CREDIT`, `SALES_CREDIT_VAT`, `SALES_RETURN`, `SALES_DISCOUNT`, `SALES_WRITE_OFF` |
| PURCHASE | `PURCHASE_CASH`, `PURCHASE_CREDIT`, `PURCHASE_RETURN` |
| INVENTORY | `STOCK_OPENING`, `STOCK_ADJUSTMENT_PLUS`, `STOCK_ADJUSTMENT_MINUS`, `STOCK_TRANSFER`, `STOCK_OPNAME_GAIN`, `STOCK_OPNAME_LOSS` |
| FINANCE | `CASH_IN`, `CASH_OUT`, `BANK_TRANSFER`, `JOURNAL_MANUAL`, `PETTY_CASH` |
| EXPENSE | `EXPENSE`, `PREPAID_EXPENSE` |
| PAYROLL | `PAYROLL`, `PAYROLL_ACCRUAL` |
| ASSET | `ASSET_PURCHASE`, `ASSET_DEPRECIATION`, `ASSET_DISPOSAL`, `ASSET_REVALUATION` |
| ACCOUNT_RECEIVABLE | `CUSTOMER_RECEIVABLE_PAYMENT`, `CUSTOMER_RECEIVABLE_WRITE_OFF` |
| ACCOUNT_PAYABLE | `VENDOR_PAYMENT`, `VENDOR_PAYABLE_WRITE_OFF` |
| TAX | `TAX_OUTPUT`, `TAX_INPUT`, `VAT_PAYMENT`, `TAX_PAYMENT` |
| CLOSING | `MONTH_END_CLOSING`, `YEAR_END_CLOSING` |

### Purpose of each service

| Service Code | Purpose |
| --- | --- |
| `SALES_CASH` | Cash sales recognized immediately without creating receivables. |
| `SALES_CASH_VAT` | Cash sales with output VAT recognition and VAT payable. |
| `SALES_CREDIT` | Sales invoices that create customer receivables. |
| `SALES_CREDIT_VAT` | Credit sales with output VAT recognition and VAT payable. |
| `SALES_RETURN` | Reversals for goods returned by customers. |
| `SALES_DISCOUNT` | Discounts granted on sales transactions. |
| `SALES_WRITE_OFF` | Sales-related balances written off after approval. |
| `PURCHASE_CASH` | Purchases paid immediately by cash or bank. |
| `PURCHASE_CREDIT` | Purchases that create vendor payables. |
| `PURCHASE_RETURN` | Returns of purchased goods to vendors. |
| `STOCK_OPENING` | Initial inventory balance recognition. |
| `STOCK_ADJUSTMENT_PLUS` | Positive inventory adjustments. |
| `STOCK_ADJUSTMENT_MINUS` | Negative inventory adjustments. |
| `STOCK_TRANSFER` | Inventory movement between locations. |
| `STOCK_OPNAME_GAIN` | Surplus stock found during stock count. |
| `STOCK_OPNAME_LOSS` | Missing stock found during stock count. |
| `CASH_IN` | General non-sales cash receipts. |
| `CASH_OUT` | General non-purchase cash disbursements. |
| `BANK_TRANSFER` | Transfers between cash and bank accounts. |
| `JOURNAL_MANUAL` | Manual accounting adjustments entered by finance users. |
| `PETTY_CASH` | Petty cash funding, usage, and replenishment. |
| `EXPENSE` | Standard operating expense recognition. |
| `PREPAID_EXPENSE` | Prepaid expense acquisition and amortization flows. |
| `PAYROLL` | Payroll payment transactions. |
| `PAYROLL_ACCRUAL` | Payroll accrual and liability recognition. |
| `ASSET_PURCHASE` | Fixed asset acquisitions and capitalization. |
| `ASSET_DEPRECIATION` | Periodic depreciation entries. |
| `ASSET_DISPOSAL` | Disposal or retirement of fixed assets. |
| `ASSET_REVALUATION` | Approved asset revaluation entries. |
| `CUSTOMER_RECEIVABLE_PAYMENT` | Customer payments against receivables. |
| `CUSTOMER_RECEIVABLE_WRITE_OFF` | Write-off of uncollectible receivables. |
| `VENDOR_PAYMENT` | Vendor payments against payables. |
| `VENDOR_PAYABLE_WRITE_OFF` | Write-off of payable balances after reconciliation. |
| `TAX_OUTPUT` | Output tax recognition from taxable sales. |
| `TAX_INPUT` | Input tax recognition from taxable purchases or expenses. |
| `VAT_PAYMENT` | Settlement of output VAT payable to the tax authority. |
| `TAX_PAYMENT` | Settlement of tax liabilities. |
| `MONTH_END_CLOSING` | Month-end adjustment and closing entries. |
| `YEAR_END_CLOSING` | Year-end closing and retained earnings transfer. |

### Service catalog registry

[`ServiceCatalog`](./src/Support/ServiceCatalog.php) is the central registry for default services. It groups services by module through `all()`, `sales()`, `purchase()`, `inventory()`, `finance()`, `expense()`, `payroll()`, `asset()`, `receivable()`, `payable()`, `tax()`, and `closing()`. The sales module now includes VAT-aware entries, and the tax module includes a dedicated VAT payment service.

## Default ERP Journal Templates

`DefaultServiceAccountMappingsSeeder` seeds `acc_service_accounts` with production-ready journal templates for the default ERP services. The seeder resolves `account_id` from seeded `account_code`, never hardcodes account IDs, and uses `updateOrCreate()` for idempotent installs and upgrades. In shared master mode, the seeders write master data through `ACCOUNTING_MASTER_CONNECTION`; otherwise they use the active application or tenant connection.

### What `acc_service_accounts` does

- Journal template registry
- Account mapping engine
- Auto journal generator blueprint
- Dynamic account resolver
- Validation layer for required mapping keys

### Default category and posting account examples

| Type | Category path | Example posting accounts |
| --- | --- | --- |
| `ASSET` | `Current Asset > Cash & Cash Equivalent` | `Kas`, `Bank BCA`, `Bank Mandiri` |
| `ASSET` | `Current Asset > Account Receivable` | `Piutang Dagang`, `Piutang Karyawan` |
| `ASSET` | `Current Asset > Inventory` | `Persediaan Barang Dagang`, `Inventory In Transit` |
| `ASSET` | `Current Asset > Prepaid Expense` | `Uang Muka Pembelian`, `Pajak Dibayar Dimuka` |
| `ASSET` | `Fixed Asset > Land / Building / Vehicle / Equipment` | `Tanah`, `Bangunan`, `Kendaraan`, `Peralatan Kantor` |
| `LIABILITY` | `Current Liability` | `Hutang Dagang`, `Hutang Pajak`, `Uang Muka Penjualan` |
| `EQUITY` | `Owner Equity` | `Modal Pemilik`, `Saldo Laba Tahun Berjalan` |
| `REVENUE` | `Sales Revenue` | `Penjualan Retail`, `Penjualan Online` |
| `EXPENSE` | `Operating Expense > Salary / Electricity / Water` | `Salary Expense`, `Electricity Expense`, `Water Expense` |

### Template registry

[`ServiceAccountTemplateRegistry`](./src/Support/ServiceAccountTemplateRegistry.php) centralizes the default mapping blueprints. Each template definition includes:

- `mapping_key`
- `mapping_name`
- `position`
- `account_code`
- `sequence_no`
- `is_dynamic`
- `is_required`
- `is_active`

### Default mapping matrix

| Service Code | Default Mappings | Dynamic |
| --- | --- | --- |
| `SALES_CASH` | `sales_cash_cash_d`, `sales_cash_sales_k`, `sales_cash_cogs_d`, `sales_cash_inventory_k` | Cash/Bank |
| `SALES_CASH_VAT` | `sales_cash_vat_cash_d`, `sales_cash_vat_sales_k`, `sales_cash_vat_vat_k`, `sales_cash_vat_cogs_d`, `sales_cash_vat_inventory_k` | Cash/Bank |
| `SALES_CREDIT` | `sales_credit_ar_d`, `sales_credit_sales_k`, `sales_credit_cogs_d`, `sales_credit_inventory_k` | No |
| `SALES_CREDIT_VAT` | `sales_credit_vat_ar_d`, `sales_credit_vat_sales_k`, `sales_credit_vat_vat_k`, `sales_credit_vat_cogs_d`, `sales_credit_vat_inventory_k` | No |
| `SALES_RETURN` | `sales_return_sales_return_d`, `sales_return_receivable_k`, `sales_return_inventory_d`, `sales_return_cogs_k` | Receivable/Cash |
| `SALES_DISCOUNT` | `sales_discount_discount_d`, `sales_discount_receivable_k` | Receivable/Cash |
| `SALES_WRITE_OFF` | `sales_writeoff_bad_debt_d`, `sales_writeoff_ar_k` | No |
| `PURCHASE_CASH` | `purchase_cash_inventory_d`, `purchase_cash_cash_k` | Cash/Bank |
| `PURCHASE_CREDIT` | `purchase_credit_inventory_d`, `purchase_credit_ap_k` | No |
| `PURCHASE_RETURN` | `purchase_return_ap_d`, `purchase_return_inventory_k` | No |
| `STOCK_OPENING` | `stock_opening_inventory_d`, `stock_opening_opening_balance_k` | No |
| `STOCK_ADJUSTMENT_PLUS` | `stock_adjustment_plus_inventory_d`, `stock_adjustment_plus_gain_k` | No |
| `STOCK_ADJUSTMENT_MINUS` | `stock_adjustment_minus_loss_d`, `stock_adjustment_minus_inventory_k` | No |
| `STOCK_TRANSFER` | No default journal mapping | Future customization |
| `STOCK_OPNAME_GAIN` | `stock_opname_gain_inventory_d`, `stock_opname_gain_gain_k` | No |
| `STOCK_OPNAME_LOSS` | `stock_opname_loss_loss_d`, `stock_opname_loss_inventory_k` | No |
| `CASH_IN` | `cash_in_cash_d`, `cash_in_other_income_k` | Cash/Bank |
| `CASH_OUT` | `cash_out_expense_d`, `cash_out_cash_k` | Expense Account, Cash/Bank |
| `BANK_TRANSFER` | `bank_transfer_destination_bank_d`, `bank_transfer_source_bank_k` | Source Bank, Destination Bank |
| `JOURNAL_MANUAL` | No default mapping | Manual journal engine |
| `PETTY_CASH` | `petty_cash_fund_d`, `petty_cash_cash_k` | Cash/Bank |
| `EXPENSE` | `expense_expense_d`, `expense_cash_k` | Expense Account, Cash/Bank |
| `PREPAID_EXPENSE` | `prepaid_expense_asset_d`, `prepaid_expense_cash_k`, `prepaid_expense_amortization_d`, `prepaid_expense_asset_k` | Expense Account on amortization, optional customization |
| `PAYROLL` | `payroll_salary_expense_d`, `payroll_cash_k` | Cash/Bank |
| `PAYROLL_ACCRUAL` | `payroll_accrual_expense_d`, `payroll_accrual_payable_k` | No |
| `ASSET_PURCHASE` | `asset_purchase_asset_d`, `asset_purchase_cash_k` | Asset Account, Cash/Bank/AP |
| `ASSET_DEPRECIATION` | `asset_depreciation_expense_d`, `asset_depreciation_accumulated_k` | No |
| `ASSET_DISPOSAL` | `asset_disposal_accumulated_d`, `asset_disposal_asset_k` | No |
| `ASSET_REVALUATION` | `asset_revaluation_asset_d`, `asset_revaluation_reserve_k` | No |
| `CUSTOMER_RECEIVABLE_PAYMENT` | `receivable_payment_cash_d`, `receivable_payment_ar_k` | Cash/Bank |
| `CUSTOMER_RECEIVABLE_WRITE_OFF` | `receivable_writeoff_bad_debt_d`, `receivable_writeoff_ar_k` | No |
| `VENDOR_PAYMENT` | `vendor_payment_ap_d`, `vendor_payment_cash_k` | Cash/Bank |
| `VENDOR_PAYABLE_WRITE_OFF` | `vendor_writeoff_ap_d`, `vendor_writeoff_income_k` | No |
| `TAX_OUTPUT` | `tax_output_receivable_d`, `tax_output_vat_k` | Cash/Receivable |
| `TAX_INPUT` | `tax_input_vat_d`, `tax_input_payable_k` | Cash/AP |
| `VAT_PAYMENT` | `vat_payment_vat_d`, `vat_payment_cash_k` | Cash/Bank |
| `TAX_PAYMENT` | `tax_payment_payable_d`, `tax_payment_cash_k` | Cash/Bank |
| `MONTH_END_CLOSING` | `month_closing_revenue_d`, `month_closing_income_summary_k`, `month_closing_income_summary_d`, `month_closing_expense_k` | Revenue Accounts, Expense Accounts |
| `YEAR_END_CLOSING` | `year_closing_income_summary_d`, `year_closing_retained_earnings_k` | No |

## API Reference

All endpoints return the package response format from [`ApiResponse`](./src/Traits/ApiResponse.php):

```json
{
  "status": 200,
  "message": "Accounts retrieved successfully",
  "data": []
}
```

Validation failures use the same wrapper:

```json
{
  "status": 422,
  "message": "Validation Error",
  "errors": {
    "category_id": ["The category id field is required."]
  },
  "data": null
}
```

### Base route

The package registers routes under the configured prefix:

- `GET|POST /api/accounting/...`
- Tenant-aware variants: `/{tenantId}/...` under the same prefix

The current route set is:

- `GET /api/accounting/categories`
- `POST /api/accounting/categories`
- `GET /api/accounting/categories/{id}`
- `PUT /api/accounting/categories/{id}`
- `DELETE /api/accounting/categories/{id}`
- `PATCH /api/accounting/categories/{id}/toggle-status`
- `GET /api/accounting/accounts`
- `POST /api/accounting/accounts`
- `GET /api/accounting/accounts/{id}`
- `PUT /api/accounting/accounts/{id}`
- `DELETE /api/accounting/accounts/{id}`
- `PATCH /api/accounting/accounts/{id}/toggle-status`
- `GET /api/accounting/services`
- `POST /api/accounting/services`
- `GET /api/accounting/services/{id}`
- `PUT /api/accounting/services/{id}`
- `DELETE /api/accounting/services/{id}`
- `PATCH /api/accounting/services/{id}/toggle-status`
- `GET /api/accounting/journals`
- `GET /api/accounting/journals/{id}`
- `GET /api/accounting/reports/general-ledger`
- `GET /api/accounting/reports/trial-balance`
- `GET /api/accounting/reports/profit-loss`
- `GET /api/accounting/reports/balance-sheet`
- `GET /api/accounting/reports/cash-flow`

### Accounts API

#### Create account

`POST /api/accounting/accounts`

Request body:

```json
{
  "category_id": 12,
  "code": "1002",
  "name": "Bank BCA",
  "is_postable": true,
  "is_active": true
}
```

Sample response:

```json
{
  "status": 201,
  "message": "Account created successfully",
  "data": {
    "id": 125,
    "category_id": 12,
    "code": "1002",
    "name": "Bank BCA",
    "is_postable": true,
    "is_active": true
  }
}
```

#### List accounts

`GET /api/accounting/accounts?search=1001`

The documented design assumes each returned account includes its related category because every posting account must belong to exactly one category.

### Services API

#### Create service with mappings

`POST /api/accounting/services`

Request body:

```json
{
  "service_code": "TEST_SERVICE",
  "service_name": "Test Service",
  "module_name": "TEST",
  "mappings": [
    {
      "mapping_key": "test_d",
      "mapping_name": "Test Debit",
      "position": "D",
      "account_id": 125
    },
    {
      "mapping_key": "test_k",
      "mapping_name": "Test Credit",
      "position": "K",
      "is_dynamic": true
    }
  ]
}
```

Sample response:

```json
{
  "status": 201,
  "message": "Service created successfully",
  "data": {
    "id": "a11d8b76-1d6a-4d4c-b2cb-7e4f8d8f67c2",
    "service_code": "TEST_SERVICE",
    "service_name": "Test Service",
    "module_name": "TEST",
    "mappings": []
  }
}
```

### Journal API

#### List journals

`GET /api/accounting/journals`

Query parameters supported by the controller:

- `page`
- `per_page`
- `search`
- `start_date`
- `end_date`
- `status`

#### Get journal detail

`GET /api/accounting/journals/{id}`

The controller returns the journal header with loaded `details.account` and `service` relations.

### Reports API

#### General ledger

`GET /api/accounting/reports/general-ledger?account_id={account_id}&start_date=2026-01-01&end_date=2026-01-31`

Request validation requires:

- `account_id` as a numeric account identifier
- `start_date` as a date
- `end_date` as a date that is on or after `start_date`

Sample response shape:

```json
{
  "status": 200,
  "message": "General Ledger retrieved successfully",
  "data": {
    "account": {
      "id": 101,
      "code": "1000",
      "name": "Kas",
      "category_path": ["ASSET", "Current Asset", "Cash & Cash Equivalent"]
    },
    "opening_balance": 0,
    "details": []
  }
}
```

#### Trial balance

`GET /api/accounting/reports/trial-balance?year=2026&month=1`

The report is structured by `acc_account_categories` and rolls balances from posting accounts into the category tree.

Example shape:

```json
{
  "data": [
    {
      "type": "ASSET",
      "category_name": "Current Asset",
      "balance": 175000000,
      "children": [
        {
          "category_name": "Cash & Cash Equivalent",
          "balance": 150000000,
          "accounts": [
            {"code": "1000", "name": "Kas", "balance": 10000000},
            {"code": "1010", "name": "Bank BCA", "balance": 90000000},
            {"code": "1011", "name": "Bank Mandiri", "balance": 50000000}
          ]
        }
      ]
    }
  ]
}
```

#### Profit & loss

`GET /api/accounting/reports/profit-loss?year=2026&month=1`

Example shape:

```json
{
  "data": {
    "revenue": [
      {
        "category_name": "Sales Revenue",
        "balance": 250000000,
        "accounts": [
          {"code": "4001", "name": "Penjualan Retail", "balance": 180000000},
          {"code": "4002", "name": "Penjualan Online", "balance": 70000000}
        ]
      }
    ],
    "expense": [
      {
        "category_name": "Operating Expense",
        "balance": 95000000,
        "children": [
          {"category_name": "Salary Expense", "balance": 60000000},
          {"category_name": "Electricity Expense", "balance": 20000000},
          {"category_name": "Water Expense", "balance": 15000000}
        ]
      }
    ],
    "net_profit": 155000000
  }
}
```

#### Balance sheet

`GET /api/accounting/reports/balance-sheet?year=2026&month=1`

Example shape:

```json
{
  "data": {
    "asset": [
      {"category_name": "Current Asset", "balance": 175000000},
      {"category_name": "Fixed Asset", "balance": 320000000}
    ],
    "liability": [
      {"category_name": "Current Liability", "balance": 85000000}
    ],
    "equity": [
      {"category_name": "Owner Equity", "balance": 410000000}
    ]
  }
}
```

#### Cash flow

`GET /api/accounting/reports/cash-flow?year=2026&month=1`

Example shape:

```json
{
  "data": {
    "operating": [
      {"category_path": ["REVENUE", "Sales Revenue"], "balance": 250000000},
      {"category_path": ["EXPENSE", "Operating Expense"], "balance": -95000000}
    ],
    "investing": [
      {"category_path": ["ASSET", "Fixed Asset"], "balance": -20000000}
    ],
    "financing": [
      {"category_path": ["LIABILITY", "Long Term Liability"], "balance": 40000000}
    ]
  }
}
```

### Internal service methods

These methods are available through the package but are not exposed as routes in the current codebase:

- [`JournalService::journalByMapping(array $data)`](./src/Services/JournalService.php)
- [`JournalService::journalManual(array $data)`](./src/Services/JournalService.php)
- [`JournalService::post($id)`](./src/Services/JournalService.php)
- [`CoaService::createAccount(array $data)`](./src/Services/CoaService.php)
- [`CoaService::getTree()`](./src/Services/CoaService.php)
- [`CoaService::activateAccount($id)`](./src/Services/CoaService.php)
- [`CoaService::deactivateAccount($id)`](./src/Services/CoaService.php)
- [`MappingService::findByKey(string $key)`](./src/Services/MappingService.php)
- [`MappingService::getByService($serviceId)`](./src/Services/MappingService.php)
- [`ClosingService::closeMonth($year, $month, $userId = null)`](./src/Services/ClosingService.php)
- [`ClosingService::closeThroughCurrentMonth($userId = null)`](./src/Services/ClosingService.php)
- [`ClosingService::closeUntilCurrentMonth($userId = null)`](./src/Services/ClosingService.php)
- [`ClosingService::reopenMonth($year, $month, $userId = null)`](./src/Services/ClosingService.php)

## Architecture Flow

```text
Business Module
    ->
Service Catalog
    ->
Account Mapping Engine
    ->
Journal Engine
    ->
Fiscal Period
    ->
Monthly Summary
    ->
Financial Reports
```

## Data Flow

1. A business module selects a standard service from the default `ServiceCatalog` or a custom service in `acc_services`.
2. The account mapping engine validates the selected service and loads its debit/credit mapping rules.
3. `JournalService` validates that every entry is balanced and that the fiscal period is not closed.
4. A journal header is created in `acc_journal_entries`, with detail lines stored in `acc_journal_entry_details`.
5. If `accounting.journal.auto_post` is enabled, the journal is immediately marked as `posted`.
6. `ClosingService` aggregates posted journal activity into `acc_monthly_balances` for each posting account.
7. `ReportService` reads posting-account balances and rolls them up through `acc_account_categories.parent_id` to generate category-tree based reports.

## Package Models

The package defines these main Eloquent models:

- [`AccountCategory`](./src/Models/AccountCategory.php)
- [`Account`](./src/Models/Account.php)
- [`Service`](./src/Models/Service.php)
- [`ServiceAccount`](./src/Models/ServiceAccount.php)
- [`JournalEntry`](./src/Models/JournalEntry.php)
- [`JournalEntryDetail`](./src/Models/JournalEntryDetail.php)
- [`FiscalPeriod`](./src/Models/FiscalPeriod.php)
- [`MonthlyBalance`](./src/Models/MonthlyBalance.php)
- [`ReportMapping`](./src/Models/ReportMapping.php)

In this documentation baseline, account hierarchy is modeled only in `AccountCategory`, while `Account` is treated as a posting leaf with mandatory `category_id`.

## Notes for Integrators

- The package uses cache tags for accounts, categories, services, and journals.
- `AccountingPeriodLockedException` is thrown when posting into a closed fiscal period.
- Route handlers support an optional tenant context when the path includes `{tenantId}` and a `tenancy()` helper is available.
- Master data lookup is repository-driven so the same service layer works for single database, multi database, and multi tenant setups.
- The service layer currently shows a naming mismatch between controller or request fields (`status`, `mappings`) and the `Service` model or schema (`is_active`, `accounts()`), so confirm service create or update behavior against the live app before depending on it.
- The codebase currently includes only one feature test file for accounts; additional coverage for journals, reports, and closing would be a useful follow-up.

