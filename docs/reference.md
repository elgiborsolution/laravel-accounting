# Public API Reference

This page documents the package surface area together with the normalized chart of accounts design used by the documentation baseline. The reporting hierarchy is defined by `acc_account_categories`, while `acc_accounts` contains only posting accounts.

## Technical Data Model

- `acc_account_categories` is hierarchical through `parent_id`.
- Root category `type` values are `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, and `EXPENSE`.
- `category_name` is custom.
- `acc_accounts` has no `parent_id` and no `level`.
- `acc_accounts.tenant_id` is optional and controls ownership visibility.
- `acc_accounts.description` is optional and stores free-form notes.
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
- `journalOpeningBalance(array $data)`
- `reverse($journalId, string $reason)`
- `post($id)`

Behavior notes:

- `journalByMapping()` resolves the service and mappings through repositories, requires `service_code`, and balanced items.
- `journalByMapping()` is safe for shared master database setups because it does not depend on cross-connection `whereHas()` or eager loading against master tables.
- `journalManual()` creates a balanced manual journal from arbitrary accounts, validates account state and fiscal period, and posts it immediately.
- `journalOpeningBalance()` creates one posted journal for opening balances, derives debit/credit placement from account category normal balance, and rejects duplicate opening-balance creation.
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

## HTTP API Reference

### API Rules

- Base prefix: `config('accounting.route.prefix', 'api/accounting')`
- Middleware: `config('accounting.route.middleware', ['api'])`
- Every endpoint is registered twice:
  - non-tenant form: `/api/accounting/...`
  - tenant-aware form: `/api/accounting/{tenantId}/...`
- Auth is not enforced by the package routes themselves. If the host app needs auth, add it through route middleware.
- Standard success responses use the package envelope:

```json
{
  "status": 200,
  "message": "OK",
  "data": {}
}
```

- Standard error responses from `errorResponse()` use:

```json
{
  "status": 422,
  "message": "Validation Error",
  "errors": {},
  "data": null
}
```

- Validation failures triggered by `$request->validate()` use Laravel's default JSON validation response.
- `GET /api/accounting/journals` is the only endpoint in this package that returns a raw Laravel paginator instead of the package response envelope.
- Master-data models (`categories`, `accounts`, `services`, `service_accounts`) may use the shared master connection when enabled.
- Transaction-data models (`journal_entries`, `journal_entry_details`, `fiscal_periods`, `monthly_balances`) always follow the active application connection.

### Endpoint Summary

| Controller | Method | Endpoint |
| --- | --- | --- |
| AccountCategoryController | GET | `/api/accounting/categories` |
| AccountCategoryController | POST | `/api/accounting/categories` |
| AccountCategoryController | GET | `/api/accounting/categories/{id}` |
| AccountCategoryController | PUT | `/api/accounting/categories/{id}` |
| AccountCategoryController | DELETE | `/api/accounting/categories/{id}` |
| AccountCategoryController | PATCH | `/api/accounting/categories/{id}/toggle-status` |
| AccountController | GET | `/api/accounting/accounts` |
| AccountController | POST | `/api/accounting/accounts` |
| AccountController | GET | `/api/accounting/accounts/{id}` |
| AccountController | PUT | `/api/accounting/accounts/{id}` |
| AccountController | DELETE | `/api/accounting/accounts/{id}` |
| AccountController | PATCH | `/api/accounting/accounts/{id}/toggle-status` |
| ServiceController | GET | `/api/accounting/services` |
| ServiceController | POST | `/api/accounting/services` |
| ServiceController | GET | `/api/accounting/services/{id}` |
| ServiceController | PUT | `/api/accounting/services/{id}` |
| ServiceController | DELETE | `/api/accounting/services/{id}` |
| ServiceController | PATCH | `/api/accounting/services/{id}/toggle-status` |
| JournalController | POST | `/api/accounting/opening-balances` |
| JournalController | GET | `/api/accounting/journals` |
| JournalController | POST | `/api/accounting/journals` |
| JournalController | GET | `/api/accounting/journals/{id}` |
| JournalController | POST | `/api/accounting/journals/{id}/reverse` |
| ReportController | GET | `/api/accounting/reports/general-ledger` |
| ReportController | GET | `/api/accounting/reports/trial-balance` |
| ReportController | GET | `/api/accounting/reports/profit-loss` |
| ReportController | GET | `/api/accounting/reports/balance-sheet` |
| ReportController | GET | `/api/accounting/reports/cash-flow` |

## Account Categories

### GET `/api/accounting/categories`

Description: returns category data only by default. No `children` or `accounts` relation is serialized unless explicitly requested.

Authentication: no package-level auth. Host app may add auth middleware.

Query parameters:

- `with` `string|array` optional
- Supported values: `children`, `accounts`, `balance`
- Accepted formats:
  - `with=children`
  - `with=accounts`
  - `with=balance`
  - `with=children,accounts`
  - `with=accounts,balance`
  - `with[]=children`
  - `with[]=accounts`
  - `with[]=balance`
- `root_only` `boolean` optional
- When `true`, only categories with `parent_id = null` are returned.
- `parent_id` `uuid|string` optional
- When provided, only child categories with `parent_id = {id}` are returned.
- The category with `id = {id}` itself is excluded.
- If `parent_id` is present, it takes precedence over `root_only`.
- `year` `integer` optional
- Used only when `with=balance` is requested. Defaults to current year.
- `month` `integer` optional
- Used only when `with=balance` is requested. Defaults to current month.

Response body:

- Default response uses `AccountCategoryResource` and includes:
  - `id`
  - `category_code`
  - `category_name`
  - `type`
  - `parent_id`
  - `sequence_no`
  - `is_active`
- When `with=children`, the controller returns a tree node payload with recursive `children`.
- When `with=accounts`, the controller attaches `accounts`.
- When `with=balance`, each category includes `balance`.
- When `with=accounts,balance`, each account inside `accounts` also includes `balance`.
- When both are requested, both relations are present.

Validation rules: none, this endpoint only reads query parameters.

Error response:

- `404` is not expected here unless the controller raises an unexpected model error.
- `500` may occur for unexpected runtime errors.

Example request:

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/categories?root_only=true&with=children,accounts' \
--header 'Accept: application/json'
```

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/categories?with=balance&year=2026&month=7' \
--header 'Accept: application/json'
```

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/categories?with=accounts,balance&year=2026&month=7' \
--header 'Accept: application/json'
```

