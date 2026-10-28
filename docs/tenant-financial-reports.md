# Profit/loss and balance sheet by tenant

Both reports accept an optional `tenant_ids` list alongside `year` and `month`:

- `GET /api/accounting/reports/profit-loss`
- `GET /api/accounting/reports/balance-sheet`

The configured route prefix and existing `/{tenantId}/...` variants remain supported.

## Existing requests

Omitting `tenant_ids` preserves the existing active-connection calculation and response. Route tenant / `X-Tenant` initialization still follows the existing controller behavior.

```http
GET /api/accounting/reports/profit-loss?year=2026&month=7
Accept: application/json
```

For example, a report with no monthly balances retains this response:

```json
{
  "status": 200,
  "message": "Profit & Loss retrieved successfully",
  "data": {
    "data": {"revenue": [], "expense": []},
    "net_income": 0
  }
}
```

## Explicit tenant requests

Use array query syntax, not a comma-separated string:

```http
GET /api/accounting/reports/profit-loss?year=2026&month=7&tenant_ids[]=tenant-a&tenant_ids[]=tenant-b
Accept: application/json
```

An explicit list selects the report targets. The controller does not first initialize a path/header tenant for these requests. All IDs are resolved before any report executes. Each report then runs separately through the same calculation in its tenant context; amounts are never consolidated.

The usual HTTP envelope remains, with a list of `{tenant_id, report}` entries in `data`. Each `report` is the existing single-tenant report payload. The following examples abbreviate category tree nodes to their code and balance; actual responses retain all existing category/account/path/children fields.

```json
{
  "status": 200,
  "message": "Profit & Loss retrieved successfully",
  "data": [
    {
      "tenant_id": "tenant-a",
      "report": {
        "data": {
          "revenue": [{"category_code": "REVENUE", "balance": 100}],
          "expense": [{"category_code": "EXPENSE", "balance": 30}]
        },
        "net_income": 70
      }
    },
    {
      "tenant_id": "tenant-b",
      "report": {
        "data": {
          "revenue": [{"category_code": "REVENUE", "balance": 800}],
          "expense": [{"category_code": "EXPENSE", "balance": 200}]
        },
        "net_income": 600
      }
    }
  ]
}
```

```http
GET /api/accounting/reports/balance-sheet?year=2026&month=7&tenant_ids[]=tenant-a&tenant_ids[]=tenant-b
Accept: application/json
```

```json
{
  "status": 200,
  "message": "Balance Sheet retrieved successfully",
  "data": [
    {
      "tenant_id": "tenant-a",
      "report": {
        "data": {
          "asset": [{"category_code": "ASSET", "balance": 100}],
          "liability": [{"category_code": "LIABILITY", "balance": 40}],
          "equity": [{"category_code": "EQUITY", "balance": 60}]
        },
        "total_assets": 100,
        "total_liabilities": 40,
        "total_equity": 60
      }
    },
    {
      "tenant_id": "tenant-b",
      "report": {
        "data": {
          "asset": [{"category_code": "ASSET", "balance": 700}],
          "liability": [{"category_code": "LIABILITY", "balance": 200}],
          "equity": [{"category_code": "EQUITY", "balance": 500}]
        },
        "total_assets": 700,
        "total_liabilities": 200,
        "total_equity": 500
      }
    }
  ]
}
```

## Validation and failure behavior

- `year` remains a required integer; `month` remains a required integer from 1 to 12.
- When present, `tenant_ids` must be a nonempty sequential array. Null, a scalar, an associative array, blank IDs, booleans, nested arrays, and unresolved tenants are rejected with HTTP 422.
- Each ID must be an integer or string. Identifiers are trimmed and resolved by primary key, then tenant route key, matching the existing tenancy lookup convention.
- Duplicate IDs and route-key aliases for the same tenant are reported once, in first-resolved order. Response `tenant_id` values are canonical primary keys serialized as strings.
- A configured host `tenancy()` integration and Eloquent `tenancy.tenant_model` are required for explicit lists. Missing integration/configuration produces validation errors; it never selects the default database as a substitute.
- Every requested tenant must resolve before the first report runs. There is no partial success response. Initialization or report failures propagate through the existing exception handling.
- Original active tenancy is restored (or tenancy is ended if originally inactive) in `finally`; the original Laravel default connection is restored even if tenancy cleanup throws. A no-op/mismatched initialization is rejected before report execution.

For example, `tenant_ids[]=tenant-a&tenant_ids[]=missing` returns validation errors and executes neither tenant report:

```json
{
  "message": "Tenant [missing] could not be resolved.",
  "errors": {
    "tenant_ids.1": ["Tenant [missing] could not be resolved."]
  }
}
```

The host still supplies authentication, authorization for requested tenants, and tenancy database initialization/teardown. The integration must switch the transaction database during `initialize()`; the package does not infer database names from tenant IDs.

## PHP services and connection modes

```php
use ESolution\LaravelAccounting\Services\ReportService;

$reports = app(ReportService::class);

// Existing active-connection payloads, unchanged (also when the third argument is null).
$profitLoss = $reports->profitLoss(2026, 7);
$balanceSheet = $reports->balanceSheet(2026, 7);

// Separate [{tenant_id, report}, ...] results; the same validation applies.
$tenantProfitLoss = $reports->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);
$tenantBalanceSheets = $reports->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']);
```

The optional third PHP parameter is `?array $tenantIds = null`; an empty array is rejected. Explicit HTTP `tenant_ids=null` is invalid rather than an omitted parameter.

With shared master mode enabled, accounts/categories remain on the configured master connection while each tenant's monthly balances come from its active transaction connection. With shared master disabled, accounts/categories also follow that tenant's active connection. Single-database requests without a list still need no tenancy integration. Existing resolver/model/repository connection overrides remain in effect.

These reports continue to use stored monthly snapshots and the existing category rollup calculations. This change adds no cross-database joins, live-balance fallback, schema changes, or report cache. Each call reads the requested period separately, so no new tenant/period cache keys are introduced. Hosts overriding reporting with a cache must include both tenant identity and period in their cache partition.

## Focused checks

`tests/Feature/TenantFinancialReportsTest.php` uses real separate SQLite databases and a host-tenancy double, with actual package migrations for report tables. It covers both master modes, separate amounts/periods, legacy responses, list validation, deduplication, connection overrides, and restoration on success/failure. With the existing local PHPUnit/Testbench tooling:

```sh
php vendor/bin/phpunit --bootstrap vendor/autoload.php --do-not-cache-result tests/Feature/TenantFinancialReportsTest.php
```
