# Public API Reference

This page documents the package surface area together with the normalized chart of accounts design used by the documentation baseline. The reporting hierarchy is defined by `acc_account_categories`, while `acc_accounts` contains only posting accounts.

## Technical Data Model

- `acc_account_categories` is hierarchical through `parent_id`.
- Root category `type` values are `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, and `EXPENSE`.
- `category_name` is custom.
- `acc_accounts` has no `parent_id` and no `level`.
- Every account must have `category_id`.
- Financial reports aggregate balances from posting accounts into the category tree.

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

- `journalByMapping()` resolves the service and mappings through repositories, requires `service_code`, and balanced items.
- `journalByMapping()` is safe for shared master database setups because it does not depend on cross-connection `whereHas()` or eager loading against master tables.
- `journalManual()` creates a balanced draft journal from arbitrary accounts.
- `reverse()` creates a brand-new reversal journal and does not edit the original posted journal.
- `post()` is idempotent for already-posted journals.

### `CoaService`

File: [`src/Services/CoaService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/CoaService.php)

- `createAccount(array $data)`
- `getTree()`
- `activateAccount($id)`
- `deactivateAccount($id)`

Behavior note:

- `getTree()` is documented as returning the category tree with posting accounts grouped under their categories.

### `MappingService`

File: [`src/Services/MappingService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/MappingService.php)

- `findByKey(string $key)`
- `getByService($serviceId)`

### `ClosingService`

File: [`src/Services/ClosingService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/ClosingService.php)

- `closeMonth($year, $month, $userId = null)`
- `closeThroughCurrentMonth($userId = null)`
- `closeUntilCurrentMonth($userId = null)`
- `reopenMonth($year, $month, $userId = null)`

### `FiscalPeriodService`

File: [`src/Services/FiscalPeriodService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/FiscalPeriodService.php)

- `ensureForDate($date)`
- `ensureThroughCurrentMonth(?Carbon $fromDate = null)`
- `ensureForJournalDate($date)`

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

#### Query parameters

`GET /api/accounting/categories`

- `with` `string|array` `optional`
- Supported values: `children`, `accounts`
- Examples: `with=children`, `with=accounts`, `with=children,accounts`, `with[]=children`, `with[]=accounts`
- Description: when present, the response includes only the requested relations. Without this parameter, no relations are serialized.
- `parent_id` `uuid` `optional`
- Description: when present, only categories whose `parent_id` matches the provided value are returned. The category with `id = parent_id` itself is excluded.
- `root_only` `boolean` `optional`
- Default: `false`
- Description: when `true`, the response returns only root categories where `parent_id` is `null`.

Example:

```http
GET /api/accounting/categories?root_only=true&with=children,accounts
```

#### Create category

`POST /api/accounting/categories`

Request body parameters:

- `parent_id` `uuid` `optional`
- `type` `string` `required`
- Allowed values: `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, `EXPENSE`
- Lowercase values are accepted and normalized to uppercase
- `category_code` `string` `required`
- Max length: `50`
- Must be unique
- `category_name` `string` `required`
- Max length: `100`
- `report_type` `string` `optional`
- Max length: `50`
- If omitted, the controller defaults to:
  - `BS` for `ASSET`, `LIABILITY`, `EQUITY`
  - `PL` for `REVENUE`, `EXPENSE`
- `sequence_no` `integer` `optional`
- `status` `boolean` `optional`

Example:

```json
{
  "parent_id": null,
  "type": "ASSET",
  "category_code": "CASH_CASH_EQUIVALENT",
  "category_name": "Cash & Cash Equivalent",
  "report_type": "BS",
  "sequence_no": 1,
  "status": true
}
```

#### Get category detail

`GET /api/accounting/categories/{id}`

Path parameters:

- `id` `uuid` `required`

The endpoint returns the selected category with its tree node structure.

#### Update category

`PUT /api/accounting/categories/{id}`

Path parameters:

- `id` `uuid` `required`

Request body parameters:

- `parent_id` `uuid` `optional`
- `type` `string` `optional`
- Allowed values are the same as create
- `category_code` `string` `optional`
- Max length: `50`
- Must be unique except for the current record
- `category_name` `string` `optional`
- Max length: `100`
- `report_type` `string` `optional`
- Max length: `50`
- `sequence_no` `integer` `optional`
- `status` `boolean` `optional`

If `type` is provided, it is normalized to uppercase. If `report_type` is omitted, the controller infers it from `type` or the existing record.

#### Delete category

`DELETE /api/accounting/categories/{id}`

Path parameters:

- `id` `uuid` `required`

The endpoint rejects deletion when the category still has descendants or linked accounts.

#### Toggle category status

`PATCH /api/accounting/categories/{id}/toggle-status`

Path parameters:

- `id` `uuid` `required`

This endpoint does not require a request body. It flips the `status` value.

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

- `parent()`
- `children()`
- `accounts()`

### `Account`

- `category()`
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

Design note:

- report mappings are not the primary statement hierarchy in this documentation baseline; standard reports are category-tree driven

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