Example response:

```json
{
  "status": 200,
  "message": "Account categories retrieved successfully",
  "data": [
    {
      "id": "uuid",
      "category_code": "ASSET",
      "category_name": "Asset",
      "type": "ASSET",
      "parent_id": null,
      "sequence_no": 1,
      "is_active": true,
      "balance": 12500000,
      "accounts": [
        {
          "id": "account-uuid",
          "category_id": "uuid",
          "code": "110001",
          "name": "Cash",
          "description": "Main cash account",
          "is_postable": true,
          "status": true,
          "balance": 1500000
        }
      ],
      "children": []
    }
  ]
}
```

Notes:

- `with=children` switches the endpoint into hierarchical/tree mode.
- This endpoint does not eager-load `accounts` unless `with` explicitly asks for it.
- When `with=accounts` is used, account visibility follows the resolved tenant context, not a query parameter.
- `with=balance` reuses the existing account balance service and loads account balances in bulk before aggregating category totals in memory.
- Category balance is calculated recursively from direct child accounts and descendant categories.
- Cache keys are parameter-aware, so `root_only`, `parent_id`, and `with` combinations are cached separately.

### POST `/api/accounting/categories`

Description: creates a new account category.

Request body:

- `parent_id` optional
- `type` required
- `category_code` required
- `category_name` required
- `report_type` optional
- `sequence_no` optional
- `status` optional

