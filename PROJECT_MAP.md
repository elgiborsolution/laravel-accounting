# Laravel Accounting package system map

Investigation baseline: 2026-10-27, source revision `cc8b7bb`. Paths below are relative to the package root. Subsequent scoped changes are recorded below; baseline investigation statements describe the original review.

## Scoped update: tenant financial reports

Profit/loss and balance sheet now accept optional `tenant_ids` HTTP arrays and `?array $tenantIds = null` as the third `ReportService` parameter. Omitted/null PHP arguments preserve the original active-connection payload. Explicit lists return ordered, deduplicated `[{tenant_id, report}, ...]` entries within the existing HTTP envelope; no values are summed across tenants. Explicit HTTP null, empty/invalid/non-list arrays and unresolved tenants are validation errors.

`src/Support/TenantReportRunner.php` is a container-resolved helper (not an additional provider singleton). It validates the entire list before execution, resolves the configured tenant model by primary/route key, switches through the host `tenancy()->initialize()`, verifies the initialized identity, invokes the existing report calculation per tenant, then restores prior tenancy and the original default connection in nested `finally` blocks. Missing tenancy integration/configuration fails explicitly. The two controller actions bypass ordinary path/header initialization only when a tenant list is supplied, so the original caller context can be restored and invalid lists do not switch context.

Existing master/transaction resolvers, model connection overrides, migrations and unscoped report-account behavior remain in place. Shared master metadata and tenant-local snapshots are fetched separately; tenant-local master mode still switches both through the active default. No report cache or cross-database join was added. Other report methods and the existing general-ledger tenant override are unchanged.

`tests/Feature/TenantFinancialReportsTest.php` exercises real, separate in-memory SQLite databases with a host-tenancy double, using package migrations for report tables. It covers both master modes, per-tenant/per-period results, deduplication and aliases, legacy single-database/active-tenant responses, invalid inputs/integration, explicit repository connections, and context restoration after success/query/initialization failures. The local complete suite passed with PHP 8.4.20: 144 tests, 795 assertions. This verifies the local runtime, not every declared Laravel version or a deployed tenancy bootstrapper. Request/response examples are in `docs/tenant-financial-reports.md`.

## Scoped update: separate arithmetic consolidation

`src/Services/ConsolidatedReportService.php` is a new concrete singleton registered in `src/AccountingServiceProvider.php`. Its `profitLoss($year, $month, array $tenantIds)` and `balanceSheet(...)` methods require a nonempty tenant list and return `{tenant_ids, period: {year, month}, report}`. This is a separate PHP service; existing `ReportService`, `ReportController`, `TenantReportRunner`, their public responses, and routes are unchanged by this addition. No consolidation HTTP endpoint/facade method was added.

The service injects the existing report service, tenant runner and account/category repositories. The runner validates/deduplicates every tenant before running any source report, switches through existing host tenancy, and restores tenant/default connection in `finally`. The consolidation callback invokes the ordinary report and captures metadata in each tenant's context. After context restoration, the service aligns participating categories/accounts by exact codes, verifies parent-code/classification/name/status/postable compatibility (including matching codes classified outside another tenant's selected report), sums account balances, and rebuilds category/root totals without re-adding source subtotals. Codes absent in some tenants are included; database UUIDs are omitted from consolidated output. Unrelated definitions outside the selected report are not compared. Conflicts raise `ValidationException` under `consolidation`.

Existing model/resolver and repository overrides remain in effect; shared-master and tenant-local master configurations use the original report paths. This service introduces no report cache, cross-database join, live snapshot fallback, migration/configuration/dependency change, or intercompany elimination. Documentation and output fields are in `docs/consolidated-reports.md`.

`tests/Feature/ConsolidatedReportServiceTest.php` adds two-database checks with different UUIDs, stable matching codes, tenant-specific branches/accounts, duplicate IDs/aliases, signed amounts, incompatible definitions, invalid lists, context restoration on success/query/initialization/conflict failures, explicit repository connections, and unchanged legacy/per-tenant HTTP/PHP payloads. The local full suite passed on PHP 8.4.20: 170 tests, 1055 assertions (including 26 consolidation cases). Tests use a host-tenancy double and separate SQLite databases, not a deployed host tenancy bootstrapper.

## Scoped update: profit/loss summaries (2026-10-28)

The summary entry points are `Services\ProfitLossSummaryService::summarize($year, $month, array $tenantIds)`, returning `{tenant_ids, period, summaries: [{tenant_id, summary}, ...]}`, and the existing `Services\ConsolidatedReportService::profitLossSummary(...)`, returning `{tenant_ids, period, summary}`. The per-tenant service delegates to `ReportService`; the consolidated summary calls its existing `profitLoss()` method, reusing tenant iteration, code alignment, definition checks and aggregation. Both use `Support\ProfitLossSummaryCalculator` for classification and four-stage profit calculations. The standalone consolidated-summary wrapper and binding have been removed; no replacement service was introduced. `ConsolidatedReportService` retains its existing four-argument constructor and resolves the shared calculator from the container for the new method. Existing report payloads/controllers/routes and tenant runner behavior are unchanged.

`config/accounting.php` exposes additive `profit_loss_summary` defaults as literal, editable `account_mappings` and `category_mappings` arrays. The existing `accounting-config` publishing tag copies them into the host's `config/accounting.php`; there is no separate mapping file. The calculator retains `DEFAULT_MAPPINGS` internally as compatibility fallback for partial host overrides. Published config uses plain values compatible with `php artisan config:cache`; rebuild the cache after host edits. Configurable exact account-code rules take precedence over the nearest category/ancestor-code rule. Defaults: sales/service revenue -> sales revenue; COGS -> cogs; operating expenses -> operating expenses; OTHER_REVENUE/OTHER_EXPENSE -> non-operating income/expenses; accounts 5701/5601 -> sales returns/discounts. Account 5210 explicitly defaults to ordinary operating expense, never inferred income tax. Other operating income and income-tax expense require explicit account/category rules; overriding 5210 itself is required to reclassify its default account rule. Partial host overrides merge over defaults. Both entry points snapshot identical classification rules at call entry.

Only direct account amounts are accumulated, once per code per report. Category/report subtotals are ignored. Income is normalized credit-positive and deductions debit-positive using the category type; contra reversals retain their sign. Unmapped nonzero balances, duplicate source codes, invalid balances, and invalid mapping configuration fail explicitly. No UUID or localized label drives classification. Calculation happens after the existing tenant runner's `finally` restoration; source query/initialization errors retain its restoration behavior. Existing split-database modes, explicit overrides, snapshot/account scope, and consolidation compatibility checks remain in effect. No new endpoint, report cache, migration, seeder change, or intercompany elimination was added.

Usage, output and mapping details are in `docs/profit-loss-summaries.md`. `tests/Feature/ProfitLossSummaryServicesTest.php` uses real seeded code classifications, separate SQLite databases and a host-tenancy double. Its 26 cases cover default/custom calculation, tax rules, overrides/inheritance, contra signs, deduplication, period/fractional arithmetic, per-tenant/consolidated reconciliation, failures/restoration, split modes, overrides, localization and unchanged old reports. Full local suite passed on PHP 8.4.20: 196 tests, 1250 assertions; deployed tenancy bootstrappers and other framework/driver combinations remain unverified.

The inline mapping configuration was additionally verified in an isolated Laravel host using actual `vendor:publish --tag=accounting-config`, edits to its published config, `config:cache`, fresh cached application boots, and `config:clear`. Default mapping equality, persisted host overrides, and account-over-category precedence passed. The related summary/consolidation regression tests passed: 52 tests, 455 assertions. Publishing code and calculator fallback/aggregation behavior did not require changes.

## Scoped update: service-only category retrieval (2026-10-28)

`src/Services/AccountCategoryTreeService.php::index(array $filters = [], ?array $tenantIds = null): Collection|LengthAwarePaginator` adds a PHP retrieval entry point using the categories API as a reference for filters, relationships, hierarchy, ordering, balances and pagination. It reads the active connection when the list is omitted, without initializing tenancy. Existing tree methods and constructor are preserved. Routes, controllers, API responses, bindings and API cache behavior are unchanged; the API does not delegate to this new method.

Explicit lists (second argument or `filters['tenant_ids']`) return one consolidated category result through `src/Services/ConsolidatedReportService.php::categoryTree()`. The existing `TenantReportRunner::run()` validates all IDs before execution, resolves primary/route keys, deduplicates aliases, initializes each tenant and restores the original tenant/default connection in nested `finally` blocks. Its source callback reads the initialized tenant's central-plus-owned accounts and `AccountBalanceService` balances, retaining category snapshot/live fallback. Full charts are collected before merging, filtering or pagination. The existing stable-code alignment, definition checks and account aggregation are reused; financial report responses and code ordering are preserved.

Merged category/account IDs become their stable codes, including category `parent_id` and account `category_id`; these are read-only identifiers. Parent filtering accepts codes or selected tenants' category UUIDs, rejecting ambiguous aliases. Account balances are summed once per source/account; category totals are recalculated without re-adding source subtotals. Tenant-specific branches remain. Matching definitions must be compatible, and unreachable chart branches fail explicitly. Categories order by the first selected occurrence's sequence, name and code; accounts use lexical code order. Search/visibility/relation options apply to the merged chart, then pagination slices filtered top-level items with complete subtrees.

This retrieval path introduces no cache, migration, configuration/dependency change, cross-database query or replacement service. Existing single-database, shared-master, tenant-local master and explicit model/repository connection overrides remain in effect. `docs/reference.md` documents signatures, response fields and usage. `tests/Feature/CategoryRetrievalServiceTest.php` exercises active-connection options, two-database code/UUID merging, visibility, hierarchy/order, totals, pagination, snapshot/live fallback, tenant/period isolation, invalid inputs, explicit connections and restoration on success/query/initialization/definition failures. Tests use SQLite and a host-tenancy double; deployed tenancy bootstrappers and other driver/framework combinations remain unverified.

## Scoped update: compact profit/loss summaries by tenant (2026-10-29)

`src/Services/ConsolidatedReportService.php` adds `profitLossSummaryByTenant($year, $month, array $tenantIds): array` and `profitLossSummaryRangeByTenant(array $range, array $tenantIds): array`. They resolve the existing `ProfitLossSummaryService` through the container and delegate to `summarize()` / `summarizeRange()`, then project each tenant's calculated totals. The response retains `{tenant_ids, period, summaries}` with flat rows containing only `tenant_id`, `net_sales`, `gross_profit`, `operating_profit`, `net_profit`. No tenant amounts are combined. Existing consolidated methods and the four-argument constructor are unchanged; no new service or binding is added.

Delegation preserves monthly snapshot versus inclusive-range movement semantics, mapping precedence/calculations, full-list validation/deduplication, split-database/repository behavior and the existing tenant runner's nested `finally` restoration. Projection happens after the source summary call returns. `docs/reference.md` documents signatures, usage and output; `tests/Feature/ProfitLossSummaryServicesTest.php` and `tests/Feature/ProfitLossRangeServicesTest.php` cover both master modes, different tenant amounts, custom mappings, validation/restoration failures and unchanged consolidated summaries. These tests use separate SQLite databases and a host-tenancy double; deployed tenancy bootstrappers remain unverified.

## Scoped update: inclusive closing cutoffs (2026-11-04)

`config/accounting.php` adds opt-in `closing.cutoff_enabled` (default false) and `cutoff_mode` (`per_tenant` default or `shared`). (The original `shared_connection` and `tenant_ids` settings were removed; see the later scoped update.) There is no static environment cutoff date. `closeThroughCurrentMonth($userId, ?string $cutoffDate)` and its alias retain active monthly closing without a date, except enabled shared mode delegates to coordinated closing through current month end. `closeMonth()` retains full-month snapshot closing and does not publish a cutoff.

`database/migrations/accounting/2026_11_04_000016_create_acc_closing_cutoffs_tables.php` creates transaction-owned prefixed `closing_cutoffs` (singleton row 1, nullable date) and `closing_cutoff_events` (ordered append-only service audit with previous/new date, action, actor, reason, timestamp). It uses the transaction resolver, including shared-master mode; apply it in each transaction DB before enabling. State is read directly without a cross-tenant cache. Enabled protection fails explicitly for missing state/schema.

`src/Services/ClosingService.php` exposes `closeThroughDate(..., ?array $tenantIds = null)`, `reopenCutoff(..., ?array $tenantIds = null)`, `getCutoffDate()` and `getCutoffHistory()`. Local date closing verifies posted balance and advances only a date lock, independently per database; selected tenants return runner envelopes. Reopening requires an earlier retained date or null plus a reason and rejects overlapping/later closed months. State and audit commit atomically. Monthly reopening retains snapshots as before.

Shared persistence lives centrally, created by `database/migrations/accounting/2026_11_04_000017_create_shared_closing_cutoffs_tables.php`. Since 2026-11-06 that file sits in the unified, auto-loaded folder (`accounting-migrations` tag); the `accounting-central` directory and tag were removed. Through `src/Traits/HandlesCentralMigration.php` it acts only on a run whose default connection is the configured master connection. Tenant runs never create, recreate or drop its tables. It is a no-op without a master connection and creates the (inert) tables whatever `cutoff_mode` is. Prefixed `shared_closing_cutoffs` and `shared_closing_cutoff_events` store the authoritative date, canonical tenant scope and audit. They live on the configured master connection, which may also hold shared master metadata but cannot be a tenant transaction connection. Journal, fiscal-period and snapshot ownership is unchanged.

