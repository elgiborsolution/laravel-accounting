# Arithmetic consolidated reports

`ESolution\LaravelAccounting\Services\ConsolidatedReportService` produces one profit/loss or balance-sheet report, or a consolidated profit/loss summary, from selected tenants. It is a separate container-bound service. Existing `ReportService` methods, per-tenant results, facade behavior, and HTTP endpoints are unchanged; no consolidation endpoint is registered.

## Usage

```php
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;

$consolidated = app(ConsolidatedReportService::class);

$profitLoss = $consolidated->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);
$balanceSheet = $consolidated->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']);
$summary = $consolidated->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);
```

All three methods require `(year, month, array $tenantIds)`. `profitLoss()` and `balanceSheet()` return an array with:

| Field | Meaning |
| --- | --- |
| `tenant_ids` | Deduplicated canonical tenant primary keys, serialized as strings, in first-resolved order |
| `period` | Integer `year` and `month` |
| `report` | Consolidated category/account trees plus `net_income` for profit/loss or asset/liability/equity totals for balance sheet |

`profitLossSummary()` reuses `profitLoss()` and the shared summary calculator to return `{tenant_ids, period, summary}` with classified components, net sales, gross profit, operating profit, and net profit. See [summary classifications and output](./profit-loss-summaries.md). It does not change either report method's payload.

Year must be an integer >= 1 and month must be an integer from 1 to 12. The tenant list must be nonempty and sequential; null/scalar entries, nested arrays, blank IDs, unresolved tenants, and associative lists are rejected through the existing `TenantReportRunner` validation. Duplicate IDs and route-key aliases resolving to one tenant are counted once.

For the example balances below, tenant A has net income 100 and tenant B has net income 315. Consolidation returns net income 415. The JSON examples abbreviate category/account metadata to show the amounts and hierarchy; the complete fields are listed afterward.

```json
{
  "tenant_ids": ["tenant-a", "tenant-b"],
  "period": {"year": 2026, "month": 7},
  "report": {
    "data": {
      "revenue": [
        {
          "category_code": "REVENUE",
          "parent_code": null,
          "balance": 485,
          "accounts": [{"code": "4000", "category_code": "REVENUE", "balance": 15}],
          "children": [
            {
              "category_code": "SALES",
              "parent_code": "REVENUE",
              "balance": 420,
              "accounts": [
                {"code": "4001", "category_code": "SALES", "balance": 400},
                {"code": "4002", "category_code": "SALES", "balance": 20}
              ],
              "children": []
            },
            {
              "category_code": "SERVICES",
              "parent_code": "REVENUE",
              "balance": 50,
              "accounts": [{"code": "4101", "category_code": "SERVICES", "balance": 50}],
              "children": []
            }
          ]
        }
      ],
      "expense": [
        {
          "category_code": "EXPENSE",
          "parent_code": null,
          "balance": 70,
          "accounts": [],
          "children": [
            {
              "category_code": "OPERATING",
              "parent_code": "EXPENSE",
              "balance": 70,
              "accounts": [{"code": "5001", "category_code": "OPERATING", "balance": 70}],
              "children": []
            }
          ]
        }
      ]
    },
    "net_income": 415
  }
}
```

Balance sheet uses the same outer structure. This example abbreviates the tree nodes:

```json
{
  "tenant_ids": ["tenant-a", "tenant-b"],
  "period": {"year": 2026, "month": 7},
  "report": {
    "data": {
      "asset": [{"category_code": "ASSET", "balance": 725}],
      "liability": [{"category_code": "LIABILITY", "balance": 205}],
      "equity": [{"category_code": "EQUITY", "balance": 520}]
    },
    "total_assets": 725,
    "total_liabilities": 205,
    "total_equity": 520
  }
}
```

Complete consolidated category nodes contain `category_code`, nullable `parent_code`, `type`, `category_name`, `report_type`, boolean `status`, `children`, `accounts`, and numeric `balance`. Accounts contain `code`, `category_code`, `name`, boolean `is_postable` and `status`, and numeric `balance`. Database UUID fields (`id`, `parent_id`, `category_id`) are not emitted. Roots, children, and accounts are sorted by their exact code strings. Descriptions, sequence numbers, and database-specific paths are not part of the consolidated output.

## Alignment and incompatible definitions

Category codes and account codes are matched exactly as strings, including case and leading zeros. Different UUIDs in different tenant databases are expected and do not affect matching. Accounts or category branches present in only some tenants are included, contributing amounts only where present.

Matching participating codes must have compatible definitions:

| Definition | Fields that must agree |
| --- | --- |
| Category | Parent category **code**, type, category name, report type, active/status flag |
| Account | Category **code**, account name, postable flag, active/status flag |

Names are compared exactly as a conservative check against unrelated definitions reusing one code. Different localized labels for the same participating code therefore produce an error. UUIDs, descriptions, and sequence numbers are not compatibility fields.

Compatibility is checked against the complete metadata of every selected tenant for each code participating in the requested report. For example, if account `4001` is a revenue account in tenant A but an asset account in tenant B, profit/loss consolidation fails explicitly rather than treating that account as absent from B. Differences in unrelated codes outside the requested report do not block consolidation.

Conflicts throw `Illuminate\Validation\ValidationException` with a `consolidation` error naming the code, incompatible field, and tenant. For example:

```text
Account code [4001] has incompatible [category_code] in tenant [tenant-b].
```

Duplicate occurrences within a tenant's report, missing code definitions, invalid hierarchy references, and invalid account balances also fail explicitly. No partial or best-effort consolidated result is returned.

## Calculation and connection behavior

1. The service validates the period and delegates full tenant-list validation/resolution to `TenantReportRunner`. No tenant report runs until all requested IDs resolve.
2. For each tenant, the runner initializes the existing host tenancy integration. The service calls the existing single-tenant `ReportService::profitLoss()` or `balanceSheet()` and captures code-based metadata through the same account/category repositories in that context.
3. The runner restores prior active tenancy, or ends tenancy if originally inactive, in `finally`, and restores the original default connection even if cleanup throws. Query or initialization failures propagate; no invalid tenant is skipped and no default database is substituted.
4. In memory, corresponding **account** balances are summed. Category balances and original report totals are not added to these amounts. Each category is rebuilt from direct accounts plus immediate child subtotals; report totals use root subtotals only. Signed amounts are preserved and sums are rounded to two decimal places, consistent with the package's monetary report convention.

Tenant-local master mode reads each tenant's own accounts/categories and snapshots. Shared-master mode keeps metadata on the configured master connection and reads snapshots on each tenant's transaction connection. Existing resolver/model/repository overrides remain in effect. No database name is derived from an ID, and there are no cross-database joins, schema/configuration/dependency changes, or new report caches.

The original reports continue to use stored monthly balances; this service does not introduce a live-balance fallback. This is arithmetic consolidation only, with no intercompany eliminations or automatic year-end/equity adjustments. Host applications continue to supply tenancy database initialization, authentication, and authorization for the requested tenants.

## Focused verification

`tests/Feature/ConsolidatedReportServiceTest.php` uses separate SQLite databases and actual package migrations with a host-tenancy double. It covers distinct UUIDs, shared/tenant-local master data, matching codes and tenant-specific branches/accounts, duplicate IDs, signed balances, incompatible definitions, invalid tenants, connection overrides, success/failure restoration, recalculated totals, and unchanged legacy/per-tenant HTTP and PHP results.

```sh
php vendor/bin/phpunit --bootstrap vendor/autoload.php --do-not-cache-result tests/Feature/ConsolidatedReportServiceTest.php
```