Validation rules:

- `parent_id` nullable and must exist in the category table
- `type` required and must be one of `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, `EXPENSE` or lowercase variants
- `category_code` required string max 50 and unique
- `category_name` required string max 100
- `report_type` nullable string max 50
- `sequence_no` nullable integer
- `status` nullable boolean

Notes:

- `type` is normalized to uppercase before save.
- If `report_type` is omitted, the controller defaults to `BS` for asset/liability/equity and `PL` for revenue/expense.

Example request:

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/categories' \
--header 'Accept: application/json' \
--header 'Content-Type: application/json' \
--data '{
  "parent_id": null,
  "type": "ASSET",
  "category_code": "CURRENT_ASSET",
  "category_name": "Current Asset",
  "sequence_no": 1,
  "status": true
}'
```

Example response:

```json
{
  "status": 201,
  "message": "Account category created successfully",
  "data": {
    "id": "uuid",
    "parent_id": null,
    "type": "ASSET",
    "category_code": "CURRENT_ASSET",
    "category_name": "Current Asset",
    "report_type": "BS",
    "sequence_no": 1,
    "status": true
  }
}
```

### GET `/api/accounting/categories/{id}`

Description: returns a single category as a tree node, including `path`, recursive `children`, and `accounts`.

Path parameters:

- `id` required

Response body:

- `id`
- `parent_id`
- `type`
- `category_code`
- `category_name`
- `report_type`
- `sequence_no`
- `status`
- `path`
- `children`
- `accounts`

Example response:

```json
{
  "status": 200,
  "message": "Account category retrieved successfully",
  "data": {
    "id": "uuid",
    "parent_id": null,
    "type": "ASSET",
    "category_code": "ASSET",
    "category_name": "Asset",
    "report_type": "BS",
    "sequence_no": 1,
    "status": true,
    "path": ["Asset"],
    "children": [],
    "accounts": []
  }
}
```

### PUT `/api/accounting/categories/{id}`

Description: updates an existing category.

Path parameters:

- `id` required

Request body:

- `parent_id` optional
- `type` optional
- `category_code` optional
- `category_name` optional
- `report_type` optional
- `sequence_no` optional
- `status` optional

Validation rules:

- `parent_id` nullable and must exist in the category table
- `type` nullable and must be one of the supported category types or lowercase variants
- `category_code` nullable string max 50 and unique except current record
- `category_name` nullable string max 100
- `report_type` nullable string max 50
- `sequence_no` nullable integer
- `status` nullable boolean

Notes:

- `type` is uppercased before save when supplied.
- If `report_type` is omitted, it is inferred from the provided or existing type.

Example response:

```json
{
  "status": 200,
  "message": "Account category updated successfully",
  "data": {
    "id": "uuid",
    "category_code": "CURRENT_ASSET",
    "category_name": "Current Asset",
    "type": "ASSET",
    "parent_id": null,
    "sequence_no": 1,
    "status": true
  }
}
```

### DELETE `/api/accounting/categories/{id}`

Description: deletes a category only when it has no descendants and no linked accounts.

Path parameters:

- `id` required

Error conditions:

- Returns `422` when the category still has child categories or accounts.

Example response:

```json
{
  "status": 200,
  "message": "Account category deleted successfully",
  "data": null
}
```

### PATCH `/api/accounting/categories/{id}/toggle-status`

Description: flips the `status` flag for a category.

Path parameters:

- `id` required

Request body: none.

Example response:

```json
{
  "status": 200,
  "message": "Account category status toggled successfully",
  "data": {
    "id": "uuid",
    "status": false
  }
}
```

## Accounts

### GET `/api/accounting/accounts`

Description: lists accounts without relations by default.

Query parameters:

- `search` optional
- Matches `code` or `name` using `LIKE`
- `category_id` optional
- When provided, only accounts in the specified category are returned
- `with` optional
- Supported values:
  - `category`
  - `tree_category`
  - `balance`