Shared operations use `TenantReportRunner` to validate/deduplicate primary IDs and route aliases, restore context, and reject saved scope mismatches. They hold the central singleton lock, validate all tenants, close complete months locally, and publish centrally only after every local commit succeeds. Mid-month totals never become snapshots. Failure retains the previous shared date/audit but can leave completed local months closed. Retrying skips closed months; repeating the current completed target returns its audit event. Shared reopening validates monthly dependencies across every participant before central publication. Scope changes require an explicit external migration/reconciliation, not an implicit subset close.

`src/Services/FiscalPeriodService.php::assertDateOpen()` unifies inclusive cutoff and monthly checks and requires an active scope member for persisted shared policies. `cutoffMode()` / `cutoffConnection()` resolve the policy store; `withClosingLock()` holds a shared central transaction through callback/local commits. `JournalService` wraps all creation/post/reversal entry points and validates actual reversal dates while retaining original monthly/year rules. `AccountOpeningBalanceService` validates original/target opening dates and rolls back only transaction levels it owns. Host outer tenant transactions must acquire the central lock first. No routes/controllers change. No cutoff cache exists.

`tests/Feature/ClosingCutoffTest.php` verifies boundaries, backdating, all journal paths, independent/shared state, partial failure/retry, central audit rollback, scope validation, context restoration, monthly dependencies, snapshot compatibility, disabled defaults, both master modes, prefixes and transaction boundaries using SQLite. Shared closing exposed a double connection-prefix bug in monthly aggregate wrapping; the query now applies that prefix once. Native concurrent writers on other drivers and deployed tenancy bootstrappers remain unverified. Public configuration/service usage is documented in `docs/reference.md`.

## Scoped update: monthly closing day

Scoped monthly execution policy: `config/accounting.php::closing.closing_day` is nullable (`ACCOUNTING_CLOSING_DAY`). `ClosingService::closeMonth()` remains the entry point and preserves its signature/return. When configured to 1–31, `monthlyClosingDate()` validates selected year/month and computes midnight on that day in the following month, clamped to its last day; `assertMonthClosingDue()` rejects earlier execution before database changes. Null/empty retains prior timing. Posted transaction selection still uses the selected calendar year/month, including the final day, independently of execution/posting timestamps. Fiscal dates, account snapshots, carry-forward/sign calculations and cutoff ownership are unchanged. No scheduler, service, migration or journal flow is added.

`closeThroughCurrentMonth()` and its alias close only eligible months when configured; shared default targets use the latest eligible month end. Shared preflight calls `closeCompleteMonths(..., validateOnly: true)` for every selected tenant before creating snapshots. Explicit per-tenant date cutoffs remain independent date locks; shared date closing still applies monthly eligibility to pending full months. Existing direct retry errors, shared audit-event retries and latest-first reopening remain intact. Reopened snapshots must be rebuilt chronologically; host schedules must coordinate corrections to avoid unintended reclosing. `tests/Feature/ClosingCutoffTest.php` covers early/due/late calls, calendar boundaries, drafts, month/year transitions, leap-day clamping, independent/shared scope, reopening and snapshot replacement. See `docs/reference.md` for rationale and usage.

### Scoped update: closing day month and `month_end` (2026-11-04)

`closing.closing_day` additionally accepts the exact string `month_end` (target month's last day). New `closing.closing_day_month` (`ACCOUNTING_CLOSING_DAY_MONTH`, default `next`; null/empty also means `next`) accepts `current` or `next` and decides whether the day belongs to the month being closed or the following one. Numeric days clamp to the target month's last day. `ClosingService::monthlyClosingDate()` validates both values (plus year/month) whenever a day is configured; a null day keeps unrestricted timing and ignores the month setting. `closeThroughCurrentMonth()` now walks back from the current month until a month's due date has arrived, so `current` can make the current month eligible; results for the `next` default are unchanged. Shared preflight and default targets reuse the same method. Signatures, return shapes, migrations, snapshot math and transaction boundaries are unchanged. `current` closes snapshot the month as posted at execution and month-lock its remaining dates. Tests in `tests/Feature/ClosingCutoffTest.php` cover current-month `month_end`, numeric `next` compatibility, clamping, bulk and shared flows, and invalid configuration.

### Scoped update: shared cutoff connection and scope sources (2026-11-05)

Removed `closing.shared_connection` (`ACCOUNTING_CLOSING_SHARED_CONNECTION`) and `closing.tenant_ids`; values left in a host's published config are ignored. `FiscalPeriodService::cutoffConnection()` now resolves shared storage through `AccountingConnectionResolver::masterConnectionName()` (`ACCOUNTING_MASTER_CONNECTION`) and does not depend on `ACCOUNTING_USE_SHARED_DATABASE`. With that flag false, master data stays on each tenant connection and only the shared cutoff tables live on the master connection. The central migration still delegates to `cutoffConnection()`, so it now targets the master connection. `ClosingService::changeSharedCutoff()` takes participants from the explicit `$tenantIds` argument, otherwise the persisted `shared_closing_cutoffs.tenant_ids`. Before the first scope is persisted, an omitted list fails with a `tenant_ids` validation error, which also applies to `closeThroughCurrentMonth()` because it has no tenant argument. Unsupported shared-mode combinations fail explicitly: master connection unset or undefined, or equal to a participating tenant transaction connection (this includes single-database installs). Journals, fiscal periods, snapshots, per-tenant cutoffs, locking order, connection overrides and tenancy restoration are unchanged. `tests/Feature/ClosingCutoffTest.php` covers both master modes, ignored legacy keys, the missing first scope, and the unsupported combinations.

## Scoped update: balance-sheet summaries (2026-11-05)

`src/Services/ConsolidatedReportService.php` adds `balanceSheetSummary($year, $month, array $tenantIds)` (`{tenant_ids, period, summary}`), `balanceSheetSummaryByTenant($year, $month, array $tenantIds)` (`{tenant_ids, period, summaries: [{tenant_id, status, total_*}]}`) and `balanceSheetSummaryByMonth(array $range, array $tenantIds)` (`{tenant_ids, period (six-field), summaries: [{period: {year, month}, summary}]}`). Validation reuses `ProfitLossSummaryCalculator::period()` and `ReportService::profitLossRangePeriod()`. One `TenantReportRunner` pass per tenant collects definitions, per-month snapshot statuses, and `ReportService::balanceSheet()` payloads for usable months only. Consolidation reuses `mergeSources()`/`sumRoots()` per month; months are never summed.

The protected `balanceSheetSnapshotStatuses()` reads `FiscalPeriod`, grouped `MonthlyBalance` counts and the earliest posted `JournalEntry.trx_date` from the active transaction connection. It reports `current` (closed, with every month from the earliest posted journal closed in non-decreasing `closed_at` order), `stale` (reopened with retained rows, or a broken or out-of-order carry-forward chain) or `missing` (open without rows). Unusable months return `null` totals plus per-tenant `snapshots`, never zeros. No routes, bindings, migrations, cache or existing responses change.

Totals inherit stored snapshot signs. The closing type-case mismatch was confirmed at runtime (an asset snapshot of −1400 against a live balance of 1400) and is fixed by the dashboard-metrics update below. `tests/Feature/BalanceSheetSummaryServicesTest.php` covers both master modes, alias deduplication, by-tenant separation, chronological and cross-year months, stale and missing scenarios, real close/reopen, validation before tenant initialization, query-failure restoration and definition conflicts.

## Scoped update: balance-sheet dashboard metrics (2026-11-05)

The three `ConsolidatedReportService` balance-sheet summary methods now also return:
- `current_assets` and `current_liabilities`;
- `current_ratio` and `debt_ratio` (×100), each `null` on a zero denominator;
- `current_year_profit_loss`;
- `balance_sheet_difference` = assets − (liabilities + equity + current-year profit/loss).

The single-month responses gain a top-level `current_year_period`. Each `ByMonth` entry gains one as well. Consolidated ratios and the difference come from consolidated amounts.

How the values are produced:
- **Classification:** current/non-current uses the new `accounting.balance_sheet_summary` stable-code mappings. These follow `profit_loss_summary` precedence: an account mapping first, then the nearest mapped ancestor category, otherwise non-current. Defaults are `CURRENT_ASSET`/`CURRENT_LIABILITY`.
- **Profit/loss:** current-year profit/loss reuses `ReportService::profitLossRange()`, which reads posted journals and excludes opening-source journals. The range runs from the previously unused `accounting.fiscal.start_month` through the reporting month. It is merged with `mergeSources()` in the same single runner pass. It does not depend on snapshots, so it is present even for `missing`/`stale` months.
- **Validation:** mappings and the fiscal start month are validated before any tenant is initialized.

Sign fix: `src/Services/ClosingService.php` `closeMonthlyPeriod()` now compares category types case-insensitively, so new asset/expense snapshots are debit-normal.

Legacy snapshots:
- The new protected `legacySignedSnapshotMonths()` finds older rows from debit-normal accounts that match `ending = opening + credit − debit` and have non-zero net activity.
- It uses the same account/category sources as closing, with ids chunked so no cross-connection join is needed.
- `balanceSheetSnapshotStatuses()` treats any such month in a target's carry-forward chain as `stale`.

No routes, bindings, migrations, cache or consumers of other methods change. `tests/Feature/BalanceSheetSummaryServicesTest.php` was expanded to 33 tests. These cover:
- metrics in both master modes;
- consolidated ratios versus averaged tenant ratios;
- the fiscal start month;
- mapping overrides and null ratios on zero denominators;
- legacy-sign staleness;
- a real close that stores natural signs, with a profit-to-equity entry counted once;
- configuration validation before tenant runs.

## Scoped update: balance-sheet profit details (2026-11-05)

`src/Services/ConsolidatedReportService.php` adds three methods:
- `balanceSheetProfitDetail($year, $month, array $tenantIds)` returns `{tenant_ids, period, summary}`.
- `balanceSheetProfitDetailByTenant(...)` returns `{tenant_ids, period, summaries: [{tenant_id, ...}]}`.
- `balanceSheetProfitDetailByMonth(array $range, array $tenantIds)` returns `{tenant_ids, period (six-field), summaries: [{period, summary}]}`.

Each `summary` holds `prior_months_profit_loss` (all posted movements before the month, prior fiscal years included) and `current_month_profit_loss`. The consolidated value is the sum of tenant amounts.

`src/Services/ReportService.php` adds the single-tenant calculation:
- `profitLossDetail($year, $month, ?array $tenantIds = null)`, which follows the `balanceSheet()` tenant convention;
- `profitLossDetailMarkers()`.

How the calculation works:
- **Movements:** it sums credit − debit for posted details on accounts whose `REVENUE`/`EXPENSE` category comes from the existing repositories.
- **Exclusions:** opening-source journals are excluded, as are journals marked as profit transfers. A transfer is marked either by a service whose code is in the new `accounting.profit_loss_detail.closing_service_codes`, or by a `source_type` in `closing_source_types`. Both default to the existing `MONTH_END_CLOSING`/`YEAR_END_CLOSING` codes; a missing key keeps its default and `[]` disables it.
- **Reversals** copy both markers.
- **Service IDs** are resolved through `ServiceRepository` in the tenant's master-data context, so no cross-connection join is needed.
- **Validation:** markers are validated before tenant runs.

The figures are informational. They are not added to equity, the balance-sheet summary metrics or snapshots, and no fiscal status is read. No routes, bindings, migrations or existing responses change.

`tests/Feature/BalanceSheetSummaryServicesTest.php` adds profit-detail tests covering:
- two months in all three scopes, in both master modes;
- service-marked and source-type closing transfers, a marker-carrying reversal, and opening journals;
- marker configuration;
- year rollover;
- validation before tenant runs;
- failure restoration.

## Scoped update: central reporting (2026-11-06)

Journals, posting, reversals, fiscal periods and closing remain tenant-local and unchanged. Tenant-list reports can be answered from central copies on `accounting.master_data.connection`. Defaults (`reporting.change_log_enabled = false`, `reporting.read_source = live`) preserve every prior behaviour; with them, the full pre-existing suite passes unchanged. Usage is in `docs/central-reporting.md`; signatures are in `docs/reference.md`.

**Supported configuration (verified in source and tests):** shared master data only.
- `accounting.master_data.use_shared_database` must be true, and `master_data.connection` must name a configured connection.
- `Account`, `AccountCategory` and `Service` must resolve to that connection, and their tables must exist on it.
- `CentralReportingRepository::assertSupported()` enforces this and throws `RuntimeException`. It runs:
  - in the central reporting migration, which creates nothing unless the check passes (`supported()`), so unsupported installs migrate normally;
  - via `assertUsable()` (which also requires `acc_reporting_sources`) at the start of every `ReportingSyncService` method and every `accounting:reporting-*` command (commands print the message and exit 1);
  - via `assertUsable()` in `CentralReportContext::run()` before any central read.
- Tenant-local master installs keep every existing live behaviour. `ReportingChangeLog::enabled()` is false for them even when `change_log_enabled` is true, so their accounting writes are unaffected and no outbox rows accumulate.
- Accounts, categories and services are never copied. Central reports read the existing master tables, inside the same central read transaction as the tenant copies. Copied journal details and snapshots keep their central account/service ids.

**Tenant outbox** (`database/migrations/accounting/2026_11_06_000018_create_acc_reporting_change_log_table.php`, transaction-owned, guarded):
- Created by the normal tenant migrations in every mode. It is harmless and unused unless recording is enabled.
- Columns: `{id, aggregate_type journal|month|cutoff, aggregate_key, operation, created_at, acknowledged_at}`.
- `src/Support/ReportingChangeLog.php` (provider singleton) writes on the journal model connection.
- Explicit records, inside the existing transactions:
  - `JournalService::createJournalEntry()`: one row per created journal, plus the reversed original. When enabled it wraps header, details and log in a transaction, so `journalManual($data, false)` without a caller transaction becomes atomic.
  - `postWithCutoff()` is transactional when enabled.
  - `AccountOpeningBalanceService::replaceOpeningBalanceJournal()` records `updated`.
  - `ClosingService` month close and reopen (`month:YYYY-MM`) and `changeCutoff()` (`cutoff:1`).
- Model listeners, registered in provider `boot()` and inert when disabled:
  - `JournalEntry` / `JournalEntryDetail` Eloquent create, update and delete (muted inside `createJournalEntry`);
  - `FiscalPeriod` create and delete (implicit `firstOrCreate` periods).
- Master deletions need no listener. With shared master data, master rows live on another connection and no foreign key cascades into tenant tables. Dangling account ids behave the same in live and central reads.
- Query-builder/raw SQL writes are unsupported for the outbox and are left to reconciliation.

**Central tables** (`database/migrations/accounting/2026_11_06_000019_create_acc_reporting_central_tables.php`, in the unified auto-loaded folder; the `accounting-reporting` directory, its publish tag and the never-deployed `000020` chart-copy drop migration were removed):
- Creates five `acc_reporting_*` tables:
  - `sources`: sync, backfill and reconcile state, freshness, lease and local cutoff metadata;
  - `journal_entries`, `journal_entry_details`, `fiscal_periods` and `monthly_balances`, keyed by `(tenant_id, id)`.
- Every copied row stores its exact source attributes in `payload`, plus typed query columns.
- Ownership via `HandlesCentralMigration`:
  - tables are created only when the run's default connection is the master connection (`migrate --database`, or a tenancy bootstrapper's per-tenant switch, sets it) and the configuration is supported;
  - existing tables are never recreated, and `down()` drops only on the master-connection run;
  - `tests/Feature/CentralReportingSyncTest.php` runs the whole folder as a tenant run and checks that no central table is created there or changed centrally.
