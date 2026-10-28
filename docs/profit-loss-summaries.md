# Profit/loss summaries

Per-tenant summaries use `ProfitLossSummaryService`; consolidated summaries are a method on the existing `ConsolidatedReportService`. Both entry points accept year/month and a required, nonempty tenant-ID array:

```php
use ESolution\LaravelAccounting\Services\ProfitLossSummaryService;
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;

$perTenant = app(ProfitLossSummaryService::class)
    ->summarize(2026, 7, ['tenant-a', 'tenant-b']);

$consolidated = app(ConsolidatedReportService::class)
    ->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);
```

The signatures are `ProfitLossSummaryService::summarize($year, $month, array $tenantIds): array` and `ConsolidatedReportService::profitLossSummary($year, $month, array $tenantIds): array`. Year must be an integer >= 1; month must be an integer from 1 to 12. Neither entry point registers HTTP routes or changes existing report service/API payloads.

## Calculations

Each account contributes to exactly one component. Parent category balances and original report totals are not added to account amounts.

```text
net_sales        = sales_revenue - sales_returns - sales_discounts
gross_profit     = net_sales - cogs
operating_profit = gross_profit + other_operating_income - operating_expenses
net_profit       = operating_profit + non_operating_income
                   - non_operating_expenses - income_tax
```

Every summary contains all nine component fields and these four calculated values, including zeros for empty components. Signed amounts and reversals are preserved; sums are rounded to two decimal places using the package's existing report convention.

## Configurable code classifications

Defaults are written directly as editable `account_mappings` and `category_mappings` arrays under `profit_loss_summary` in `config/accounting.php`. The existing `accounting-config` publish tag copies this complete file into the host application's config directory; no separate mapping file or package-class reference is required in the published config.

```sh
php artisan vendor:publish --tag=accounting-config
```

Edit the host application's `config/accounting.php` to persist classification changes. For an already published config, add or edit its `profit_loss_summary` section directly. For example, a partial host override can be written inside the returned config array as:

```php
'profit_loss_summary' => [
    'account_mappings' => [
        '4101' => 'other_operating_income',
        '5210' => 'income_tax',
    ],
],
```

Host overrides are merged over the calculator's existing compatibility defaults, so a partial override retains other seeded rules. All original mapping values and account-over-category precedence are preserved. Rules are snapshotted once at the start of each call and applied identically to all selected tenants and to the consolidated tree.

The published mappings are plain arrays/scalars and work with Laravel configuration caching. After editing the host config, rebuild the cache so subsequent application boots use the new values:

```sh
php artisan config:cache
```

`php artisan config:clear` returns the application to loading config files directly. The `config([...])` examples below demonstrate programmatic overrides; editing the host config is the persistent configuration path.

Classification precedence is:

1. An account-code mapping, including seeded account defaults.
2. The nearest mapped category code, starting at the account's own category and walking up its report-tree ancestry.
3. An unmapped nonzero account produces a `ValidationException` under `summary`; unmapped zero-balance accounts contribute nothing. Nonzero amounts are never silently discarded.

| Code source | Default component |
| --- | --- |
| Account `5210` | `operating_expenses` |
| Account `5601` | `sales_discounts` |
| Account `5701` | `sales_returns` |
| Category `SALES_REVENUE` | `sales_revenue` |
| Category `SERVICE_REVENUE` | `sales_revenue` |
| Category `COST_OF_GOODS_SOLD` | `cogs` |
| Category `OPERATING_EXPENSE` | `operating_expenses` |
| Category `OTHER_REVENUE` | `non_operating_income` |
| Category `OTHER_EXPENSE` | `non_operating_expenses` |

`other_operating_income` and `income_tax` have no inferred default accounts. In particular, generic tax account **5210 is an ordinary operating expense** until the host explicitly maps that account to `income_tax`. Neither a generic tax label nor a translated account/category name is used to infer income tax.

For example, explicitly classify account `4101` as other operating income and `5210` as income tax:

```php
config([
    'accounting.profit_loss_summary.account_mappings.4101' => 'other_operating_income',
    'accounting.profit_loss_summary.account_mappings.5210' => 'income_tax',
]);
```

Custom category-code mappings work the same way:

```php
config([
    'accounting.profit_loss_summary.category_mappings.CUSTOM_OPERATING_INCOME' => 'other_operating_income',
    'accounting.profit_loss_summary.category_mappings.INCOME_TAX_EXPENSE' => 'income_tax',
]);
```

Only accounts under those custom categories are affected when the categories exist. Account mappings take precedence even over an explicitly configured category rule: to change the default classification of account `5210`, override its account mapping directly.

Valid component identifiers are `sales_revenue`, `sales_returns`, `sales_discounts`, `cogs`, `other_operating_income`, `operating_expenses`, `non_operating_income`, `non_operating_expenses`, and `income_tax`. Invalid configuration fails under `summary_mappings` before tenant reports run. Codes are exact strings, preserving case and leading zeros. Defaults reuse existing seeded chart codes; no seeded account/category or localized report-mapping record is changed. Localized `ReportMapping` group labels are presentation data, not summary classification identifiers.