- `with=category` loads the direct category relation.
- `with=tree_category` loads the category lineage from root to the account's category.
- `with=balance` loads the account balance object.
- `with=category,balance` and `with=tree_category,balance` are supported.
- Any other value is ignored and no relation is included.
- `year` optional
- Used only when `with=balance` is requested.
- Default: current year
- `month` optional
- Used only when `with=balance` is requested.
- Default: current month

Response body:

- Success envelope with a collection of accounts
- Default response does not include `category` or `tree_category`
- When requested, `category`, `tree_category`, and/or `balance` are serialized on each account
- Each account item includes `description` when stored in the database
- Each account item includes `tenant_id`
- `balance` contains:
  - `opening_balance`
  - `total_debit`
  - `total_credit`
  - `ending_balance`

Tenant visibility:

- The endpoint resolves the current tenant from `X-Tenant`, existing tenant context, or the tenant-aware route.
- If no tenant is resolved, only central accounts are returned (`tenant_id IS NULL`).
- If a tenant is resolved, the endpoint returns central accounts plus accounts whose `tenant_id` matches the resolved tenant.
- If `category_id` is provided, the category filter is applied together with tenant visibility and search.

Example response:

```json
{
  "status": 200,
  "message": "Accounts retrieved successfully",
  "data": [
    {
      "id": "uuid",
      "category_id": "uuid",
      "tenant_id": null,
      "code": "1001",
      "name": "Cash",
      "description": "Kas operasional perusahaan.",
      "is_postable": true,
      "status": true
    }
  ]
}
```

