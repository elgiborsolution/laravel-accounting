# Public API Reference

This page documents the actual public API exposed by the package source.

## Facade API

### `Accounting`

File: [`src/Facades/Accounting.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Facades/Accounting.php)

The facade resolves the container alias `laravel-accounting`, which points to `AccountingService`.

#### `journal()`

- Signature: `Accounting::journal()`
- Return type: `ESolution\LaravelAccounting\Services\JournalService`
- Description: Resolves the journal service.
- Example:

```php
$journalService = Accounting::journal();
```

#### `coa()`

- Signature: `Accounting::coa()`
- Return type: `ESolution\LaravelAccounting\Services\CoaService`
- Description: Resolves the chart-of-accounts service.

#### `mapping()`

- Signature: `Accounting::mapping()`
- Return type: `ESolution\LaravelAccounting\Services\MappingService`
- Description: Resolves the service-account mapping service.

#### `closing()`

- Signature: `Accounting::closing()`
- Return type: `ESolution\LaravelAccounting\Services\ClosingService`
- Description: Resolves the closing service.

#### `report()`

- Signature: `Accounting::report()`
- Return type: `ESolution\LaravelAccounting\Services\ReportService`
- Description: Resolves the reporting service.

#### `service(string|AccountingServiceCode $service)`

- Signature: `Accounting::service(string|AccountingServiceCode $service)`
- Return type: `ESolution\LaravelAccounting\Models\Service|null`
- Description: Loads a service record from `acc_services` by service code.
- Example:

```php
use ESolution\LaravelAccounting\Enums\AccountingServiceCode;

$service = Accounting::service(AccountingServiceCode::SALES_CASH);
```

#### `catalog()`

- Signature: `Accounting::catalog()`
- Return type: `ESolution\LaravelAccounting\Support\ServiceCatalog`
- Description: Returns the default service catalog registry.

## Service Classes

### `AccountingService`

File: [`src/Services/AccountingService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/AccountingService.php)

- `journal()`
- `coa()`
- `mapping()`
- `closing()`
- `report()`
- `service(string|AccountingServiceCode $service)`
- `catalog()`

### `JournalService`

File: [`src/Services/JournalService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/JournalService.php)

- `journalByMapping(array $data)`
- `journalManual(array $data)`
- `reverse($journalId, string $reason)`
- `post($id)`

Behavior notes:

- `journalByMapping()` requires `service_code` and balanced items.
- `journalManual()` creates a balanced draft journal from arbitrary accounts.
- `reverse()` creates a brand-new reversal journal and does not edit the original posted journal.
- `post()` is idempotent for already-posted journals.

### `CoaService`

File: [`src/Services/CoaService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/CoaService.php)

- `createAccount(array $data)`
- `getTree()`
- `activateAccount($id)`
- `deactivateAccount($id)`

### `MappingService`

File: [`src/Services/MappingService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/MappingService.php)

- `findByKey(string $key)`
- `getByService($serviceId)`

### `ClosingService`

File: [`src/Services/ClosingService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/ClosingService.php)

- `closeMonth($year, $month, $userId = null)`
- `reopenMonth($year, $month, $userId = null)`

### `ReportService`

File: [`src/Services/ReportService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/ReportService.php)

- `generalLedger($accountId, $startDate, $endDate)`
- `trialBalance($year, $month)`
- `profitLoss($year, $month)`
- `balanceSheet($year, $month)`
- `cashFlow($year, $month)`

## Support Registries

### `ServiceCatalog`

File: [`src/Support/ServiceCatalog.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Support/ServiceCatalog.php)

Public methods:

- `all()`
- `sales()`
- `purchase()`
- `inventory()`
- `finance()`
- `expense()`
- `payroll()`
- `asset()`
- `receivable()`
- `payable()`
- `tax()`
- `closing()`
- `find(string|AccountingServiceCode $service)`
- `normalizeCode(string|AccountingServiceCode $service)`