- `src/Repositories/CentralReportingRepository.php` owns configuration validation, leases with in-transaction fencing, atomic replace operations, readiness, backoff and the central report reads.

**Sync** (`src/Services/ReportingSyncService.php`, new provider singleton):
- Methods `backfill`, `sync`, `reconcile`, `prune`, `status`, exposed as `accounting:reporting-backfill|sync|reconcile|prune|status` in `src/Console/Commands/`. These are the package's first commands, registered in console. The host owns scheduling.
- Each tenant runs through `TenantReportRunner::run([$id])` under a central lease. The master connection must differ from the tenant transaction connection.
- Outbox rows are read in id order. Item state is re-read in one tenant read transaction (REPEATABLE READ on MySQL/MariaDB/PostgreSQL) and replaced in one central transaction. Exactly the fetched rows are acknowledged after the commit.
- Months are replaced as whole period and snapshot sets, so reclosing replaces and reopening keeps rows.
- `synced_through` (the run start) advances only when a run drains every row.
- Failures set `sync_status = failed` with exponential backoff (`deferred` until retried).
- Reconciliation compares order-independent per-month digests of every source row against the central payloads. It repairs mismatched months (up to three attempts) or, with detect-only, marks the tenant `drifted`. Backfill always ends with a reconcile.

**Reads:**
- `TenantReportRunner::runReports()` dispatches by `read_source`. `resolveTenants()` is extracted from `run()` with unchanged validation and error order.
- `TenantReportRunner::readSource()` (public, 2026-11-09) is the single resolver of `read_source`: a missing key or `reporting` block (older published or cached configs), `null` and empty/whitespace values mean `live`; values are trimmed and case-insensitive; anything else throws `RuntimeException` before tenants are resolved. Verified by `tests/Feature/ReportingReadSourceTest.php`, which also checks that live mode works with the central tables and tenant outbox dropped and never queries `acc_reporting_*`, and that central mode rejects every tenant-list entry point (no live fallback) when tables are missing or a tenant is not ready. The entry-point list is shared with `CentralReportingParityTest` through `CentralReportingFixture::reports()`.
- `src/Support/CentralReportContext.php` (provider singleton) validates the configuration, then the readiness of all selected tenants. It runs the same report callbacks inside one central transaction and never initializes tenancy.
- `ReportService`, `ConsolidatedReportService`, `AccountBalanceService` (snapshot query, journal totals, earliest journal date) and `GeneralLedgerService::accountActivityTotals()` read snapshots, fiscal periods, snapshot counts, the earliest posted/journal date, legacy-sign rows and posted-movement queries through loaders that prefer the active central context. Master data comes from the unchanged `AccountCategoryRepository` / `AccountRepository` / `ServiceRepository` / `Account` calls. Calculations, payloads and stable-code checks are unchanged.
- Central mode covers every tenant-filtered report path (audited 2026-11-06):
  - `ReportService::profitLoss|balanceSheet|profitLossRange|profitLossDetail`;
  - every tenant-list `ConsolidatedReportService` method, including `categoryTree()` (and `categoryBalanceTree()`, added 2026-11-10); its tenant id comes from `CentralReportContext::tenantId()` before the tenancy-based `categoryTenantIdentifier()`;
  - `AccountCategoryTreeService::index()` with tenant lists;
  - `GeneralLedgerService::getAccountBreakdown()`;
  - `ProfitLossSummaryService`, and therefore the `tenant_ids` HTTP report endpoints.
- No fallback: an unsupported setup, missing tables or an unready tenant reject the call.
- Live in both modes: calls without a tenant list, methods with no tenant filter (`ReportService::generalLedger|trialBalance|cashFlow`, `GeneralLedgerService` ledger/detail) and all closing operations.
- A selected tenant that is unregistered, not backfilled, failed, drifted or older than `reporting.max_lag_seconds` rejects the whole request under `reporting_sync`.

**Tests** (local suite on PHP 8.4.20: 427 tests, 3115 assertions, all passing). They use SQLite and a host-tenancy double; deployed tenancy, other drivers and real concurrent processes are unverified.
- `tests/Feature/CentralReportingParityTest.php`:
  - 29 PHP entry points plus HTTP, identical live versus central with tenant databases disconnected and no tenancy initialization. These include category tree/index balances (snapshot, carry-forward, pre-activity months) and account breakdowns. Each fix was mutation-checked: reverting it fails this test;
  - master tables read directly, central ids in the copies, master edits visible without sync;
  - unsupported configurations: tenant-local master data and missing master tables skip the migration and are rejected by sync, commands and reads; missing central tables are reported with instructions;
  - every readiness rejection reason;
  - direct-SQL repair.
- `tests/Feature/CentralReportingSyncTest.php`:
  - outbox paths, atomicity and rollback;
  - Eloquent deletions;
  - backfill with concurrent and unlogged changes;
  - incremental, idempotent sync;
  - reclose/reopen;
  - out-of-order outbox ids;
  - failed acknowledgement and failed central commit;
  - leases and fencing, backoff, batching and freshness;
  - unified-folder migration ownership (tenant runs and rollbacks, idempotent central re-runs, no master connection), prune and commands.
- Shared fixture: `tests/Feature/Concerns/`.

## Scoped update: global monthly closing (2026-11-09)

Existing closing methods, signatures and return values are unchanged. Usage, statuses, codes and scheduling are in `docs/reference.md` (section "Global monthly closing").

**Entry points (verified in source and tests).**
- `ClosingService` (`src/Services/ClosingService.php`) adds `closeMonthGlobally()`, `getGlobalCloseRun()`, `retryGlobalCloseRun()`, `recoverGlobalCloseRuns()`, `processGlobalCloseTenant()` and `defaultGlobalCloseMonth()`. No new service was added.
- Queued job `src/Jobs/CloseTenantMonthJob.php` is the package's first job. It holds only the tracking item id and calls `processGlobalCloseTenant()`.
- Command `accounting:closing-close-month` (`src/Console/Commands/ClosingCloseMonthCommand.php`), registered in console. The host owns the schedule and the queue worker.
- Persistence lives in `src/Repositories/ClosingRunRepository.php`, a provider singleton.

**Database ownership.**
- `database/migrations/accounting/2026_11_09_000020_create_acc_closing_runs_tables.php` creates prefixed `closing_runs`, `closing_run_tenants` (unique per run and tenant) and `closing_run_locks` (primary key tenant, year, month).
- They live only on `master_data.connection`, through `HandlesCentralMigration`, whether or not `use_shared_database` is set. Tenant runs create nothing.
- Fiscal periods, snapshots and outbox rows remain tenant-local and are written by the unchanged `closeMonth()`.

**Tenant behavior.**
- Null or `[]` selects every tenant of `tenancy.tenant_model` via the new `TenantReportRunner::allTenantIds()`. Explicit lists go through `resolveTenants()` (aliases deduplicated) before the run is saved.
- Each tenant runs inside `TenantReportRunner::run([$id])`, which restores the previous tenant and default connection.
- Single-database installs without a tenancy model cannot use this feature.

**Flow.**
- Tracking rows commit in one master transaction. Jobs are queued with `afterCommit()`.
- A claim is a conditional update on the item plus a tenant/period lease shared by all runs. Expired leases (`closing.global.lease_seconds`, default 900) can be taken over. Outcome writes are fenced by the claim token.
- Each attempt rechecks the fiscal period: `already_closed`, `not_due` (closing day), or for scheduled runs `not_due`/`reopened_period` when snapshots are retained or an earlier run closed it. Otherwise it calls `closeMonth()`.
- Failures are mapped to readable codes and reported through the host exception handler as `Exceptions/ReferencedAccountingException` (original as previous, `reference` in context). Messages are localized from `lang/{en,id}/closing.php`, loaded with namespace `accounting`. The new `accounting-lang` publish tag exposes them.
- Scheduled runs: one per period. Later scheduled calls resume it rather than starting a new run.
- Shared cutoff state and scope are never changed by a global close.

**Supporting changes.**
- `closeMonthlyPeriod()` now throws `Exceptions/ClosingPeriodException` (a subclass of `Exception`; messages unchanged) with a `reason`.
- The posted-totals query and the latest-due-month loop were extracted into `postedMonthTotals()` / `latestDueMonth()` with identical behavior.

**Reporting sanitization.**
- `ReportingSyncService::runTenant()` reports failures through `Support/AccountingErrorReporter` (reference ID).
- `CentralReportingRepository::recordFailure()` gained an optional `$reference` and stores readable text. Database failures become `a database error occurred (SQLSTATE x)`; other messages are unchanged.
- `readiness()` / `status()` also sanitize legacy stored `last_error` values containing SQL. Before this, `assertReady()` validation messages exposed raw SQL to central-mode report callers.

**Tests.** `tests/Feature/GlobalMonthlyClosingTest.php` (SQLite; 14 tests at introduction, 18 since 2026-11-11) covers:
- both master modes, default/explicit/alias tenant selection, and progress flags mid-close;
- a foreign-key partial failure with log reference and retry of failed tenants only;
- unbalanced and unavailable tenants, with context restoration;
- duplicate jobs, held leases across runs, crash recovery, a committed-but-unrecorded close, and fencing;
- dispatch after commit and caller rollback;
- scheduled reopen protection and resume, `not_due` in `en` and `id`;
- shared-cutoff preservation, migration ownership, the command, and reporting sanitization.
- Since 2026-11-11, the real `sync` driver (no `Queue::fake()`) with `closing.global.queue_connection = sync` while the host default never runs jobs: three tenants with one failing, after-commit inside a caller transaction, inline retry and context restoration. Null, empty and missing connection settings follow `queue.default`.
- Not covered by tests, but confirmed by a throwaway probe: under `sync`, a job-level exception (master write failure) is rethrown to the caller, leaving later tenants `pending` until `recoverGlobalCloseRuns()`. A busy lease leaves the tenant `pending`, because `SyncJob::release()` does not requeue.

## Scoped update: global monthly reopening (2026-11-11)

Extends the global monthly closing above; no new service, job or tracking table. Usage, statuses, codes and examples are in `docs/reference.md` (section "Global monthly reopening").

**Entry points (verified in source and tests).**
- `ClosingService::reopenMonthGlobally(int $year, int $month, ?array $tenantIds, string $reason, int|string|null $actorId = null): array` (`src/Services/ClosingService.php`). Tenant resolution is shared with `closeMonthGlobally()` through `globalRunTenantIds()`.
- `processGlobalCloseTenant()` branches on the run's operation and calls the new `reopenTenantMonthForRun()`, which delegates to the unchanged-rules `reopenMonth()`. The same `CloseTenantMonthJob`, queue routing, claims, leases, `retryGlobalCloseRun()` and `recoverGlobalCloseRuns()` serve both operations.
- `accounting:closing-close-month` gained `--reopen` and `--reason=`. `--status`, `--retry` and `--recover` work for both operations; close options and output are unchanged.

**Database ownership.**
- `database/migrations/accounting/2026_11_11_000021_add_operation_to_acc_closing_runs_table.php` adds `operation` (default `close`, so existing runs are close runs), `reason`, `reopened_count` and `already_open_count` to `closing_runs`. Master connection only (`HandlesCentralMigration`); no-op on tenant runs or without the table.
- Close runs omit the new columns on insert, so global closing still works before this migration (verified by the existing closing tests, which do not run it). `reopenMonthGlobally()` requires it (`ClosingRunRepository::assertUsable(true)`).
- Fiscal periods, retained snapshots and the `month reopened` outbox row stay tenant-local, written by `reopenMonth()` in one tenant transaction.