Example with balance:

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/accounts?with=balance&year=2026&month=7' \
--header 'Accept: application/json'
```

```json
{
  "status": 200,
  "message": "Accounts retrieved successfully",
  "data": [
    {
      "id": "uuid",
      "category_id": "uuid",
      "code": "1001",
      "name": "Cash",
      "description": "Kas operasional perusahaan.",
      "is_postable": true,
      "status": true,
      "balance": {
        "opening_balance": 1000000,
        "total_debit": 500000,
        "total_credit": 250000,
        "ending_balance": 1250000
      }
    }
  ]
}
```

### POST `/api/accounting/accounts`

Description: creates a new account.

Request body:

- `category_id` required
- `tenant_id` optional
- `code` required
- `name` required
- `description` optional
- `is_postable` optional
- `status` optional

Validation rules:

- `category_id` required and must exist in the category table
- `tenant_id` nullable string max 100
- `code` required string max 30 and unique
- `name` required string max 200
- `description` nullable string
- `is_postable` nullable boolean
- `status` nullable boolean

Example response:

```json
{
  "status": 201,
  "message": "Account created successfully",
  "data": {
    "id": "uuid",
    "category_id": "uuid",
    "tenant_id": "tenant-a",
    "code": "1001",
    "name": "Cash",
    "description": "Kas operasional perusahaan.",
    "is_postable": true,
    "status": true
  }
}
```

### GET `/api/accounting/accounts/{id}`

Description: returns one account with its category relation.

Path parameters:

- `id` required

Tenant visibility:

- The endpoint resolves the current tenant from `X-Tenant`, existing tenant context, or the tenant-aware route.
- If the requested account is not visible under the resolved tenant, the endpoint returns `404`.

Example response:

```json
{
  "status": 200,
  "message": "Account retrieved successfully",
  "data": {
    "id": "uuid",
    "category_id": "uuid",
    "tenant_id": "tenant-a",
    "code": "1001",
    "name": "Cash",
    "description": "Kas operasional perusahaan.",
    "is_postable": true,
    "status": true,
    "category": {
      "id": "uuid",
      "category_name": "Current Asset"
    }
  }
}
```

### PUT `/api/accounting/accounts/{id}`

Description: updates an account.

Path parameters:

- `id` required

Request body:

- `category_id` optional
- `tenant_id` optional
- `code` optional
- `name` optional
- `description` optional
- `is_postable` optional
- `status` optional

Validation rules:

- `category_id` nullable and must exist in the category table
- `tenant_id` nullable string max 100
- `code` nullable string max 30 and unique except current record
- `name` nullable string max 200
- `description` nullable string
- `is_postable` nullable boolean
- `status` nullable boolean

Example response:

```json
{
  "status": 200,
  "message": "Account updated successfully",
  "data": {
    "id": "uuid",
    "tenant_id": "tenant-a",
    "code": "1001",
    "name": "Cash",
    "description": "Kas operasional perusahaan.",
    "status": true
  }
}
```

### DELETE `/api/accounting/accounts/{id}`

Description: deletes an account.

Path parameters:

- `id` required

Example response:

```json
{
  "status": 200,
  "message": "Account deleted successfully",
  "data": null
}
```

### PATCH `/api/accounting/accounts/{id}/toggle-status`

Description: flips the `status` flag for an account.

Path parameters:

- `id` required

Example response:

```json
{
  "status": 200,
  "message": "Account status toggled successfully",
  "data": {
    "id": "uuid",
    "status": false
  }
}
```

## Services

### GET `/api/accounting/services`

Description: returns all services and eagerly loads mappings plus mapped account data.

Query parameters: none.

Response body:

- Success envelope with a collection of services
- Each service includes `mappings`
- Each mapping includes its mapped `account` when present

Example response:

```json
{
  "status": 200,
  "message": "Services retrieved successfully",
  "data": [
    {
      "id": "uuid",
      "service_code": "SALES_CASH",
      "service_name": "Cash Sales",
      "module_name": "sales",
      "description": "Cash sale flow",
      "status": true,
      "mappings": [
        {
          "id": "uuid",
          "mapping_key": "cash",
          "mapping_name": "Cash",
          "position": "D",
          "account_id": "uuid",
          "account": {
            "id": "uuid",
            "code": "1001",
            "name": "Cash"
          }
        }
      ]
    }
  ]
}
```

### POST `/api/accounting/services`

Description: creates a service and its mappings in one transaction.

Request body:

- `service_code` required
- `service_name` required
- `module_name` required
- `description` optional
- `status` optional
- `mappings` optional array

Validation rules:

- `service_code` required string max 100 and unique
- `service_name` required string max 200
- `module_name` required string max 100
- `description` nullable string
- `status` nullable boolean
- `mappings` nullable array
- `mappings.*.mapping_key` required string max 150
- `mappings.*.mapping_name` required string max 200
- `mappings.*.position` required and must be `D` or `K`
- `mappings.*.account_id` nullable and must exist in the account table
- `mappings.*.sequence_no` nullable integer
- `mappings.*.is_dynamic` nullable boolean
- `mappings.*.is_required` nullable boolean

Example response:

```json
{
  "status": 201,
  "message": "Service created successfully",
  "data": {
    "id": "uuid",
    "service_code": "SALES_CASH",
    "service_name": "Cash Sales",
    "module_name": "sales",
    "mappings": []
  }
}
```

### GET `/api/accounting/services/{id}`

Description: returns one service with mappings and mapped accounts.

Path parameters:

- `id` required

Example response:

```json
{
  "status": 200,
  "message": "Service retrieved successfully",
  "data": {
    "id": "uuid",
    "service_code": "SALES_CASH",
    "service_name": "Cash Sales",
    "module_name": "sales",
    "status": true,
    "mappings": []
  }
}
```

### PUT `/api/accounting/services/{id}`

Description: updates a service and synchronizes its mappings.

Path parameters:

- `id` required

Request body:

- `service_code` optional
- `service_name` optional
- `module_name` optional
- `description` optional
- `status` optional
- `mappings` optional array

Validation rules:

- `service_code` nullable string max 100 and unique except current record
- `service_name` nullable string max 200
- `module_name` nullable string max 100
- `description` nullable string
- `status` nullable boolean
- `mappings` nullable array
- `mappings.*.id` nullable and must exist in the service-account table
- `mappings.*.mapping_key` required string max 150
- `mappings.*.mapping_name` required string max 200
- `mappings.*.position` required and must be `D` or `K`
- `mappings.*.account_id` nullable and must exist in the account table
- `mappings.*.sequence_no` nullable integer
- `mappings.*.is_dynamic` nullable boolean
- `mappings.*.is_required` nullable boolean
- `mappings.*.status` nullable boolean

Example response:

```json
{
  "status": 200,
  "message": "Service updated successfully",
  "data": {
    "id": "uuid",
    "service_code": "SALES_CASH",
    "service_name": "Cash Sales",
    "module_name": "sales",
    "mappings": []
  }
}
```

### DELETE `/api/accounting/services/{id}`

Description: deletes a service and its mappings.

Path parameters:

- `id` required

Example response:

```json
{
  "status": 200,
  "message": "Service and its mappings deleted successfully",
  "data": null
}
```

### PATCH `/api/accounting/services/{id}/toggle-status`

Description: flips the service active flag.

Path parameters:

- `id` required

Example response:

```json
{
  "status": 200,
  "message": "Service status toggled successfully",
  "data": {
    "id": "uuid",
    "status": false
  }
}
```

## Journals

### POST `/api/accounting/journals`

Description: creates a manual journal entry without `service_id` or `mapping_key`.

Authentication: package-level auth is not enforced.

Request body:

```json
{
  "trx_date": "2026-07-13",
  "reference_no": "JU-20260713-0001",
  "description": "Jurnal penyesuaian akhir bulan",
  "details": [
    {
      "account_id": "uuid-account-1",
      "type": "D",
      "amount": 1000000,
      "description": "Kas"
    },
    {
      "account_id": "uuid-account-2",
      "type": "K",
      "amount": 1000000,
      "description": "Modal"
    }
  ]
}
```

Validation rules:

- `trx_date` required|date
- `reference_no` nullable|string|max:100
- `description` nullable|string
- `details` required|array|min:2
- `details.*.account_id` required and must exist in `acc_accounts`
- `details.*.type` required|in:D,K
- `details.*.amount` required|numeric|gt:0
- `details.*.description` nullable|string
- Total debit must equal total credit
- Account must be active
- Account must be postable
- Fiscal period must be open

Success response:

```json
{
  "status": 201,
  "message": "Manual journal created successfully",
  "data": {
    "id": "uuid",
    "journal_no": "JV/2026/07/0001",
    "trx_date": "2026-07-13",
    "reference_no": "JU-20260713-0001",
    "status": "posted"
  }
}
```

Validation and domain errors:

- Laravel returns the standard JSON validation payload for request validation failures.
- Service-level validation failures also return a 422 response with `errors` keyed by the invalid field.
- A closed fiscal period returns a 422 validation response for `trx_date`.

Error response example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "details.0.account_id": [
      "Account is inactive: uuid-account-1"
    ]
  }
}
```