### Contra-account signs

Existing report account balances follow the account category's normal balance. The calculator expresses income components as credit-positive and deductions as debit-positive. For example, a debit sales return in a `REVENUE` category appears in the source report as `-50`; the summary reports `sales_returns: 50` and subtracts it from sales. A reversing credit becomes a negative deduction. An expense-category sales return already has a debit-positive balance and is not negated again. The calculator never takes absolute values to infer expense amounts.

Account-level overrides ensure sales returns/discounts under `OTHER_REVENUE` are not also included in non-operating income. Explicit tax and other-operating-income overrides likewise remove the amounts from their default categories' summary components.

## Per-tenant output

`ProfitLossSummaryService` returns canonical `tenant_ids`, `period`, and a `summaries` list with separate `{tenant_id, summary}` entries. For example, with the default classifications:

```json
{
  "tenant_ids": ["tenant-a", "tenant-b"],
  "period": {"year": 2026, "month": 7},
  "summaries": [
    {
      "tenant_id": "tenant-a",
      "summary": {
        "sales_revenue": 1200,
        "sales_returns": 50,
        "sales_discounts": 20,
        "cogs": 400,
        "other_operating_income": 0,
        "operating_expenses": 215,
        "non_operating_income": 40,
        "non_operating_expenses": 8,
        "income_tax": 0,
        "net_sales": 1130,
        "gross_profit": 730,
        "operating_profit": 515,
        "net_profit": 547
      }
    },
    {
      "tenant_id": "tenant-b",
      "summary": {
        "sales_revenue": 2100,
        "sales_returns": 100,
        "sales_discounts": 30,
        "cogs": 900,
        "other_operating_income": 0,
        "operating_expenses": 325,
        "non_operating_income": 80,
        "non_operating_expenses": 13,
        "income_tax": 0,
        "net_sales": 1970,
        "gross_profit": 1070,
        "operating_profit": 745,
        "net_profit": 812
      }
    }
  ]
}
```

## Consolidated output

`ConsolidatedReportService::profitLossSummary()` returns canonical `tenant_ids`, `period`, and one `summary`. The example above consolidates to:

```json
{
  "tenant_ids": ["tenant-a", "tenant-b"],
  "period": {"year": 2026, "month": 7},
  "summary": {
    "sales_revenue": 3300,
    "sales_returns": 150,
    "sales_discounts": 50,
    "cogs": 1300,
    "other_operating_income": 0,
    "operating_expenses": 540,
    "non_operating_income": 120,
    "non_operating_expenses": 21,
    "income_tax": 0,
    "net_sales": 3100,
    "gross_profit": 1800,
    "operating_profit": 1260,
    "net_profit": 1359
  }
}
```

Applying the account overrides in the earlier example changes consolidated other operating income to 90, operating expenses to 500, operating profit to 1390, non-operating income to 30, and income tax to 40. Net profit remains 1359: those amounts are reclassified, not added again.

## Tenancy, compatibility, and failures

- The per-tenant summary delegates to the existing `ReportService::profitLoss(..., $tenantIds)` path. `ConsolidatedReportService::profitLossSummary()` calls its existing `profitLoss(...)` method, retaining the same tenant iteration, code alignment, aggregation, tenant-specific accounts, and incompatible-definition checks. The shared calculator then classifies each materialized report's account balances.
- Existing `TenantReportRunner` validates all tenant IDs before executing any report, deduplicates IDs and route-key aliases, and restores prior tenancy/default connection in `finally`, including query/initialization failures. Empty, malformed, or unresolved tenant lists are rejected; there is no fallback database or partial success result.
- Calculation takes place after the underlying runner has restored context. Thus a classification or summary-calculation error also leaves the original caller context intact. Duplicate report categories/accounts and invalid balances are explicit errors, rather than an opportunity to count amounts twice.
- Each tenant's monthly balances come from its own active transaction database. Master data continues to follow the existing single/default, tenant-local master, or shared-master configuration and explicit model/resolver/repository overrides. No connection name is hardcoded. Summary mappings are caller-context configuration, not database UUIDs or per-tenant translated labels.
- Consolidated summaries retain the existing consolidation service's definition compatibility checks, including its label/status checks. Code-based summary classification does not relax those existing contracts.
- Stored monthly snapshots, account scope, existing report responses, and seeded chart behavior are unchanged. No live-balance fallback, new cache, cross-database join, HTTP endpoint, or intercompany elimination is introduced.

## Tests

`tests/Feature/ProfitLossSummaryServicesTest.php` uses the real Indonesian/English seeded chart, package migrations, separate SQLite databases, and a host-tenancy double. It covers all four calculations, contra signs/reversals, account/category precedence, explicit tax/operating-income mappings, tenant isolation and deduplication, consolidated reconciliation, fractional amounts, invalid lists/configuration/unmapped balances, both master modes and explicit repository connections, context restoration, and unchanged reports/API output.

```sh
php vendor/bin/phpunit --bootstrap vendor/autoload.php --do-not-cache-result tests/Feature/ProfitLossSummaryServicesTest.php
```