### `ServiceAccountTemplateRegistry`

File: [`src/Support/ServiceAccountTemplateRegistry.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Support/ServiceAccountTemplateRegistry.php)

Public methods:

- `all()`
- `forService(string|AccountingServiceCode $serviceCode)`

## Controllers and API Endpoints

### Account Categories

- `GET /api/accounting/categories`
- `POST /api/accounting/categories`
- `GET /api/accounting/categories/{id}`
- `PUT /api/accounting/categories/{id}`
- `DELETE /api/accounting/categories/{id}`
- `PATCH /api/accounting/categories/{id}/toggle-status`

Controller methods:

- `index()`
- `store()`
- `show()`
- `update()`
- `destroy()`
- `toggleStatus()`

### Accounts

- `GET /api/accounting/accounts`
- `POST /api/accounting/accounts`
- `GET /api/accounting/accounts/{id}`
- `PUT /api/accounting/accounts/{id}`
- `DELETE /api/accounting/accounts/{id}`
- `PATCH /api/accounting/accounts/{id}/toggle-status`

Controller methods:

- `index()`
- `store()`
- `show()`
- `update()`
- `destroy()`
- `toggleStatus()`

### Services

- `GET /api/accounting/services`
- `POST /api/accounting/services`
- `GET /api/accounting/services/{id}`
- `PUT /api/accounting/services/{id}`
- `DELETE /api/accounting/services/{id}`
- `PATCH /api/accounting/services/{id}/toggle-status`

Controller methods:

- `index()`
- `store()`
- `show()`
- `update()`
- `destroy()`
- `toggleStatus()`

### Journals

- `GET /api/accounting/journals`
- `GET /api/accounting/journals/{id}`
- `POST /api/accounting/journals/{id}/reverse`

Controller methods:

- `index()`
- `show()`
- `reverse()`

### Reports

- `GET /api/accounting/reports/general-ledger`
- `GET /api/accounting/reports/trial-balance`
- `GET /api/accounting/reports/profit-loss`
- `GET /api/accounting/reports/balance-sheet`
- `GET /api/accounting/reports/cash-flow`

Controller methods:

- `generalLedger()`
- `trialBalance()`
- `profitLoss()`
- `balanceSheet()`
- `cashFlow()`

## Models

### `AccountCategory`

- `accounts()`

### `Account`

- `category()`
- `parent()`
- `children()`
- `mappings()`

### `Service`

- `accounts()`
- `mappings()`

### `ServiceAccount`

- `service()`
- `account()`
- `scopeActive()`

### `JournalEntry`

- `service()`
- `details()`
- `reversalOf()`
- `reversals()`
- `getTypeAttribute()`

### `JournalEntryDetail`

- `header()`
- `account()`

### `FiscalPeriod`

- No public relations or custom methods beyond `getTable()`

### `MonthlyBalance`

- `account()`

### `ReportMapping`

- `account()`

## Enums

### `AccountingServiceCode`

Default ERP service codes shipped by the package.

### `JournalStatus`

- `DRAFT`
- `POSTED`
- `REVERSED`

### `NormalBalance`

- `DEBIT`
- `CREDIT`

### `ReportType`

- `BALANCE_SHEET`
- `PROFIT_LOSS`
- `CASH_FLOW`

## Traits and Exceptions

### `ApiResponse`

Methods:

- `successResponse($message = null, $data = null, $code = 200)`
- `errorResponse($data, $code = 422, $message = null)`

### `HasUuid`

Methods:

- `getIncrementing()`
- `getKeyType()`

### `AccountingPeriodLockedException`

- Thrown when a journal is posted or reversed inside a closed fiscal period.

## What Does Not Exist Yet

These are not present in the source tree:

- Contracts / interfaces
- Repositories
- Action classes
- DTO classes
- Events
- Listeners
- Global helper functions

