# Laravel Accounting Package

`elgibor-solution/laravel-accounting` is a Laravel 11/12 accounting package that provides chart-of-accounts management, journal entry storage, service-to-account mappings, monthly closing, and financial reporting.

## Overview

The package is built around a small set of accounting entities:

- Account categories
- Accounts
- Business services and account mappings
- Journal entries and journal entry details
- Fiscal periods
- Monthly balances
- Report mappings

The package also ships with API controllers, reusable services, seeders, a factory, and package migrations. Tables are prefixed by default with `acc_`.

## Features

- Chart of accounts with hierarchical parent/child accounts
- Account category management with status toggling
- Business service definitions with debit/credit mappings
- Journal storage with validation and auto-posting
- Journal number generation using a configurable format
- Fiscal period locking to prevent posting into closed periods
- Monthly closing and reopening
- General ledger, trial balance, profit & loss, balance sheet, and cash flow reports
- Tenant-aware routes when a `{tenantId}` segment is used

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
php artisan migrate
```

5. Seed the default chart of accounts and account categories.

```bash
php artisan db:seed --class="ESolution\\LaravelAccounting\\Database\\Seeders\\AccountingSeeder"
```

The service provider also loads the package migrations automatically when Laravel is running in console.

## Configuration

The package configuration lives in [`config/accounting.php`](./config/accounting.php).

### Available options

```php
return [
    'table_prefix' => 'acc_',
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
- `journal.auto_post` determines whether a created journal is immediately posted.
- `journal.number_format` is used by `JournalService::generateJournalNo()` to build the journal number.
- `fiscal.start_month` is present for fiscal-year configuration.
- `route.prefix` and `route.middleware` control package API routing.

## Usage

### Resolve the high-level service

[`src/Services/AccountingService.php`](./src/Services/AccountingService.php) is a convenience wrapper that exposes the package services:

```php
use ESolution\LaravelAccounting\Services\AccountingService;

$accounting = app(AccountingService::class);

$journalService = $accounting->journal();
$coaService = $accounting->coa();
$mappingService = $accounting->mapping();
$closingService = $accounting->closing();
$reportService = $accounting->report();
```

### Create a manual journal

[`JournalService::journalManual()`](./src/Services/JournalService.php) accepts balanced debit/credit lines and creates a journal entry with details.

```php
use ESolution\LaravelAccounting\Services\JournalService;

$journal = app(JournalService::class)->journalManual([
    'trx_date' => '2026-01-15',
    'description' => 'Office expense payment',
    'items' => [
        [
            'account_code' => '5100',
            'type' => 'D',
            'amount' => 150000,
            'description' => 'Office supplies',
        ],
        [
            'account_code' => '1000',
            'type' => 'K',
            'amount' => 150000,
            'description' => 'Cash payment',
        ],
    ],
]);
```

### Create a journal from a mapped service

[`JournalService::journalByMapping()`](./src/Services/JournalService.php) validates `service_code`, checks mapping keys, and can auto-post when enabled in configuration.

```php
use ESolution\LaravelAccounting\Services\JournalService;

$journal = app(JournalService::class)->journalByMapping([
    'service_code' => 'SALE_INVOICE',
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

[`CoaService::getTree()`](./src/Services/CoaService.php) returns categories with top-level accounts and nested children.

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

### Generate reports

[`ReportService`](./src/Services/ReportService.php) provides report methods that are also exposed through the API.

```php
use ESolution\LaravelAccounting\Services\ReportService;

$reportService = app(ReportService::class);

$generalLedger = $reportService->generalLedger($accountId, '2026-01-01', '2026-01-31');
$trialBalance = $reportService->trialBalance(2026, 1);
$profitLoss = $reportService->profitLoss(2026, 1);
$balanceSheet = $reportService->balanceSheet(2026, 1);
$cashFlow = $reportService->cashFlow(2026, 1);
```

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
    "account": ["Cannot delete account with children"]
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
  "category_id": "b3d2f1d0-3a8b-4b42-9d18-3b3e7d1f2f11",
  "code": "1002",
  "name": "Bank BCA",
  "status": true
}
```

Sample response:

```json
{
  "status": 201,
  "message": "Account created successfully",
  "data": {
    "id": "6c3c8b8d-8c3f-4e5c-a8ea-2d8d3ef2f4f1",
    "category_id": "b3d2f1d0-3a8b-4b42-9d18-3b3e7d1f2f11",
    "code": "1002",
    "name": "Bank BCA",
    "status": true
  }
}
```

#### List accounts

`GET /api/accounting/accounts?search=1001`

The controller loads the related category in the cached result.

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
      "account_id": "UUID-OF-ACCOUNT"
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

`GET /api/accounting/reports/general-ledger?account_id={uuid}&start_date=2026-01-01&end_date=2026-01-31`

Request validation requires:

- `account_id` as a UUID
- `start_date` as a date
- `end_date` as a date that is on or after `start_date`

Sample response shape:

```json
{
  "status": 200,
  "message": "General Ledger retrieved successfully",
  "data": {
    "account": {
      "id": "6c3c8b8d-8c3f-4e5c-a8ea-2d8d3ef2f4f1",
      "code": "1000",
      "name": "Kas"
    },
    "opening_balance": 0,
    "details": []
  }
}
```

#### Trial balance

`GET /api/accounting/reports/trial-balance?year=2026&month=1`

#### Profit & loss

`GET /api/accounting/reports/profit-loss?year=2026&month=1`

#### Balance sheet

`GET /api/accounting/reports/balance-sheet?year=2026&month=1`

#### Cash flow

`GET /api/accounting/reports/cash-flow?year=2026&month=1`

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
- [`ClosingService::reopenMonth($year, $month, $userId = null)`](./src/Services/ClosingService.php)

## Data Flow

1. A business event is transformed into a journal either manually or through a service mapping.
2. `JournalService` validates that every entry is balanced and that the fiscal period is not closed.
3. A journal header is created in `acc_journal_entries`, with detail lines stored in `acc_journal_entry_details`.
4. If `accounting.journal.auto_post` is enabled, the journal is immediately marked as `posted`.
5. `ClosingService` aggregates posted journal activity into `acc_monthly_balances` for each account.
6. `ReportService` reads `acc_monthly_balances`, `acc_report_mappings`, and posted journal details to generate reports.

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

All package models use UUID primary keys through the shared `HasUuid` trait.

## Notes for Integrators

- The package uses cache tags for accounts, categories, services, and journals.
- `AccountingPeriodLockedException` is thrown when posting into a closed fiscal period.
- Route handlers support an optional tenant context when the path includes `{tenantId}` and a `tenancy()` helper is available.
- The service layer currently shows a naming mismatch between controller/request fields (`status`, `mappings`) and the `Service` model/schema (`is_active`, `accounts()`), so confirm service create/update behavior against the live app before depending on it.
- The codebase currently includes only one feature test file for accounts; additional coverage for journals, reports, and closing would be a useful follow-up.