Example request:

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/journals' \
--header 'Accept: application/json' \
--header 'Content-Type: application/json' \
--data '{
  "trx_date": "2026-07-13",
  "reference_no": "JU-20260713-0001",
  "description": "Jurnal penyesuaian akhir bulan",
  "details": [
    {
      "account_id": "uuid-account-1",
      "type": "D",
      "amount": 1000000,
      "description": "Kas"
    },
    {
      "account_id": "uuid-account-2",
      "type": "K",
      "amount": 1000000,
      "description": "Modal"
    }
  ]
}'
```

### POST `/api/accounting/opening-balances`

Description: creates one opening-balance journal for multiple accounts in a single transaction.

Authentication: package-level auth is not enforced.

Request body:

```json
{
  "trx_date": "2026-01-01",
  "reference_no": "OPENING-2026",
  "description": "Opening Balance Tahun 2026",
  "details": [
    {
      "account_id": "uuid-account-1",
      "amount": 1000000
    },
    {
      "account_id": "uuid-account-2",
      "amount": -500000
    },
    {
      "account_id": "uuid-account-3",
      "amount": 2500000
    }
  ]
}
```

Validation rules:

- `trx_date` required|date
- `reference_no` nullable|string|max:100
- `description` nullable|string
- `details` required|array|min:2
- `details.*.account_id` required and must exist in `acc_accounts`
- `details.*.amount` required|numeric|not_in:0
- `details.*.description` nullable|string
- All accounts must exist, be active, and be postable
- Duplicate accounts are rejected
- Fiscal period must be open
- The total debit must equal the total credit after the package maps signed amounts using the account category normal balance
- Opening balance can only be created once per database or tenant because the package checks `source_type = OPENING_BALANCE`

Success response:

```json
{
  "status": 201,
  "message": "Opening balance created successfully",
  "data": {
    "id": "uuid",
    "journal_no": "JV/2026/01/0001",
    "trx_date": "2026-01-01",
    "reference_no": "OPENING-2026",
    "amount": 5000000,
    "status": "posted"
  }
}
```

Copyable example payload:

```json
{
  "trx_date": "2026-01-01",
  "reference_no": "OPENING-2026",
  "description": "Opening Balance Tahun 2026",
  "details": [
    {
      "account_id": "uuid-account-1",
      "amount": 1000000
    },
    {
      "account_id": "uuid-account-2",
      "amount": -500000
    },
    {
      "account_id": "uuid-account-3",
      "amount": 2500000
    }
  ]
}
```

Notes:

- Positive values follow the normal balance of the account category.
- Negative values reverse the normal balance.
- The package creates one journal entry only, not one journal per account.
- The implementation uses a database transaction and calls `JournalService::journalManual()` internally.

### GET `/api/accounting/journals`

Description: returns a paginated list of journals ordered by transaction date descending, then journal number descending.

Authentication: package-level auth is not enforced.

Query parameters:

- `page` optional integer, default `1`
- `per_page` optional integer, default `15`
- `search` optional string
- `start_date` optional date
- `end_date` optional date
- `status` optional string
- Supported status values are the `JournalStatus` enum values:
  - `draft`
  - `posted`
  - `reversed`

Response body:

- This endpoint returns a raw Laravel paginator, not the package response envelope.
- Each journal item has the `service` relation loaded when a `service_id` exists.

Example request:

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/journals?search=JV&per_page=10' \
--header 'Accept: application/json'
```