**Public contract changes (additive).**
- `ClosingRunRepository`: `REOPEN_TENANT_STATUSES`, `OPERATIONS`, static `operation()`, `reopenedByRun()`, `latestPeriodOutcomes()`; `assertUsable(bool $reopen = false)`; `createRun()` gained optional `$operation = 'close'`, `?string $reason = null`. `TENANT_STATUSES` is unchanged.
- Run arrays gain `operation` and `reason`; tenant entries gain `notice` and `tracked_period`. Reopen-run `counts` use the reopen statuses; close-run `counts` keys are unchanged.
- `ClosingPeriodException` gained `NOT_CLOSED` and `LATER_MONTH_CLOSED`; `reopenMonth()` now throws it instead of a plain `Exception`, with identical messages. No package route or responder handled these exceptions.
- `lang/{en,id}/closing.php` gained a `reopen` group; `errors.*` is the fallback.

**Behavior.**
- Tenant statuses: `pending`, `processing`, `reopened`, `already_open`, `failed`. Failures: `later_month_closed`, `period_not_found`, plus the shared closing codes with reopen wording, all logged with a reference (`operation: reopen` in context).
- `cutoff_active` notice (no reference) when a per-tenant cutoff, or a shared cutoff whose scope includes the tenant, still covers the month. Cutoffs and the shared scope are never changed; `reopenCutoff()` stays explicit. A failure to read the cutoff is logged and does not fail the reopen.
- Crash recovery: an open period counts as `reopened` when an earlier attempt of the same item started at or before the period's `updated_at` and the month still has snapshots. Assumption: PHP-generated timestamps share the application timezone on both connections.
- Scheduled closing also skips periods with a `reopened` item in any reopen run (`reopenedByRun()`), even when snapshots were deleted. Close-run rows are never rewritten; `tracked_period` reports the latest outcome across global runs only.

**Tests.** `tests/Feature/GlobalMonthlyReopeningTest.php` (11 tests, SQLite) covers:
- both master modes, default selection, audit fields, retained snapshots, the outbox row, close-run history and `tracked_period`;
- later-month and missing-period failures (`en`/`id`), latest-first retry, and input validation;
- unavailable tenants with caller context restoration;
- duplicate jobs, a close/reopen shared lease, takeover and fencing;
- crash recovery of a committed reopen versus `already_open`;
- scheduler protection without snapshots, shared-cutoff preservation and notice, central sync, the command, and migration ownership.

The full suite passed: 479 tests.

**Unverified.**
- Concurrent workers on production drivers.
- Real queue backends other than `sync`/fake.
- Host tenancy bootstrappers in queued jobs.

## Scoped update: category balance tree (2026-11-10)

**Entry point (verified in source and tests).** `src/Services/ConsolidatedReportService.php::categoryBalanceTree(int $year, int $month, array $tenantIds): array` is a PHP-only method on the existing service. It adds no service, binding, route, controller, cache, migration or configuration. It returns `{tenant_ids, period, status, snapshots: [{tenant_id, status, reason}], data}`. Category and account nodes carry `normal_balance`, `opening_balance`, `total_debit`, `total_credit` and `ending_balance`. Usage, statuses and a response example are in `docs/reference.md` ("Category balance tree").

**How it works.**
- **Validation:** the period is validated with `ProfitLossSummaryCalculator::period()`, then the tenant list by `TenantReportRunner::runReports()`. Both happen before any tenant runs.
- **Execution:** `runReports()` honours `reporting.read_source`; `live` reads tenant databases, `central` reads central copies without initializing tenancy.
- **Source data:** each source reads the same data as `categoryTree()`: all categories, plus `visibleOrdered()` accounts for that tenant. It also reads `AccountBalanceService::getBalances()` with all four fields.
- **Merging:** after restoration, `mergeSources()` performs the existing hierarchy and stable-code compatibility checks. The four fields are summed once per account code across tenants.
- **Subtotals:** these are rebuilt by sign-aware addition. A child category with the opposite normal balance contributes negated opening/ending and unchanged movements, so `ending = opening ± movement` holds on every node. Category types outside the five known ones fail under `consolidation`.

**Snapshot status.**
- **Shared rules:** `balanceSheetSnapshotStatuses()` was split into `snapshotChainState()`, `snapshotChainStatus()` (which now also returns a reason) and `monthIndex()`. Balance-sheet summary statuses are unchanged; the 47 `BalanceSheetSummaryServicesTest` tests pass.
- **Statuses:**
  - `current`: the existing chain rules pass; amounts are snapshots.
  - `open`: the month has no snapshot rows, and the latest earlier snapshot month is `current` or absent; amounts are calculated from posted journals.
  - `stale`: otherwise, with reason `reopened`, `broken_chain` or `legacy_sign` from the month or its carry-forward base. Stale tenants are not queried for amounts.
- **Combined result:** any stale tenant makes the overall status `stale` and nulls every amount. `getBalances()` itself is unchanged; it still prefers any matching snapshot, which is why this method checks status first.