Example response:

```json
{
  "data": [
    {
      "id": "uuid",
      "journal_no": "JV/2026/07/0001",
      "trx_date": "2026-07-10",
      "status": "posted",
      "service": {
        "id": "uuid",
        "service_code": "SALES_CASH"
      }
    }
  ],
  "links": {},
  "meta": {}
}
```

Notes:

- `search` matches `journal_no`, `reference_no`, and `description`.
- `start_date` and `end_date` filter `trx_date`.
- The paginator is cached per filter combination.

### GET `/api/accounting/journals/{id}`

Description: returns a journal with service, details, detail accounts, reversals, and reversal source information.

Path parameters:

- `id` required

Response body:

- `service`
- `details`
- `details[].account`
- `reversals`
- `reversalOf` when the journal is a reversal

Example response:

```json
{
  "status": 200,
  "message": "Journal retrieved successfully",
  "data": {
    "id": "uuid",
    "journal_no": "JV/2026/07/0001",
    "status": "posted",
    "service": {
      "id": "uuid",
      "service_code": "SALES_CASH"
    },
    "details": [
      {
        "id": "uuid",
        "account_id": "uuid",
        "debit": "1000.00",
        "credit": "0.00",
        "account": {
          "id": "uuid",
          "code": "1001",
          "name": "Cash"
        }
      }
    ],
    "reversals": []
  }
}
```