**Tests.** Full local suite on PHP 8.4.20: 468 tests, 3830 assertions, all passing. Uses SQLite and a host-tenancy double; deployed tenancy bootstrappers are unverified.
- `tests/Feature/CategoryBalanceTreeTest.php` (21 tests) uses real `ClosingService` closes and reopen/reclose in both master modes. It covers:
  - per-node arithmetic, the mixed-type subtotal, and ownership (another tenant's account, a hidden tenant-c account);
  - alias deduplication and context restoration;
  - snapshot use, zero months before any activity, and every stale reason;
  - validation before any tenant runs;
  - unsupported types, definition conflicts, and an unchanged `categoryTree()` shape.
- `tests/Feature/Concerns/CentralReportingFixture.php::reports()` gains six `categoryBalanceTree` entries (current, open, stale and stale-base months). `CentralReportingParityTest` therefore checks live/central equality without tenant databases, and `ReportingReadSourceTest` checks central rejection without fallback.

## Evidence and scope

- **Verified from source:** Composer metadata, all package PHP classes, routes, configuration, migrations, seeder implementations and representative localized data, hook stubs, and test definitions were inspected. Existing documentation was checked against source.
- **Static findings / inferred risks:** Limitations identified by reading code are called out explicitly below. They were not reproduced against a running host application.
- **Unverified:** Actual host integration, deployed schema/data, tenant lifecycle, cache backend, and behavior across every declared Laravel/database version. No application was booted, migrations or seeders run, dependencies installed, or test suite executed during this documentation-only investigation.
- `README.md` and `docs/` provide examples and design intent, but contain stale or aspirational descriptions. Executable source, migrations, and existing contracts take precedence when determining current behavior.

## Architecture and bootstrap

This is an embedded Laravel library, not a standalone application. Its core is a journal engine with configurable service-to-account mappings, category-based chart of accounts, monthly closing snapshots, and JSON APIs. Host applications provide operational transactions and the Laravel runtime.

| Area | Verified location and role |
| --- | --- |
| Package identity | `composer.json`: `elgibor-solution/laravel-accounting`; PHP `>=8.2`; `laravel/framework` `^11.0|^12.0|^13.0` |
| Main namespace | `ESolution\LaravelAccounting\` maps to `src/` through PSR-4 |
| Database namespaces | `ESolution\LaravelAccounting\Database\Seeders\`, `Database\Migrations\`, and `Database\Factories\` map to the corresponding package directories; migrations themselves are anonymous classes |
| Bootstrap | `src/AccountingServiceProvider.php`, auto-discovered through Composer `extra.laravel.providers` |
| Facade | `src/Facades/Accounting.php`; Composer alias `Accounting`; accessor `laravel-accounting` aliases `Services\AccountingService` |
| Configuration | `config/accounting.php`, merged under `accounting` by the provider |
| HTTP entry | `routes/api.php`, loaded unconditionally by provider boot; routes use configured middleware plus `accounting.api.exceptions` |
| Database setup | `database/migrations/accounting/` (the only migration folder), automatically loaded while running in console; publish tag `accounting-migrations` copies them to host `database/migrations/accounting`. Master-connection-only migrations (`000017`, `000019`) act only on runs whose default connection is the master connection |
| Published config | Console publish tag `accounting-config` copies config to host `config/accounting.php` |
| Exception integration | Provider aliases `Http\Middleware\HandleAccountingApiExceptions` and registers a renderable callback when the host exception handler supports `renderable()` |
| Host examples | `stubs/hooks/BeforeApiHook.php.stub`, `stubs/hooks/AfterApiHook.php.stub`, `postman_collection.json`, `penjualan_ppn.md` |

The usual dependency direction is HTTP controller or facade -> service -> repository/model -> configured database connection. Some controllers write Eloquent models directly; services also use query builder joins and raw aggregates. There is no uniform repository-only persistence boundary.

The provider registers these concrete singleton bindings:

- Support: `ServiceCatalog`, `ServiceAccountTemplateRegistry`, `AccountingConnectionResolver`, `AccountingTableResolver`, `AccountingExceptionResponder`, `AccountingHookManager`, `ProfitLossSummaryCalculator`, `ReportingChangeLog`, `CentralReportContext`.
- Repositories: `AccountCategoryRepository`, `AccountRepository`, `ServiceRepository`, `ServiceAccountRepository`, `JournalRepository`, `FiscalPeriodRepository`, `CentralReportingRepository`.
- Services: `AccountingService`, `JournalService`, `CoaService`, `AccountCategoryTreeService`, `AccountBalanceService`, `GeneralLedgerService`, `AccountOpeningBalanceService`, `MappingService`, `ClosingService`, `FiscalPeriodService`, `ReportService`, `ConsolidatedReportService`, `ProfitLossSummaryService`, `ServiceManagementService`, `ReportingSyncService` (2026-11-06).

Services mix constructor injection with `app(...)` lookups. Bindings are concrete classes, not repository/service interfaces. Model connection resolution is dynamic rather than captured at singleton construction.

## Configuration contract

All keys are in `config/accounting.php`.

| Key | Default | Actual use |
| --- | --- | --- |
| `table_prefix` | `acc_` | Models, migrations, and query builders construct accounting table names |
| `default_language` | `env('ACCOUNTING_DEFAULT_LANGUAGE', 'id')` | Seeder datasets; `id` and `en`, otherwise Indonesian fallback |
| `master_data.use_shared_database` | `env('ACCOUNTING_USE_SHARED_DATABASE', false)` | Enables shared master connection resolution |
| `master_data.connection` | `env('ACCOUNTING_MASTER_CONNECTION')` | Named host database connection for master models/schema (only when `use_shared_database` is true); also the central store for shared closing cutoffs regardless of that flag, and for central reporting tables (which additionally require `use_shared_database` = true) |
| `journal.auto_post` | `true` | Controls posting after **mapped** journal creation; manual/opening journals post immediately regardless |
| `journal.number_format` | `JV/{YEAR}/{MONTH}/{SEQ}` | Number substitution in `JournalService::generateJournalNo()` |
| `profit_loss_summary` | Literal `account_mappings` / `category_mappings` arrays | Editable in the host's published config and compatible with config caching; additive stable-code classifications for the two summary entry points only; account rules override category rules |
| `fiscal.start_month` | `1` | Fiscal-year start month (1–12); consumed only by `ConsolidatedReportService` balance-sheet summaries for `current_year_profit_loss` (validated before tenant runs) |
| `balance_sheet_summary` | `category_mappings`: `CURRENT_ASSET`/`CURRENT_LIABILITY` => `current`; empty `account_mappings` | Stable code => `current`/`non_current` for balance-sheet summary current ratio; merged over `ConsolidatedReportService::DEFAULT_BALANCE_SHEET_MAPPINGS`; account overrides beat nearest mapped ancestor category; unmapped = non-current |
| `profit_loss_detail` | `closing_service_codes` and `closing_source_types`: `MONTH_END_CLOSING`, `YEAR_END_CLOSING` | Journal markers excluded as profit transfers by balance-sheet profit details (with opening-balance source types, always excluded); missing key keeps default, `[]` disables |
| `closing.closing_day` | `env('ACCOUNTING_CLOSING_DAY')` (null) | Earliest month-close execution day: null (unrestricted), 1–31 (clamped), or `month_end` |
| `closing.closing_day_month` | `env('ACCOUNTING_CLOSING_DAY_MONTH', 'next')` | Month the closing day belongs to: `current` or `next` |
| `closing.cutoff_enabled` | `env('ACCOUNTING_CLOSING_CUTOFF_ENABLED', false)` | Enables persisted inclusive date cutoffs |
| `closing.cutoff_mode` | `env('ACCOUNTING_CLOSING_CUTOFF_MODE', 'per_tenant')` | `per_tenant` (transaction DB) or `shared` (on `master_data.connection`) cutoff storage |
| `closing.global.*` | `queue_connection` / `queue` null (host defaults), `lease_seconds` 900 | Global monthly closing and reopening job routing and claim lease (2026-11-09; reopening 2026-11-11) |
| `reporting.*` | `read_source` `live`, `change_log_enabled` false, `max_lag_seconds` 900, `batch_size` 500, `lock_seconds` 600, `retry_base_seconds` 60, `retry_max_seconds` 3600, `prune_after_days` 7 | Central reporting outbox, sync and read source (2026-11-06 scoped update) |
| `route.prefix` | `api/accounting` | Route group and exception-responder request matching |
| `route.middleware` | `['api']` | Host-configurable route middleware; exception middleware is always appended and deduplicated |
| `hooks.before`, `hooks.after` | `null`, `null` | Optional host hook classes resolved through the container |

Changing prefixes or connection configuration does not relocate existing tables/data. Laravel connection-level table prefixes are additionally handled by `src/Support/AccountingTableResolver.php` for raw SQL fragments; query-builder table names use the accounting prefix and Laravel applies its own connection prefix.

## Module inventory and dependencies

These are functional areas within one package, not separately registered Laravel modules.

| Module | Important files | Responsibility and dependencies |
| --- | --- | --- |
| Entry facade | `src/Services/AccountingService.php`, `src/Facades/Accounting.php` | Resolves journal, CoA, mapping, closing, and report services; looks up configured business services via `ServiceCatalog` and `ServiceRepository` |
| Categories / CoA | `src/Http/Controllers/Api/AccountCategoryController.php`, `src/Services/AccountCategoryTreeService.php`, `src/Services/CoaService.php`, `src/Repositories/AccountCategoryRepository.php` | Category CRUD, filtering, ancestor paths, recursive trees and descendant balances; depends on accounts and optional live balances |
| Accounts / ownership | `src/Http/Controllers/Api/AccountController.php`, `src/Repositories/AccountRepository.php`, `src/Models/Account.php` | Posting account CRUD, visibility, category enrichment, period balances; calls account opening-balance service for create/update |
| Account opening balances | `src/Services/AccountOpeningBalanceService.php` | Creates account-specific posted opening journals against account code `3001`, exposes edit metadata, and conditionally updates those journals; depends on journal service and both connection families |
| Business services / mapping management | `src/Http/Controllers/Api/ServiceController.php`, `src/Services/ServiceManagementService.php`, `src/Services/MappingService.php`, `src/Repositories/ServiceRepository.php`, `src/Repositories/ServiceAccountRepository.php` | CRUD of service definitions and mapping sets; active mapping lookup and service-code lookup; service create/update uses hooks and a master-connection transaction |
| Catalog / templates | `src/Enums/AccountingServiceCode.php`, `src/Support/ServiceCatalog.php`, `src/Support/ServiceAccountTemplateRegistry.php` | In-memory service definitions and account-code templates; runtime journal creation uses database mappings, not the template registry |
| Journal engine | `src/Http/Controllers/Api/JournalController.php`, `src/Services/JournalService.php`, `src/Repositories/JournalRepository.php` | Mapped/manual/opening journal creation, posting, reversing, listing/detail hydration; depends on services, mappings, accounts, fiscal periods, tagged cache |
| Fiscal periods / close | `src/Services/FiscalPeriodService.php`, `src/Repositories/FiscalPeriodRepository.php`, `src/Services/ClosingService.php` | Monthly period creation, closed-period checks, close/reopen and snapshot balances; reads journals and category types; no dedicated HTTP controller |
| Live balance engine | `src/Services/AccountBalanceService.php` | Returns stored monthly snapshot if present; otherwise derives opening/current/ending balances from previous snapshots and posted movements; used by account/category endpoints and monthly ledger |
| General ledger | `src/Http/Controllers/Api/ReportController.php`, `src/Services/GeneralLedgerService.php`, `src/Support/GeneralLedgerPaginator.php` | Monthly summary and full-period running balances with paginated details; depends on account/category repositories, live balance service, transaction database |
| Financial reports | `src/Services/ReportService.php` | Legacy date-range ledger opening summary and snapshot-backed trial balance, profit/loss, balance sheet, cash flow; depends on category tree, accounts, monthly snapshots |
| Arithmetic consolidation | `src/Services/ConsolidatedReportService.php` | Separate profit/loss and balance-sheet consolidation by stable codes; depends on ordinary reports, tenant runner and account/category repositories; validates definitions and recalculates totals without eliminations |
| Profit/loss summaries | `src/Services/ProfitLossSummaryService.php`, `src/Services/ConsolidatedReportService.php`, `src/Support/ProfitLossSummaryCalculator.php` | Separate summaries via `summarize()` and consolidated summaries via `profitLossSummary()`; share configurable code classifications and four-stage profit calculations; reuse existing report/consolidation paths |
| Database infrastructure | `src/Models/AccountingModel.php`, `MasterDataModel.php`, `TransactionDataModel.php`, `HasUuid.php`, `src/Support/AccountingConnectionResolver.php`, `AccountingTableResolver.php`, `src/Traits/HandlesMasterConnection.php` | UUIDs, table/validation names, master/transaction routing, schema routing |
| HTTP infrastructure / hooks | `src/Http/Controllers/BaseController.php`, `src/Traits/ApiResponse.php`, `src/Support/ApiContext.php`, `AccountingHookManager.php`, `AccountingExceptionResponder.php`, `src/Http/Middleware/HandleAccountingApiExceptions.php` | Tenant initialization, mutation hooks, response helpers, tagged cache helpers, error rendering |
| Master installation data | `database/seeders/`, `database/factories/AccountFactory.php` | Localized category/account/service/mapping data and test account factory; relationships resolved by codes |

### Business catalog modules

`ServiceCatalog` groups 37 enum-backed service codes. These are accounting templates for host workflows, not implemented inventory, invoicing, payroll, or tax subsystems. Every group integrates through `JournalService::journalByMapping()` and database `Service` / `ServiceAccount` records.

| Catalog group / `module_name` | Codes and accounting responsibility |
| --- | --- |
| Sales / `SALES` | `SALES_CASH`, `SALES_CREDIT`, `SALES_CASH_VAT`, `SALES_CREDIT_VAT`, `SALES_RETURN`, `SALES_DISCOUNT`, `SALES_WRITE_OFF`: sales, receivables, output tax, COGS/inventory and adjustments |
| Purchase / `PURCHASE` | `PURCHASE_CASH`, `PURCHASE_CREDIT`, `PURCHASE_RETURN`: inventory acquisition, cash/payables, returns |
| Inventory / `INVENTORY` | `STOCK_OPENING`, `STOCK_ADJUSTMENT_PLUS`, `STOCK_ADJUSTMENT_MINUS`, `STOCK_TRANSFER`: opening and adjustments; transfer has no default mapping template |
| Finance / `FINANCE` | `CASH_IN`, `CASH_OUT`, `BANK_TRANSFER`, `JOURNAL_MANUAL`, `PETTY_CASH`: receipts, disbursements, transfers; manual has no default template |
| Expense / `EXPENSE` | `EXPENSE`, `PREPAID_EXPENSE`: expenses and prepaid entries |
| Payroll / `PAYROLL` | `PAYROLL`, `PAYROLL_ACCRUAL`: payment and accrual |
| Assets / `ASSET` | `ASSET_PURCHASE`, `ASSET_DEPRECIATION`, `ASSET_DISPOSAL`, `ASSET_REVALUATION`: acquisition, depreciation, disposal, reserves |
| Receivables / `ACCOUNT_RECEIVABLE` | `CUSTOMER_RECEIVABLE_PAYMENT`, `CUSTOMER_RECEIVABLE_WRITE_OFF` |
| Payables / `ACCOUNT_PAYABLE` | `VENDOR_PAYMENT`, `VENDOR_PAYABLE_WRITE_OFF` |
| Tax / `TAX` | `TAX_OUTPUT`, `TAX_INPUT`, `VAT_PAYMENT`, `TAX_PAYMENT` |
| Closing / `CLOSING` | `MONTH_END_CLOSING`, `YEAR_END_CLOSING`: mapping templates; no automatic year-end transfer engine |

VAT descriptions mention 11%, but the engine accepts amounts supplied by the caller; no tax-rate calculation or current tax-law guarantee is implemented. Likewise, no stock quantities, invoice settlement, payroll calculation, depreciation schedule, or external ERP connector is present.

## Public PHP APIs

These public methods are integration surfaces even when no route exposes them. Classes below are under `src/Services/` unless otherwise noted.

| Entry | Public methods / return behavior |
| --- | --- |
| `AccountingService` / facade | `journal()`, `coa()`, `mapping()`, `closing()`, `report()` resolve services; `service(string\|AccountingServiceCode)` returns nullable `Service`; `catalog(): ServiceCatalog` |
| `JournalService` | `journalByMapping(array)`, `journalManual(array, bool $wrapTransaction = true)`, `journalOpeningBalance(array)`, `post($id, int\|string\|null $postedBy = null)`, `reverse($journalId, string $reason, int\|string\|null $postedBy = null)`; journal models returned |
| `CoaService` | `createAccount(array)`, `getTree()`, `activateAccount($id)`, `deactivateAccount($id)`; see status-column limitation below |
| `AccountCategoryTreeService` | `index(array $filters = [], ?array $tenantIds = null): Collection\|LengthAwarePaginator` (PHP-only current-context or consolidated retrieval), `getCategories()`, `getTree(?Collection, ?Collection)`, `buildNode(AccountCategory, ?Collection, ?Collection)`, `getDescendants(AccountCategory)`, `buildPath(AccountCategory)`, `flatten(Collection)` |
| `AccountOpeningBalanceService` | `createAccount(array): Account`, `updateAccount(Account, array): Account`, `resolveOpeningBalanceData(Account): ?array`; constants `SOURCE_TYPE = 'ACCOUNT_OPENING_BALANCE'`, `OPENING_BALANCE_EQUITY_CODE = '3001'` |
| `AccountBalanceService` | `getBalances(array $accountIds, ?int $year = null, ?int $month = null): Collection`, keyed by account ID with `opening_balance`, `total_debit`, `total_credit`, `ending_balance`; `applyMovement(float, float, float, bool): float` |
| `MappingService` | `findByKey(string)` returns first active matching mapping; `getByService($serviceId)` returns active ordered mappings |
| `ServiceManagementService` | `create(array, ?Request = null): Service`, `update(Service, array, ?Request = null): Service`; active mappings attached; hooks also execute on direct calls |
| `FiscalPeriodService` | `ensureForDate($date): FiscalPeriod`, `ensureForJournalDate($date): FiscalPeriod`, `ensureThroughCurrentMonth(?Carbon = null): Collection` |
| `ClosingService` | `closeMonth($year, $month, $userId = null)`, `closeThroughCurrentMonth($userId = null)`, alias `closeUntilCurrentMonth($userId = null)`, `reopenMonth($year, $month, $userId = null)`; global monthly closing (2026-11-09): `closeMonthGlobally(int $year, int $month, ?array $tenantIds = null, int\|string\|null $actorId = null, bool $scheduled = false)`, `getGlobalCloseRun(int $runId)`, `retryGlobalCloseRun(int $runId, ?array $tenantIds = null)`, `recoverGlobalCloseRuns()`, `processGlobalCloseTenant(int $itemId)`, `defaultGlobalCloseMonth()`; global monthly reopening (2026-11-11): `reopenMonthGlobally(int $year, int $month, ?array $tenantIds, string $reason, int\|string\|null $actorId = null)` |
| `GeneralLedgerService` | `getLedger(string, int, int): array`; `getLedgerDetails(string $accountId, int $year, int $month, string\|int\|null $tenant = null, ?int $page = null, ?int $perPage = null): GeneralLedgerPaginator`; `getDetails(string, string, string, float, bool): array` |
| `ReportService` | `generalLedger($accountId, $startDate, $endDate)` returns account/path and opening balance only; `trialBalance($year, $month)`, `cashFlow(...)` return report arrays; `profitLoss($year, $month, ?array $tenantIds = null)` and `balanceSheet(...)` additionally support separate per-tenant payloads through `TenantReportRunner`; `profitLossDetail($year, $month, ?array $tenantIds = null)` returns `{prior_months_profit_loss, current_month_profit_loss}` (runner rows with tenant IDs) and `profitLossDetailMarkers()` returns validated closing markers |
| `ConsolidatedReportService` | `profitLoss($year, $month, array $tenantIds): array`, `balanceSheet(...)`: canonical tenant IDs, year/month and one arithmetic report; `profitLossSummary(...)`: same required arguments and `{tenant_ids, period, summary}` with nine components and four profit values; `profitLossSummaryByTenant($year, $month, array $tenantIds): array` / `profitLossSummaryRangeByTenant(array $range, array $tenantIds): array`: compact, separate tenant rows with four calculated profits; `categoryTree(array $tenantIds, ?int $year = null, ?int $month = null, bool $withBalance = false): array`: full merged chart and source UUID/code aliases for service retrieval; `categoryBalanceTree(int $year, int $month, array $tenantIds): array` (2026-11-10): merged chart with opening/debit/credit/ending and `normal_balance` on every node, per-tenant `current`/`open`/`stale` snapshot status with reason, all amounts null when any tenant is stale; `balanceSheetSummary($year, $month, array $tenantIds)` / `balanceSheetSummaryByTenant(...)` / `balanceSheetSummaryByMonth(array $range, array $tenantIds)`: month-end asset/liability/equity totals, current assets/liabilities, `current_ratio`, `debt_ratio`, journal-based `current_year_profit_loss` and `balance_sheet_difference`, plus `current_year_period`, with `current`/`stale`/`missing` snapshot status and null snapshot metrics when unusable; `balanceSheetProfitDetail(...)` / `balanceSheetProfitDetailByTenant(...)` / `balanceSheetProfitDetailByMonth(...)`: informational `prior_months_profit_loss` / `current_month_profit_loss` excluding opening and closing-transfer journals |
| `ProfitLossSummaryService` | `summarize($year, $month, array $tenantIds): array`: required tenant list and period; separate tenant summaries with nine classified components and four calculated profit values |
| `Support\ServiceCatalog` | `all()`, group methods `sales()`, `salesVat()`, `purchase()`, `inventory()`, `finance()`, `expense()`, `payroll()`, `asset()`, `receivable()`, `payable()`, `tax()`, `closing()`, `find(string\|AccountingServiceCode)`, `normalizeCode(...)` (string input is unchanged) |
| `Support\ServiceAccountTemplateRegistry` | `all()` returns definitions grouped by service code; `forService(string\|AccountingServiceCode)` returns templates or `[]` |

Repository methods are public and container-resolvable (`src/Repositories/*.php`). Important distinctions: `AccountRepository::findById/findByCode/findManyByIds/allOrdered` are unscoped; the `*Visible`, `visibleQuery`, and `visibleOrdered*` methods apply ownership visibility. `JournalRepository` manually attaches service, accounts, and reversal relations, enabling master/transaction records to be fetched on different connections without cross-database joins.

Enums in `src/Enums/`: `JournalStatus` has `draft`, `posted`, `reversed`; `NormalBalance` has `debit`, `credit`; `ReportType` has `PL`, `BS`, `CF`; `AccountingServiceCode` defines the catalog above. The current reversal path leaves the original posted and creates a new posted reversing entry; it does not set the original to `reversed`.

## HTTP API and frontend contracts

Source of truth: `routes/api.php` and `src/Http/Controllers/Api/*.php`. Default base is `/api/accounting`. Every route also exists at `/api/accounting/{tenantId}/...`. Resource route names are `categories.*`, `accounts.*`, `services.*`; tenant versions start with `tenant.`. Resource placeholders are Laravel-generated (`{category}`, `{account}`, `{service}`), while extra routes use `{id}`. Controllers normalize positional IDs for both route variants; there is no implicit typed model binding in these signatures.

| Methods and suffix | Handler | Contract notes |
| --- | --- | --- |
| `GET, POST categories`; `GET, PUT/PATCH, DELETE categories/{category}` | `AccountCategoryController` resource methods | Parent/category validation, category type, code/name, report grouping, status |
| `PATCH categories/{id}/toggle-status` | `toggleStatus` | Toggles stored `status` |
| `GET, POST accounts`; `GET, PUT/PATCH, DELETE accounts/{account}` | `AccountController` resource methods | Category FK, globally unique code, name, optional description/tenant/opening balance, `is_postable`, `status` |
| `PATCH accounts/{id}/toggle-status` | `toggleStatus` | Visible-account lookup, then toggles `status` |
| `GET, POST services`; `GET, PUT/PATCH, DELETE services/{service}` | `ServiceController` resource methods | Service code/name/module, description, `updated_by`, `status`, optional mapping array |
| `PATCH services/{id}/toggle-status` | `toggleStatus` | Status alias writes `is_active` |
| `POST journals` | `JournalController::store` | Manual journal: required `trx_date`, `details` >= 2, each `account_id`, `type: D/K`, positive numeric `amount`; optional descriptions, reference, `posted_by` |
| `POST opening-balances` | `JournalController::openingBalance` | Required date and >= 2 details with account ID and signed nonzero amount; no D/K field needed |
| `GET journals` | `JournalController::index` | `page`, `per_page` (default 15), `search`, `start_date`, `end_date`, `status`; native paginator |
| `GET journals/{id}` | `JournalController::show` | Journal with service, detail accounts, reversals, and reversal-of relation when found |
| `POST journals/{id}/reverse` | `JournalController::reverse` | `reason` required, max 1000 characters; optional `posted_by`; returns original/reversal IDs |
| `GET reports/general-ledger` | `ReportController::generalLedger` | UUID `account_id`; year/month uses monthly engine, or start/end date uses legacy summary; if year is supplied it selects monthly mode |
| `GET reports/general-ledger/details` | `ReportController::generalLedgerDetails` | UUID account, required year/month, optional page >=1, per-page 1..100; unwrapped custom paginator |
| `GET reports/trial-balance`, `profit-loss`, `balance-sheet`, `cash-flow` | Corresponding `ReportController` method | Required integer year, month 1..12; wrapped report arrays. Profit/loss and balance sheet additionally accept validated, nonempty `tenant_ids[]` and return separate `{tenant_id, report}` entries. |

There are no HTTP endpoints for mapped-journal creation, explicit posting, journal edits/deletion, fiscal-period CRUD, close/reopen, standalone mapping CRUD, or catalog enumeration. Hosts call services for these supported PHP operations.

### Response and query details to preserve

- `src/Traits/ApiResponse.php`: usual success body is `{status: <HTTP code>, message, data}`. Creates/reversal return 201; deletion returns 200 with null data. Explicit helper errors use `{status, message, errors, data: null}`.
- Journal index returns Laravel's paginator directly. Account and category indexes switch to a native `LengthAwarePaginator` when **either** `page` or `per_page` is present; otherwise they use the success envelope. Account pagination queries the database; category pagination slices an assembled collection.
- Accounts index supports `search`, `category_id`, and `with=category,tree_category,balance` (comma or array syntax), with optional balance year/month defaulting to now. A `tenant_id` query parameter does not select ownership scope. Detail includes category summary, root category, nested parent chain, balance object, and nullable account opening metadata.
- Categories index supports `type` (case-insensitive; allowed values are obtained from current stored types), `parent_id`, `root_only`, `has_accounts`, `search`, and `with=accounts,children,balance`. Search includes account code/name and category code/name, retaining ancestors of matching accounts/categories. Children mode starts at roots unless a parent filter selects direct children. Balance totals sum direct and descendant accounts.
- `src/Http/Resources/AccountCategoryResource.php` exposes `is_active` derived from `status` for flat resource results. Tree nodes built by `AccountCategoryRepository` expose `status`, `report_type`, `path`, `children`, and optionally accounts/balance. These representations differ.
- Services index accepts boolean `status`; mappings returned by repository hydration are active mappings only. Service API `status` is a model alias of database `is_active`.
- Journal `posted_by` and service `updated_by` accept integer or string and normalize to trimmed nullable strings. They are caller-controlled; they are not populated from the authenticated user automatically.
- Manual creation response is a compact journal summary, not the full detail model. Opening response additionally includes numeric `amount`. Eloquent decimal casts otherwise serialize many stored monetary fields as strings.
- `GeneralLedgerPaginator::toArray()` merges standard paginator fields with ledger-wide `account`, `period`, opening/debit/credit/ending totals. Detail rows use `date`, `journal_number`, `reference_no`, `description`, `debit`, `credit`, `ending_balance`. Balances are computed across the whole period before slicing pages.
- `trialBalance` returns tree `data` and asset/liability/equity totals; profit/loss groups revenue/expense and calculates `net_income`; balance sheet groups asset/liability/equity; cash flow groups operating/investing/financing and calculates `net_cash_flow`.

No frontend assets, Blade views, JS/TS client, Vite/package.json build, or UI components are shipped. Integration is JSON HTTP and/or PHP services. `postman_collection.json` is an example collection, not a complete generated OpenAPI contract; no OpenAPI schema is present.

## Database model, relationships, and migration ownership

All nine concrete models extend `AccountingModel` through a connection family. `src/Models/HasUuid.php` generates UUID string primary keys in a creating callback; models are non-incrementing. Tables include Laravel timestamps. No soft deletes, global tenant scopes, or user/tenant foreign-key models are defined.

In this table, `acc_` is the configurable default prefix. All migrations belong to this package under `database/migrations/accounting/`, not to the host's operational business modules.

| Table / model | Family and creation migration | Columns / relationships / constraints |
| --- | --- | --- |
| `acc_account_categories` / `AccountCategory` | Master; `2026_01_01_000001_create_acc_account_categories_table.php` | Unique `category_code`; name, enum type, report type, sequence, status. Added nullable self-parent FK restricts delete. Model `parent`, ordered `children`, ordered `accounts`, plus lineage/descendant helpers. Type stored lowercase; accessor returns uppercase. |
| `acc_accounts` / `Account` | Master; `2026_01_01_000002_create_acc_accounts_table.php` | Required category FK cascade delete; nullable indexed `tenant_id` string(100), globally unique `code`, name, description, postable/status flags. Schema retains nullable self `parent_id` FK (null on delete) and `level` default 1, but these are not fillable or used by current category hierarchy. Model `category`, `mappings`. |
| `acc_services` / `Service` | Master; `2026_01_01_000003_create_acc_services_table.php` | Unique service code, name, module name, description, `is_active`, later `updated_by` string(100). `accounts` and alias `mappings` both relate to service mappings. |
| `acc_service_accounts` / `ServiceAccount` | Master; `2026_01_01_000004_create_acc_service_accounts_table.php` | Service FK cascade, nullable account FK null-on-delete, globally unique `mapping_key`, name, enum D/K position, sequence, dynamic/required/status flags. `service`, `account` relations; `active()` filters status; `is_active` aliases status. |
| `acc_journal_entries` / `JournalEntry` | Transaction; `2026_01_01_000005_create_acc_journal_entries_table.php` | Unique journal number, date, optional service ID/source type/UUID source ID/reference/description, decimal(18,2) amount, status enum, nullable posted-by string(100)/posted-at. Service FK null-on-delete only when resolver permits. Self reversal FK null-on-delete added later. `details`, `reversalOf`, `reversals`; computed `type` accessor. |
| `acc_journal_entry_details` / `JournalEntryDetail` | Transaction; `2026_01_01_000006_create_acc_journal_entry_details_table.php` | Required header/account UUIDs, debit/credit decimal(18,2), description. Header FK cascade; account FK cascade only when permitted. Model defines `header()`; account relation is manually attached by journal repository, not a declared method. |
| `acc_fiscal_periods` / `FiscalPeriod` | Transaction; `2026_01_01_000007_create_acc_fiscal_periods_table.php` | Unique year/month; dates, is-closed, closed-at, nullable UUID closed-by. No declared model relations or user FK. |
| `acc_monthly_balances` / `MonthlyBalance` | Transaction; `2026_01_01_000008_create_acc_monthly_balances_table.php` | Unique fiscal year/month/account tuple (`acc_monthly_balances_unique`); four decimal(18,2) balances/totals, journal count, close metadata. Account FK cascade only when permitted. No declared account relation. |
| `acc_report_mappings` / `ReportMapping` | Master; `2026_01_01_000009_create_acc_report_mappings_table.php` | Account ID, report type/group/subgroup, sequence, is-active. Account FK conditional via resolver; `account()` relation, report-type enum cast. Seeded but not used by current report calculations. |

Additional package migrations, in filename order:

| Migration path under `database/migrations/accounting/` | Owner / effect |
| --- | --- |
| `2026_01_01_000010_add_reversal_fields_to_acc_journal_entries_table.php` | Transaction; `reversal_of_id`, reason, reversed-at, is-reversal and indexes/self FK |
| `2026_06_30_000011_add_parent_id_to_acc_account_categories_table.php` | Master; self-parent/index with restrict-on-delete |
| `2026_07_14_000012_add_amount_to_acc_journal_entries_table.php` | Transaction; guarded addition of amount, already present in current base migration |
| `2026_07_14_000013_add_description_to_acc_accounts_table.php` | Master; guarded description addition, already present in base migration |
| `2026_07_15_000014_add_tenant_id_to_acc_accounts_table.php` | Master; guarded ownership column/index addition, already present in base migration |
| `2026_07_30_000013_alter_posted_by_on_acc_journal_entries_table.php` | Transaction; changes posted-by to nullable string(100), skips SQLite; rollback changes to UUID |
| `2026_08_03_000015_add_updated_by_to_acc_services_table.php` | Master; guarded nullable updated-by string(100) addition |
| `2026_11_04_000016_create_acc_closing_cutoffs_tables.php` | Transaction; per-tenant cutoff state and audit |
| `2026_11_04_000017_create_shared_closing_cutoffs_tables.php` | Master connection only (`HandlesCentralMigration`); shared cutoff state and audit; guarded |
| `2026_11_06_000018_create_acc_reporting_change_log_table.php` | Transaction; reporting outbox; guarded |
| `2026_11_06_000019_create_acc_reporting_central_tables.php` | Master connection only (`HandlesCentralMigration`) and supported configuration; central reporting tables; guarded |
| `2026_11_09_000020_create_acc_closing_runs_tables.php` | Master connection only (`HandlesCentralMigration`); global monthly-closing runs, per-tenant items and tenant/period leases; guarded |
| `2026_11_11_000021_add_operation_to_acc_closing_runs_table.php` | Master connection only (`HandlesCentralMigration`); adds `operation` (default `close`), `reason`, `reopened_count`, `already_open_count` to closing runs for global monthly reopening; guarded |

Master migrations use `HandlesMasterConnection::schema()` and skip existing master tables. Static risk (pre-existing, unchanged): their `down()` targets the master connection from any run, so a tenant rollback in shared-master mode would drop master tables. The 2026-11-06 central migrations (`HandlesCentralMigration`) do not share this risk. Transaction migrations use default `Schema`; their initial table creation and reversal migration are not equivalently guarded. Filename timestamps determine ordering; numeric suffix `000013` is reused on different dates.

`AccountingConnectionResolver::shouldCreateCrossConnectionForeignKeys()` returns true if shared mode is disabled or the configured master connection name equals the current/default transaction connection name. Otherwise transaction-to-master FKs are omitted. This comparison is by connection name, not physical database identity. Report-mapping FK creation uses the same check, even though both report mappings and accounts are master tables.

### Connection and tenant behavior

Source: `src/Support/AccountingConnectionResolver.php`, `src/Models/MasterDataModel.php`, `TransactionDataModel.php`, `src/Http/Controllers/BaseController.php`, `src/Repositories/AccountRepository.php`, `src/Services/GeneralLedgerService.php`.

| Mode | Master data | Transaction data |
| --- | --- | --- |
| Single/default database | Current Laravel default connection | Current default connection |
| Tenant database, shared master disabled | Current default after host tenancy switches it | Current default after host tenancy switches it |
| Shared master enabled | `accounting.master_data.connection` | Current Laravel default, which may be a tenant connection |

Explicit model connections override resolver defaults. Shared mode with no valid configured name falls back to Laravel's default resolution; there is no package startup validation requiring a named master connection.

Shared closing cutoffs (`closing.cutoff_mode = shared`) are a separate concern: `FiscalPeriodService::cutoffConnection()` uses `AccountingConnectionResolver::masterConnectionName()` (the raw `master_data.connection`) whether or not `use_shared_database` is enabled, and fails explicitly when it is unset, undefined in `database.connections`, or equal to a participating tenant's transaction connection.

Controllers attempt tenancy initialization when an identifier is supplied. Identifier priority in initialization is explicit controller/path argument, then `X-Tenant`, then request route value. The host must provide a `tenancy()` helper and valid `tenancy.tenant_model`; lookup tries primary key then route key. Missing helper/model/tenant returns null and continues; thrown initialization errors are reported and swallowed. Controllers do not restore/end tenancy after processing. This is integration code shaped for Stancl-style tenancy, not a declared Composer tenancy dependency.

Account visibility resolution prefers the already initialized tenant ID, then header, then route. Central context sees only accounts with null `tenant_id`; tenant context sees central plus exactly that tenant's accounts. Account create/update accepts client-provided ownership and does not infer it from runtime tenancy. Ownership is metadata on master accounts, not a transaction-table tenant discriminator.

Visibility is applied in account HTTP lookup/list paths and category-index account enrichment. It is **not** a global scope: manual/mapped/opening journal account lookups, financial reports, general ledger, closing, generic tree retrieval, and category detail can use unscoped accounts. All journal/period/balance tables rely on the selected database for tenant isolation; they have no tenant column. Tenant route/header handling alone does not authorize tenant access.

`GeneralLedgerService::getLedgerDetails()` additionally supports a string tenant override for direct PHP callers. It resolves tenant or throws, switches context, and restores the previous tenant (or ends tenancy) in `finally`. An integer fourth argument is interpreted as the legacy page argument, shifting the fifth to per-page. Preserve this positional compatibility; integer tenant IDs need string form to select override behavior.

### Seeding lifecycle

`database/seeders/AccountingSeeder.php` invokes category -> account -> service -> service-account -> report-mapping seeders. Models select their master connection. `Concerns/LoadsLocalizedSeederData.php` loads `database/seeders/data/{id,en}/` files for `account_categories`, `accounts`, `services`, `service_accounts`, `report_mappings`; unsupported/missing locale falls back to Indonesian.

Seeders use `updateOrCreate()` by category code, account code, service code, mapping key, or report account ID. Re-running updates seeded records, including labels/flags and mapping targets; it does not merely insert missing records. Category parent codes, account category codes and service/account codes are resolved to existing UUIDs. Missing required category/service/account dependencies raise `RuntimeException`. Parent categories must appear before children in seed data.

`DefaultCoaSeeder`, `DefaultAccountingServicesSeeder`, and `DefaultServiceAccountMappingsSeeder` are compatibility subclasses of `AccountSeeder`, `ServiceSeeder`, and `ServiceAccountSeeder`. The latter reads localized files directly; it does **not** read `ServiceAccountTemplateRegistry`. The catalog, registry and localized seed files are separate definitions that can drift. `AccountFactory` supplies default account fields but requires callers to provide a valid category.

## Important flows from entry to persistence

### Manual journal via HTTP or PHP

1. `POST /journals` -> `JournalController::store()` -> optional tenant initialization -> request validation, including connection-aware `Account::validationTable()` existence rules.
2. `BaseController::executeMutationHook('journals.store', ...)` calls configured before hook with validated payload, then invokes `JournalService::journalManual()`.
3. On the transaction connection, the service validates >=2 lines, finds accounts by ID or code (code is supported on the PHP path), requires active/postable accounts, recognizes D/debit or K/credit internally, and requires positive amounts.
4. `checkPeriodLocked()` -> `FiscalPeriodService::ensureForJournalDate()` -> `FiscalPeriodRepository::firstOrCreateForDate()` creates a calendar-month period if needed and rejects closed periods. Manual/opening paths convert locked-period errors to field validation exceptions.
5. Rounded total debit must equal credit. Number generation looks up the greatest journal number for the month and increments its final four characters. Empty manual reference defaults to that number.
6. `createJournalEntry()` inserts one `JournalEntry` and each `JournalEntryDetail`, using UUID callbacks. Manual header is immediately `posted`, with amount equal to max(total debit,total credit), posted timestamp and normalized actor; default source type is `MANUAL_JOURNAL`.
7. Tagged journal cache is flushed inside the transaction; after success the hook manager passes the result through the after hook. Controller emits a 201 compact summary. No custom domain event is dispatched.

### Mapped host transaction

1. Host calls `Accounting::journal()->journalByMapping($data)`; no package HTTP route implements this operation, and this service method does not automatically execute API hooks.
2. Service normalizes enum/string `service_code`, loads active database service and active mappings keyed by `mapping_key` on the master connection.
3. Each caller-supplied `items[]` needs a known mapping key; D/K mapping position selects debit/credit. Dynamic mappings may accept caller `account_id`; otherwise the configured account is used. Required mapping keys must appear; a required line must resolve an account.
4. Service checks the transaction date's fiscal lock and rounded balance, inserts draft header/detail rows on the transaction database, then calls `post()` if `journal.auto_post` is enabled.
5. `post()` checks the period and marks a non-posted header posted; an already posted header returns unchanged. It does not independently revalidate detail balance. Cache is flushed and the originally created header returned. `journalByMapping()` does not assign the separate model returned by `post()` or refresh its own instance, so the returned model can still carry draft status even after the database row is posted. Service/account reads across the master connection are outside the transaction database's atomic boundary.

Amounts, source linkage (`source_type`, UUID `source_id`), invoice references, VAT, COGS, and other operational calculations come from the host. Default VAT sale mappings include cash/receivable debit, sales/output-VAT credit, COGS debit, inventory credit. Only accounting entries are persisted.

### Full opening balance versus account opening balance

- `POST /opening-balances` -> controller validation/hooks -> `JournalService::journalOpeningBalance()`: rejects any existing `source_type = OPENING_BALANCE` in the current transaction database, validates unique active/postable account IDs and category types, converts signed amounts using account normal balance (assets/expenses debit-normal, others credit-normal), verifies balance, then calls the manual engine without a nested transaction. Creates one immediately posted journal with source `OPENING_BALANCE`. Revenue/expense accounts are accepted by current validation. Duplicate prevention is an application query, not a unique database constraint.
- `POST/PUT/PATCH /accounts` -> account validation/hooks -> `AccountOpeningBalanceService::createAccount/updateAccount()`: persists account master fields and, for positive opening amounts, creates a two-line journal against globally looked-up code `3001`. Uses source `ACCOUNT_OPENING_BALANCE` and source ID equal to account UUID. This is independent of the full-opening singleton check.
- Account detail calls `resolveOpeningBalanceData()` for `{amount, date, journal_entry_id, can_edit}`. Existing account opening journals may be edited only when no other journal exists on/after the earlier of original/requested opening date; this check is database-wide, not limited to that account or posted status.
- Editing uses direct query-builder updates of the posted header and two details, bypassing the model immutability callback. It checks the target fiscal period; zero/negative target amounts produce no opening update rather than removing it. `runAtomic()` starts transactions on unique master/transaction connections, commits them sequentially, and rolls them back on failure; it is not a distributed two-phase commit.

### Reversal

`POST /journals/{id}/reverse` -> request validation/hooks -> `JournalService::reverse()` transaction -> `JournalRepository::findWithDetails()` -> require posted original, no existing reversing entry, original period/year not closed -> create a new **posted** journal dated now, copying source/reference/service, swapping detail debits/credits, recording reversal link/reason/timestamp/flag -> flush cache -> hydrate view relations -> return IDs. Original header remains unchanged and both entries participate in posted ledger queries. A model `updating` callback attempts to prevent dirty updates to already posted headers; see its enum-comparison limitation below. Details and direct query-builder changes are outside that callback.

### Service and mapping mutation

`POST/PUT/PATCH /services` -> controller request validation -> `ServiceManagementService::create/update()` -> before hook -> master-connection transaction -> service insert/update plus mapping writes -> repository attaches active mappings -> commit -> after hook -> controller cache invalidation/response. Omitted `mappings` leaves them unchanged; supplied `[]` deletes all mappings on update; supplied entries update by ID or create, then omitted existing IDs are deleted. Service destroy relies on schema cascade for mappings and optional journal service FK nulling. Category/account mutations use controller hooks and direct Eloquent or the opening-balance service instead.

### Account/category listing and live balances

Account/category controllers initialize context, filter ownership through `AccountRepository`, and optionally call `AccountBalanceService::getBalances()`. For each account, a current-month snapshot wins even if the period is open. Without it, the engine finds the latest earlier snapshot, aggregates posted movements since that snapshot (or earliest journal), and aggregates requested-month movements. Normal-balance arithmetic produces opening/debit/credit/ending values; no snapshot is written. Categories add these balances through the tree. Account list balance is an object of totals; category/account tree balance fields are scalar endings.

### Monthly ledger and detail pagination

`GET /reports/general-ledger?account_id=...&year=...&month=...` -> validation -> `GeneralLedgerService::getLedger()` -> unscoped master account/category lookup -> live/snapshot balance service -> account/path, period, and totals; no details in summary. Detail endpoint resolves the same summary, joins posted header/details **only on transaction tables**, orders by date, journal number, detail creation time, computes every running balance, then slices an in-memory page. Detail description wins over header description. Legacy start/end-date requests use `ReportService::generalLedger()`, which returns opening summary only and has a different calculation path.

### Closing, reopening, and financial statements

1. Host calls `Accounting::closing()->closeMonth(...)`; no HTTP route. Transaction ensures period exists, rejects already closed period, checks aggregate monthly posted debit equals credit.
2. `ClosingService` reads all master accounts/category types, previous **immediately preceding** month's snapshots, and current posted activity. It upserts one `MonthlyBalance` per account and closes `FiscalPeriod` with actor/time, then flushes journal cache. Drafts are excluded; no year-end journal is generated.
3. `closeThroughCurrentMonth()` ensures calendar months from earliest journal (or current month) through now, closes open periods in order, and returns them; `closeUntilCurrentMonth()` is an alias.
4. `reopenMonth()` rejects an open/missing period or any later closed period, clears period close metadata and flushes cache. It leaves monthly snapshot rows in place.
5. `ReportController` financial-statement methods -> `ReportService` -> all master accounts/category tree + requested month's stored `MonthlyBalance` -> recursive ending-balance rollup. Missing snapshots become zero; these reports do not call live balance calculation. Profit/loss uses root REVENUE minus EXPENSE totals; balance sheet uses root ASSET/LIABILITY/EQUITY. Cash flow uses revenue/expense for operating, category codes `FIXED_ASSET`/`OTHER_ASSET` for investing, liability/equity for financing. `ReportMapping` rows are not consulted.

## Authentication, validation, hooks, caching, and errors

### Host security boundary

No package authentication guard, user model, policy, Gate, role/permission store, or authorization call is implemented. Default middleware is `api`, not an explicit authentication middleware. The host must configure authentication/authorization/tenant access middleware and any read-access restrictions. Request actors and ownership are accepted fields, not verified user/tenant FKs. Hook context can expose `request()->user()` but does not itself authenticate it.

### Validation conventions

Controllers validate inline with `$request->validate()` / `validator()` and `Rule::exists/unique(Model::validationTable(), ...)`; no FormRequest classes. Model validation table names include a connection name when applicable. Services add business checks, commonly `ValidationException::withMessages()` for manual/opening workflows and generic `Exception` for mapped journals, reversal, closing. Direct service calls do not generally rerun HTTP validation; mapping-management internal calls in particular rely on their caller. Foreign keys and unique constraints supplement validation only where installed.

### Hooks and events

Source: `src/Contracts/BeforeApiHook.php`, `AfterApiHook.php`, `src/Support/AccountingHookManager.php`, `ApiContext.php`.

- `BeforeApiHook::handle(ApiContext): void` may mutate payload with `setPayload/mergePayload` or attributes with `set`; throwing stops execution.
- `AfterApiHook::handle(ApiContext, mixed $result): mixed` receives successful result and must return a result compatible with the caller. Service-management return types require a `Service`; controller code often expects a journal/account model.
- Context exposes action, optional request, payload, arbitrary attributes, headers, route parameters and user. It does not independently infer/authenticate tenant or user for direct PHP calls without a request.
- HTTP mutations run after controller validation, so failed validation does not execute hooks. Changed hook payloads are not automatically revalidated. GETs do not execute hooks.
- Actions: `accounts.store/update/destroy/toggle-status`, `categories.store/update/destroy/toggle-status`, `services.store/update/destroy/toggle-status`, `journals.store/reverse`, `opening-balances.store`.
- Service create/update invokes the hook manager itself, including direct PHP calls; controllers avoid double wrapping those two actions. Other direct services, including journal, CoA, closing, opening-balance service, and reports, have no automatic hook lifecycle.
- After hooks run after the business handler returns; journal/service transactions have already committed. There is no failure hook, hook chain, transaction-wide wrapper, or queued hook execution. After-hook failure can therefore report failure after persistence and before controller cache invalidation.
- `src/Contracts/AccountingHook.php` and `src/Support/NullAccountingHook.php` implement an older beforeHandle/afterHandle contract; the current manager does not reference them. Hook stubs are examples to copy into the host; provider does not publish/register them.
- No custom domain events are defined. The only job is `Jobs/CloseTenantMonthJob` (global monthly closing, 2026-11-09; also global monthly reopening runs, 2026-11-11). Eloquent callbacks in `HasUuid` and `JournalEntry::booted()` are the active model lifecycle logic; since 2026-11-06 the provider also registers `ReportingChangeLog` model listeners (journals, details, fiscal periods), which only write outbox rows when `reporting.change_log_enabled` is true and master data is shared.

### Cache behavior

Controllers use `Cache::tags()` without a backend capability check. A tag-supporting cache store is a host requirement. Account/category/service reads are usually cached forever, journal list 24 hours and journal detail forever; paginated account reads bypass that cache path. Reports/general ledger are not directly cached.

Tags use fixed names `acc_accounts`, `acc_account_categories`, `acc_services`, `acc_journals` even if table prefix changes. Base controller adds `<base>_tenant_<path tenant>` when present. Account/category list and account detail keys also encode resolved tenant ownership and filters; journal and service keys do not include header-resolved tenant identity. Journal service invalidation derives tenant from active `tenancy()`; controller invalidation usually uses path argument. Account/category mutation cross-flushes the other master tag; balance-enriched reads include journal tags for invalidation. Direct model/repository writes, CoA writes, seeders, and direct service-management calls do not automatically perform controller cache invalidation.

### Errors and diagnostics

`AccountingExceptionResponder` is used by both route middleware and the provider's handler callback. It matches the configured path prefix with `str_starts_with`, and passes validation, authentication, authorization, model-not-found, and HTTP exceptions to Laravel. Their final rendering depends on host handler and JSON negotiation.

Other exceptions become HTTP 500 with `{success: false, message, error_code}`: `ACCOUNTING_DATABASE_ERROR` for query/PDO errors or `ACCOUNTING_INTERNAL_ERROR` otherwise. Debug mode adds connection/database/driver/SQL-state/exception/SQL metadata. Server logs include exception stack and query bindings. `AccountingPeriodLockedException` has code 423, but is a plain exception, not an HTTP exception: manual/full-opening paths convert it to validation; other paths can receive generic 500. Tenant initialization errors are reported and suppressed separately. `JournalService` also logs connection snapshots for mapped/full-opening entry points.

## Host integration and compatibility requirements

1. Install/autoload the Composer package into a Laravel application with a compatible PHP/framework dependency set. Discovery provides the provider/facade; hosts that disable discovery must register them themselves. Manifest constraints declare supported majors; they do not prove runtime compatibility for every major or override each Laravel release's own PHP requirements.
2. Configure a tag-capable cache backend and host authentication/authorization middleware under `accounting.route.middleware`. Resolve route-prefix/name collisions with host routes; route names have no package-wide `accounting.` namespace.
3. Configure database connections and accounting table prefix before migrations. Master tables use their configured connection, transaction tables the current default. In tenancy deployments the host must orchestrate migration execution and migration-history ownership for central/master versus each tenant database. There is no package tenant migration installer or tenant provisioning listener.
4. Provider loads migrations from source in console and optionally publishes copies. Inspect the host migration inventory and history before choosing execution paths; do not blindly execute both copies or replay unguarded transaction migrations. Shared master guards do not make every migration idempotent or rollbacks tenant-safe.
5. Run master seeders in dependency order on the intended master/current connection if defaults are needed. Code `3001` is required specifically for account-level opening balances. Default catalog/template code constants are integration identifiers, and localized seeding updates existing records.
6. For tenancy support, install/configure the host tenancy system, tenant model, middleware, switching and teardown lifecycle. The package declares no `stancl/tenancy` dependency. Direct PHP callers normally initialize the desired tenant themselves; ledger details offers an explicit temporary override, and profit/loss and balance sheet offer explicit tenant lists through `TenantReportRunner`.
7. Host source records may link through `source_type/source_id` and reference number. No polymorphic source relation, host-table FK, idempotency key, currency conversion, or transaction-source duplicate prevention exists. The only outbox is the opt-in central reporting change log (2026-11-06), which records accounting items, not host source records.

Since 2026-11-06 the package ships the `accounting:reporting-*` Artisan commands (console-only registration); it registers no scheduled tasks, frontend publishing, translations runtime, or Composer scripts. Only PHP/framework runtime dependencies are declared; Composer has no `require-dev`, checked-in lockfile, PHPUnit configuration, or CI workflow in this checkout.

## Existing tests and verification boundaries

`tests/TestCase.php` extends Orchestra Testbench, registers the package provider, and sets an in-memory SQLite `testbench` connection. Its declared namespace is `Tests`, while `composer.json` autoload-dev declares `ESolution\LaravelAccounting\Tests\`; feature tests import `Tests\TestCase`. Thus a fresh standalone test setup needs test tooling and a compatible bootstrap/autoload arrangement not fully expressed in this package manifest. A local ignored `vendor/` exists, but is not a reproducible compatibility guarantee.

Existing test inventory (baseline review was static; see the scoped update for subsequent local suite execution):

| Tests | Covered intent visible in definitions |
| --- | --- |
| `tests/Feature/AccountControllerTest.php` | Accounts, category enrichment, paginator, balances, account opening creation/edit restrictions |
| `tests/Feature/AccountOwnershipEnhancementTest.php` | Central+tenant visibility, caller-controlled ownership, category filtering and opening balance interactions |
| `tests/Feature/AccountCategoryTreeTest.php` | Hierarchy, filtering/search/pruning, pagination, recursive balances |
| `tests/Feature/ManualJournalControllerTest.php` | Manual posting, actor formats, balancing, active/postable accounts, locked periods, tenant variants |
| `tests/Feature/OpeningBalanceControllerTest.php` | Signed full opening, duplicates, invalid accounts, lock and tenant variants |
| `tests/Feature/GeneralLedgerControllerTest.php` | Monthly summary, period validation, unwrapped detail paginator and cross-page running balances |
| `tests/Feature/AccountingConnectionModesTest.php` | Single/shared/separate connection lookups, mapped amount and posted-by, master migration guards; some schemas constructed directly in tests |
| `tests/Feature/ServiceControllerTest.php` | Service actor/status filtering, hooks and internal service-call hooks |
| `tests/Feature/MutationHookSystemTest.php` | Mutation action lifecycle, no GET/failed-validation hooks |
| `tests/Feature/LocalizedAccountingSeederTest.php` | Indonesian default and English seeding |
| `tests/Feature/TenantFinancialReportsTest.php` | Separate tenant/period report results, both master modes, legacy shape, list validation/deduplication, explicit repository connections and context restoration |
| `tests/Feature/ConsolidatedReportServiceTest.php` | Distinct UUID/code alignment, tenant-specific accounts/branches, rebuilt subtotals, definition conflicts, validation, both master modes/overrides, context restoration and unchanged existing reports |
| `tests/Feature/ProfitLossSummaryServicesTest.php` | Summary formulas/classification, generic versus explicit income tax, account/category precedence, contra signs, separate/consolidated reconciliation, tenancy/overrides/restoration and unchanged old outputs |
| `tests/Feature/AccountingErrorHandlingTest.php` | Database/internal safe response and debug/provider rendering |
| `tests/Unit/InventoryServiceCatalogTest.php` | Supported inventory catalog codes and adjustment template counts |

No dedicated closing/reopening/reversal test file or cross-Laravel-major test matrix is checked in. Tenant financial report coverage was added as described in the scoped update. Test definitions and local results do not prove all production drivers or host tenancy bootstrappers work.

## Known limitations and source inconsistencies

These are investigation findings, not changes authorized by this map. Preserve current behavior until an affected enhancement explicitly addresses it and establishes compatibility expectations.

| Finding | Source evidence and implication |
| --- | --- |
| Tenant isolation is partial | `BaseController` continues after unresolved tenant initialization; unscoped repository methods remain widely used. Path/header identifiers are not authorization. Inference: an invalid tenant or mismatched host lifecycle can execute against the current/default database. |
| Header-only cache partitioning is incomplete | `JournalController` / `ServiceController` use path-tenant tags and generic keys; no header/default-connection identity in those keys. Inference: shared cache with header-driven database switching can reuse another context's cached records. Account/category ownership keys mitigate only their specific paths. |
| Closing type comparison mismatch (fixed 2026-11-05) | `ClosingService::closeMonth()` compared the uppercase `AccountCategory` type with lowercase `asset`/`expense`, so asset/expense snapshots were stored credit-normal (runtime confirmed: −1400 against a live 1400). It now compares case-insensitively. Snapshots written before the fix are not rewritten; balance-sheet summaries report months whose chain contains them as `stale`. Other snapshot readers (`balanceSheet()`, `profitLoss()`, `trialBalance()`) still show such legacy rows as stored until the months are reclosed. |
| Reopened snapshots remain authoritative | `ClosingService::reopenMonth()` does not delete snapshots; `AccountBalanceService` uses any matching snapshot without checking period closure, and financial reports read snapshots only. Inference: post-reopen changes can leave stale reported balances until recomputed. |
| Closing can skip historical carry | `closeMonth()` uses only immediately preceding monthly snapshots; it neither ensures earlier months were closed nor uses the live engine to reconstruct skipped activity. Balance check is aggregate for the period, not each journal; drafts do not block close. |
| Reversal checks original period, not new period | `validateReversal()` checks original date/year; new posted reversal is dated now without `checkPeriodLocked(now())`. Static risk: reversal may be written into a closed current month. |
| Numbering and duplicate checks lack serialization | Number generation reads last monthly journal and parses its last four digits; no lock/sequence/retry. Opening singleton and reversal uniqueness checks have no corresponding unique constraints. Inference: concurrent requests can race; nonstandard number formats and >9999 sequences need investigation. |
| Monetary calculations use floats | Services aggregate/round in PHP while schema uses decimal(18,2). Equal rounded totals do not guarantee every input line is valid to two decimal places, especially for direct/mapped calls. No money value object/currency dimension. |
| Mapped path validates less than manual path | `journalByMapping()` does not check resolved account existence/active/postable/ownership or positive item amount as the manual path does. Nullable optional account resolution can reach a non-null detail column; split mode may omit account FK. `post()` itself does not recheck balanced details. |
| Posted immutability is limited | `JournalEntry` callback targets Eloquent header updates only and compares `getOriginal('status')` strictly with string `JournalStatus::POSTED->value`, although status is enum-cast. The local ignored Laravel source (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php`, `getOriginalWithoutRewindingModel/transformModelValue`) applies casts to original values. Static risk: enum-versus-string comparison prevents the intended guard from firing; verify on the host framework. Detail writes/deletion and query-builder changes also bypass it; account-opening edits intentionally use query builder. Single-database account deletion can cascade to details/balances while leaving journal headers, unlike split mode where account FKs are omitted. |
| Multi-connection transaction is best effort | `AccountOpeningBalanceService::runAtomic()` commits connections sequentially; partial commit is possible. Its error path rolls back until transaction level zero, potentially unwinding outer host transactions. No distributed coordination. |
| Service mapping ownership not enforced | Update accepts any existing `ServiceAccount` ID; `ServiceManagementService::update()` does not require that ID to belong to the current service. Mapping keys are globally unique in DB but controller rules do not prevalidate uniqueness. |
| Legacy CoA status methods target absent column | `CoaService::activateAccount/deactivateAccount()` update `is_active`, while account schema/model use `status` and have no alias. `createAccount()` strips hierarchy fields and bypasses opening service/controller validation/cache/hooks. |
| Tree integrity not fully guarded | Category update validates parent existence but not self-parent/cycles or consistent types across branches. Recursive tree/lineage code has no visited-set protection. Category detail/default tree calls include unscoped accounts. |
| Reports have limited semantics | `ReportService` consumes snapshot ending balances and ignores report-mapping rows; cash flow is a category-balance grouping, not a cash-movement classification engine. Legacy ledger date-range summary lacks the monthly engine's historical fallback. |
| Paginators can require full collections | Category payload and ledger details are loaded/processed before slicing; direct ledger calls do not enforce HTTP per-page max. Journal pagination/search/date inputs have fewer validation constraints, and journal search OR conditions are not grouped before later filters. Inference: large datasets or combined journal filters need targeted review. |
| Cache invalidation is not atomic/universal | Journal flushes occur inside DB transactions, controller flushes after hooks; non-controller master writes may leave forever-cached master data. Closing flushes journal tags, not every plain master cache. |
| Migration upgrades/rollback need care | Some guarded additions already exist in base creates; their rollback still drops the column. Base accounts still have parent/level fields. Report-mapping FK conditional can omit a valid master-to-master FK in split mode. Shared master `down()` migrations can drop common tables; no tenant ownership guard exists. |
| Documentation/registry drift | `docs/technical-design.md` describes BIGINT IDs and account `is_active`/removed hierarchy fields; actual migrations use UUIDs, `status`, retained parent/level. `docs/README.md` says repositories are absent although six exist. README's single-test-file note is obsolete. Registry overrides alone do not change localized seeder data. |

## Conventions and extension points for enhancements

- Preserve the `ESolution\LaravelAccounting` namespace, facade alias/accessor, concrete container bindings and public method signatures. `GeneralLedgerService::getLedgerDetails()` argument shifting, closing alias, default-seeder aliases, and Service/ServiceAccount status aliases are existing compatibility surfaces.
- Preserve UUID identity, configurable table naming, connection-aware validation, master/transaction separation, account visibility rules, D/K mapping positions, enum values, source-type constants, globally unique codes/mapping keys, and existing HTTP response differences unless explicitly changing that contract.
- Reuse repositories for connection-aware lookups/manual cross-connection hydration, `AccountingTableResolver` for table/raw prefix handling, `FiscalPeriodService` for period provisioning, and current response/hook patterns where they apply. Do not assume all direct services run hooks or invalidate cache.
- Host hook classes are the existing request/result extension mechanism. Concrete singleton rebinding/subclassing is the main service/repository customization mechanism; replace bindings before dependent singleton resolution. No account-resolver/posting-rule interface or domain-event pipeline exists.
- A new default business service may need coordinated enum/catalog/template and **both localized datasets** plus compatible seeder behavior; verify all consumers first. Host-defined database services can use string codes without adding enum cases.
- Existing limitations above require investigation and tests before fixes; their presence is not permission for unrelated refactoring or behavioral changes. Update this map whenever architecture, entry points, schema ownership, tenant behavior, or public contracts change.