### POST `/api/accounting/journals/{id}/reverse`

Description: creates a reversal journal for a posted journal.

Path parameters:

- `id` required

Request body:

- `reason` required string max 1000

Validation rules:

- `reason` required|string|max:1000

Example request:

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/journals/uuid/reverse' \
--header 'Accept: application/json' \
--header 'Content-Type: application/json' \
--data '{
  "reason": "Customer refund"
}'
```

Example response:

```json
{
  "status": 201,
  "message": "Journal reversed successfully",
  "data": {
    "original_journal_id": "uuid",
    "reversal_journal_id": "uuid"
  }
}
```

Notes:

- Only posted journals can be reversed.
- The reversal journal is created as a new entry; the original journal is not edited.

## Reports

### GET `/api/accounting/reports/general-ledger`

Description: returns general ledger movement for a single account.

Query parameters:

- `account_id` required uuid
- `start_date` required date
- `end_date` required date and must be greater than or equal to `start_date`

Response body:

- `account`
- `opening_balance`
- `details`
- `account.category_path`

Example response:

```json
{
  "status": 200,
  "message": "General Ledger retrieved successfully",
  "data": {
    "account": {
      "id": "uuid",
      "code": "1001",
      "name": "Cash",
      "category_path": ["Asset", "Current Asset"]
    },
    "opening_balance": 1000,
    "details": []
  }
}
```

### GET `/api/accounting/reports/trial-balance`

Description: returns trial balance for a year and month.

Query parameters:

- `year` required integer
- `month` required integer between 1 and 12

Response body:

- `data`
- `total_assets`
- `total_liabilities`
- `total_equity`

Example response:

```json
{
  "status": 200,
  "message": "Trial Balance retrieved successfully",
  "data": {
    "data": [],
    "total_assets": 0,
    "total_liabilities": 0,
    "total_equity": 0
  }
}
```

### GET `/api/accounting/reports/profit-loss`

Description: returns profit and loss report for a year and month.

Query parameters:

- `year` required integer
- `month` required integer between 1 and 12

Response body:

- `data.revenue`
- `data.expense`
- `net_income`

Example response:

```json
{
  "status": 200,
  "message": "Profit & Loss retrieved successfully",
  "data": {
    "data": {
      "revenue": [],
      "expense": []
    },
    "net_income": 0
  }
}
```

### GET `/api/accounting/reports/balance-sheet`

Description: returns balance sheet for a year and month.

Query parameters:

- `year` required integer
- `month` required integer between 1 and 12

Response body:

- `data.asset`
- `data.liability`
- `data.equity`
- `total_assets`
- `total_liabilities`
- `total_equity`

Example response:

```json
{
  "status": 200,
  "message": "Balance Sheet retrieved successfully",
  "data": {
    "data": {
      "asset": [],
      "liability": [],
      "equity": []
    },
    "total_assets": 0,
    "total_liabilities": 0,
    "total_equity": 0
  }
}
```

### GET `/api/accounting/reports/cash-flow`

Description: returns cash flow report for a year and month.

Query parameters:

- `year` required integer
- `month` required integer between 1 and 12

Response body:

- `data.operating`
- `data.investing`
- `data.financing`
- `net_cash_flow`

Example response:

```json
{
  "status": 200,
  "message": "Cash Flow retrieved successfully",
  "data": {
    "data": {
      "operating": [],
      "investing": [],
      "financing": []
    },
    "net_cash_flow": 0
  }
}
```

## Implementation Notes

- There are no dedicated Form Request classes in the package; validation is handled inline in controllers.
- Categories, accounts, and services are master-data endpoints.
- Journals and fiscal-period/balance logic are transaction-data endpoints and follow the application's active connection.
- Route model binding is not used for these endpoints; the controllers read `id` manually and resolve tenant context through the `tenantId` route segment or `X-Tenant` header when present.

