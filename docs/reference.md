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

This section is the source-based reference for every PHP class in `src/Services`. They are registered as singletons by `AccountingServiceProvider` and can be resolved with Laravel's container.

Connection rules used by the service layer:

- Master-data models and repositories use the shared master connection when `accounting.master_data.use_shared_database` is enabled; otherwise they use the current default connection.
- Journals, fiscal periods, and monthly balances use the transaction connection from `AccountingConnectionResolver`.
- The active Stancl tenant controls the runtime connection context. Services use package resolvers and table resolvers rather than hardcoded connection names or prefixes.

### `AccountingService`

File: [`src/Services/AccountingService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/AccountingService.php)

Purpose: facade-oriented entry point for the main services and the business-service catalog.

Dependencies: `ServiceCatalog`, `ServiceRepository`, Laravel container.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `journal()` | None | `JournalService` | Resolves the journal service. |
| `coa()` | None | `CoaService` | Resolves the chart-of-accounts service. |
| `mapping()` | None | `MappingService` | Resolves the service-account mapping service. |
| `closing()` | None | `ClosingService` | Resolves the fiscal closing service. |
| `report()` | None | `ReportService` | Resolves the report service. |
| `service(string\|AccountingServiceCode $service)` | Required service code | `Service\|null` | Normalizes the code through `ServiceCatalog` and loads the service record. |
| `catalog()` | None | `ServiceCatalog` | Returns the package business-service catalog. |

### `AccountCategoryTreeService`

File: [`src/Services/AccountCategoryTreeService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/AccountCategoryTreeService.php)

Purpose: reads and transforms the account-category hierarchy and retrieves filtered category items, optionally consolidated across selected tenants.

Dependencies: constructor-injected `AccountCategoryRepository`, `AccountRepository`; `index()` resolves `AccountBalanceService` and `ConsolidatedReportService` from the Laravel container and uses the existing `AccountCategoryResource` for flat items.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `index(array $filters = [], ?array $tenantIds = null)` | Optional retrieval filters and optional nonempty tenant list; list also accepted as `$filters['tenant_ids']` | `Collection\|LengthAwarePaginator` | Returns category arrays using the category API's existing filtering, relationships, hierarchy, ordering and pagination conventions. Omission uses the active connection; a list returns one consolidated result. This PHP method is not connected to the existing API. |
| `getCategories()` | None | `Collection<AccountCategory>` | Returns ordered categories. |
| `getTree(?Collection $categories = null, ?Collection $accounts = null)` | Optional preloaded collections | `Collection` | Builds the category tree; loads collections when omitted. |
| `buildNode(AccountCategory $category, ?Collection $categories = null, ?Collection $accounts = null)` | Category required; collections optional | `array` | Returns one complete tree node or an empty array. |
| `getDescendants(AccountCategory $category)` | Category required | `Collection<AccountCategory>` | Returns recursive descendants. |
| `buildPath(AccountCategory $category)` | Category required | `array` | Returns lineage category names. |
| `flatten(Collection $tree)` | Tree required | `Collection` | Recursively flattens tree nodes. |

Retrieval options and rules:

- Supported filters: `type`, `parent_id`, `root_only`, `has_accounts`, `search`, `with`, `year`, `month`, `page`, `per_page`. `with` accepts a comma-separated string or array containing `children`, `accounts`, `balance`; other options are ignored. `type` is case-insensitive and validated against available source category types. Year/month default to the current year/month and are used for balances; the existing category retrieval period coercion is retained.
- Without a tenant list, `index()` reads the active connection without switching tenancy. Account visibility follows the existing active tenancy, `X-Tenant`, then tenant-route identifier precedence: central accounts plus that tenant's accounts, or only central accounts without an identity. Existing UUIDs and flat/tree item fields are retained. The service does not initialize a tenant from a request header or route.
- With a tenant list, `ConsolidatedReportService::categoryTree()` uses the existing tenant runner to validate all primary/route keys before executing source reads, deduplicate IDs/aliases, and initialize each selected tenant. With `accounting.reporting.read_source = central` it instead reads master tables and central copies without initializing tenants (see [Central read source](#central-read-source)). Empty, malformed, non-list and unresolved lists fail with `ValidationException`. An explicit null list inside `$filters` is invalid; a null second PHP argument preserves current-context retrieval. When both forms are supplied, a non-null second argument selects the tenants after validating the list shape in the filters.
- Each source chart and its visible account balances are read in that tenant's database context. Categories align by exact `category_code`, accounts by exact `code`, preserving case and leading zeros. Corresponding balances are summed once per account per source; category totals are rebuilt from direct accounts and descendants. Accounts/branches present in only some tenants remain. Existing consolidation definition checks reject incompatible parent/category codes, types, names, report types, status or postable flags. Unreachable source branches fail explicitly.
- Consolidated category `id` and `parent_id` contain category codes; account `id` and `category_id` contain account/category codes. These identifiers describe read-only merged items and are not database UUIDs. `parent_id` accepts a stable category code or a category UUID from any selected tenant; aliases matching multiple codes are rejected. Categories use the first selected occurrence's sequence, then name/code order; accounts sort lexically by code. Descriptions use the first selected occurrence.
- Filters apply to the merged chart before pagination. `parent_id` selects direct children and takes precedence over `root_only`; `with=children` starts at roots otherwise. Search matches category code/name and account code/name, retaining necessary ancestors. Returned account relations contain matching accounts only; balances include all visible accounts in the retained categories, matching existing category retrieval behavior. `has_accounts` retains branches containing visible accounts.
- With no pagination options, returns a `Collection` of category arrays, without an HTTP envelope. Flat items include `id`, `category_code`, `category_name`, `type`, `parent_id`, `sequence_no`, `is_active`, plus requested `accounts`/scalar `balance`. Tree items retain the existing `report_type`, `status`, `path`, recursive `children`, and requested `accounts`/`balance` fields. Account balances are scalar ending balances.
- Presence of `page` or `per_page` returns a native `LengthAwarePaginator` of those items. Defaults: page 1, per-page 15, minimum 1. Counts and slicing apply to filtered top-level items, with complete subtrees attached. The service introduces no cache and no API wiring or response changes.
- Balances reuse `AccountBalanceService` and its monthly snapshot/live fallback. Existing shared-master, tenant-local master, single-database and explicit model/repository connection overrides remain effective. Explicit-list iteration restores original tenancy and the original default connection in nested `finally` blocks, including query/initialization failures. Filtering/merging errors occur after restoration. No cross-database join is used.

Example:

```php
use ESolution\LaravelAccounting\Services\AccountCategoryTreeService;

$categories = app(AccountCategoryTreeService::class);
$current = $categories->index(['with' => 'children,accounts,balance', 'year' => 2026, 'month' => 7]);
$merged = $categories->index([
    'with' => ['children', 'accounts', 'balance'], 'year' => 2026, 'month' => 7,
], ['tenant-a', 'tenant-b', 'tenant-a']);
$page = $categories->index([
    'tenant_ids' => ['tenant-a', 'tenant-b'], 'parent_id' => 'ASSET', 'per_page' => 10,
]);
// $merged is one chart. Matching account balances 100 and 200 become 300.
// $page->items() contains direct children of ASSET; $page->total() counts them.
```

### `AccountBalanceService`

File: [`src/Services/AccountBalanceService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/AccountBalanceService.php)

Purpose: bulk source of truth for account balances in a monthly period.

Dependencies: `AccountCategoryRepository`, `AccountRepository`, `FiscalPeriodRepository`, `AccountingConnectionResolver`, `AccountingTableResolver`.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `getBalances(array $accountIds, ?int $year = null, ?int $month = null)` | Account IDs required; year/month optional | `Collection` keyed by account ID | Each value has `opening_balance`, `total_debit`, `total_credit`, and `ending_balance`. Invalid/omitted period values use the current period. |
| `applyMovement(float $balance, float $debit, float $credit, bool $isDebitNormal)` | All required | `float` | Applies debit-normal or credit-normal movement to a balance. |

Business rules:

- An existing current `MonthlyBalance` is used first.
- Missing monthly balances are calculated from the latest previous balance, carry-forward posted movements, and posted movements within the requested month.
- Asset and expense are debit-normal; all other category types are credit-normal.
- Aggregate journal queries use the resolved transaction connection and table prefix.

### `AccountOpeningBalanceService`

File: [`src/Services/AccountOpeningBalanceService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/AccountOpeningBalanceService.php)

Purpose: creates or updates an account and its account-level opening-balance journal atomically.

Dependencies: `JournalService`, `AccountingConnectionResolver`, `FiscalPeriodService`, account/journal models, cache, database transactions.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `createAccount(array $attributes)` | Required account attributes; opening-balance fields optional | `Account` | Creates the account and creates an opening-balance journal when `opening_balance > 0`. |
| `updateAccount(Account $account, array $attributes)` | Account and attributes required | `Account` | Updates account data and creates or conditionally edits an opening balance. |
| `resolveOpeningBalanceData(Account $account)` | Account required | `array\|null` | Returns `amount`, `date`, `journal_entry_id`, and `can_edit` when an opening balance exists. |

Business rules:

- A positive opening balance requires `opening_balance_date`, an active/postable account, and account code `3001` (`Opening Balance Equity`) as the contra account.
- Journal lines follow account category normal balance and are created through `JournalService::journalManual()` with source type `ACCOUNT_OPENING_BALANCE`.
- An existing opening balance can only be changed when no blocking journal transaction exists; a closed fiscal period cannot be edited.
- Work spans account/master and journal/transaction connections in database transactions and flushes journal cache after direct journal edits.

### `JournalService`

File: [`src/Services/JournalService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/JournalService.php)

Purpose: creates, posts, and reverses transaction journals.

Dependencies: `ServiceCatalog`, service/mapping/account/journal/fiscal-period repositories, `FiscalPeriodService`, transaction connection, cache.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `journalByMapping(array $data)` | `service_code` and `items` required; date/source/reference/description/`posted_by` optional | `JournalEntry` | Builds lines from active mappings. It creates draft then posts when `accounting.journal.auto_post` is enabled. |
| `journalManual(array $data, bool $wrapTransaction = true)` | At least two `details` or `items` required; header fields optional | `JournalEntry` | Creates an immediately posted balanced manual journal. |
| `journalOpeningBalance(array $data)` | At least two signed `details` required; header fields optional | `JournalEntry` | Creates the single package-wide `OPENING_BALANCE` journal. |
| `reverse($journalId, string $reason, int\|string\|null $postedBy = null)` | Journal ID and reason required; poster optional | `JournalEntry` | Creates a new posted reversal with debit/credit swapped. |
| `post($id, int\|string\|null $postedBy = null)` | Journal ID required; poster optional | `JournalEntry` | Posts a draft journal; returns an already posted journal unchanged. |

Behavior notes:

- `journalByMapping()` resolves the service and mappings through repositories, requires `service_code`, and balanced items.
- `journalByMapping()` is safe for shared master database setups because it does not depend on cross-connection `whereHas()` or eager loading against master tables.
- `journalManual()` creates a balanced manual journal from arbitrary accounts, validates account state and fiscal period, and posts it immediately.
- `journalOpeningBalance()` creates one posted journal for opening balances, derives debit/credit placement from account category normal balance, and rejects duplicate opening-balance creation.
- All journal creation paths accept optional `posted_by` as either integer or string and store it directly without checking any users table.
- `reverse()` creates a brand-new reversal journal and does not edit the original posted journal.
- `post()` is idempotent for already-posted journals.
- Journal writes share `FiscalPeriodService::assertDateOpen()`. With cutoff protection enabled, dates on or before the saved cutoff are locked, even when their fiscal month is open. Reversals check their actual new `trx_date` (`now()`) and retain the original journal's monthly/year lock rules. A reversal dated after a cutoff can correct an original dated before it when that original's month/year is otherwise open.
- Enabled cutoff checks hold the transaction database's cutoff-row lock through the write. Posting uses a transaction in cutoff mode; `journalManual(..., false)` starts one when its caller has no transaction. Existing caller transactions are reused.

Input notes:

- `journalManual(array $data)`
  - `trx_date` required
  - `reference_no` optional
  - `description` optional
  - `posted_by` optional `int|string`
  - `details` required
- `journalOpeningBalance(array $data)`
  - `trx_date` required
  - `reference_no` optional
  - `description` optional
  - `posted_by` optional `int|string`
  - `details` required
- `journalByMapping(array $data)`
  - `service_code` required
  - `trx_date` optional
  - `reference_no` optional
  - `description` optional
  - `posted_by` optional `int|string`
  - `items` required

Connection behavior: journal headers, details, fiscal periods, and writes use the resolved transaction connection. `journalByMapping()` reads service/mapping master data through repositories, so shared-master mode does not require a cross-connection join.

### `MappingService`

File: [`src/Services/MappingService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/MappingService.php)

Purpose: read-only lookup of service-account mappings.

Dependency: `ServiceAccountRepository`.

| Method | Parameters | Returns |
| --- | --- | --- |
| `findByKey(string $key)` | Required mapping key | `ServiceAccount\|null` |
| `getByService($serviceId)` | Required service ID | `Collection<ServiceAccount>` |

### `ServiceManagementService`

File: [`src/Services/ServiceManagementService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/ServiceManagementService.php)

Purpose: creates and updates `Service` master records and their `ServiceAccount` mappings.

Dependencies: `ServiceRepository`, `AccountingHookManager`, optional Laravel `Request`, database transactions.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `create(array $payload, ?Request $request = null)` | Service payload required; request optional | `Service` with mappings | Executes `services.store` hooks, creates service and supplied mappings atomically. |
| `update(Service $service, array $payload, ?Request $request = null)` | Service/payload required; request optional | `Service` with mappings | Executes `services.update` hooks. Supplied mappings are updated/created; existing omitted mappings are deleted when `mappings` is present. |

`updated_by` is normalized to a trimmed string or `null` without users-table validation. The service uses the `Service` model connection, so it follows shared-master configuration.

### `CoaService`

File: [`src/Services/CoaService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/CoaService.php)

Purpose: chart-of-accounts helper over the account model and category tree service.

Dependency: `AccountCategoryTreeService`.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `createAccount(array $data)` | Account attributes required | `Account` | Removes `parent_id` and `level` before creating an account. |
| `getTree()` | None | `Collection` | Returns category tree with posting accounts. |
| `activateAccount($id)` | Account ID required | `int` | Updates `is_active` to `true` and returns affected rows. |
| `deactivateAccount($id)` | Account ID required | `int` | Updates `is_active` to `false` and returns affected rows. |

### `FiscalPeriodService`

File: [`src/Services/FiscalPeriodService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/FiscalPeriodService.php)

Purpose: ensures fiscal-period records exist and applies shared monthly/cutoff date checks for journal workflows.

Dependencies: `FiscalPeriodRepository`; cutoff checks resolve `AccountingConnectionResolver` and `AccountingTableResolver` from the container. The constructor is unchanged.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `ensureForDate($date)` | Parseable date required | `FiscalPeriod` | Creates or returns the period for the date. |
| `ensureThroughCurrentMonth(?Carbon $fromDate = null)` | Optional starting Carbon date | `Collection<FiscalPeriod>` | Ensures periods from the supplied date, earliest journal date, or current month through the current month. |
| `ensureForJournalDate($date)` | Parseable date required | `FiscalPeriod` | Alias for `ensureForDate()`. |
| `assertDateOpen($date, bool $includeCutoff = true): void` | Actual affected date required; cutoff inclusion defaults to true | `void` | Checks the saved inclusive cutoff, ensures the fiscal period, and rejects closed months with `AccountingPeriodLockedException` (423). Passing false retains only monthly checks for an unchanged reversal original; the new reversal date receives the full check. |
| `lockClosingCutoff(): ?object` | None | `object\|null` | Reads and locks the local or central singleton according to mode; null when disabled. Missing enabled state fails explicitly. |
| `cutoffMode(): string` | None | `string` | Returns `per_tenant` or `shared`; rejects unknown modes. |
| `cutoffConnection(): string` | None | `string` | Resolves the transaction connection in per-tenant mode, or the configured master connection (`accounting.master_data.connection`) in shared mode, whether or not `use_shared_database` is enabled. Throws `RuntimeException` when that connection is unset or not defined. |
| `withClosingLock(callable $callback): mixed` | Callback required | Callback result | In enabled shared mode, holds the central lock until the callback and its tenant commits complete. Otherwise executes the callback directly. |

### `ClosingService`

File: [`src/Services/ClosingService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/ClosingService.php)

Purpose: closes/reopens fiscal months and materializes account `MonthlyBalance` rows, or manages an audited inclusive date cutoff.

Dependencies: `AccountCategoryRepository`, `AccountingTableResolver`, `FiscalPeriodService`, `AccountingConnectionResolver`, `TenantReportRunner`, transaction connections, cache, database transactions. Constructor dependencies remain unchanged.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `closeMonth($year, $month, $userId = null)` | Year/month required; closer ID optional | `FiscalPeriod` | Ensures the selected calendar period, upserts its monthly balances, and marks it closed. Optional `closing_day` (1–31 or `month_end`) rejects execution before that day in the selected or following month, per `closing_day_month`. Snapshots use natural signs: `ASSET`/`EXPENSE` categories (compared case-insensitively) are debit-normal, others credit-normal; an account without a resolvable category stays debit-normal. |
| `closeThroughCurrentMonth($userId = null, ?string $cutoffDate = null)` | Optional closer ID and `Y-m-d` cutoff | `Collection<FiscalPeriod>\|array` | Explicit dates delegate to `closeThroughDate()`. Without a date, closes due months; null `closing_day` retains closing through the current month. Enabled shared mode publishes the applicable month-end cutoff for the saved scope. It takes no tenant argument, so the first shared close must use `closeThroughDate(..., $tenantIds)`. |
| `closeUntilCurrentMonth($userId = null, ?string $cutoffDate = null)` | Optional closer ID and cutoff | `Collection<FiscalPeriod>\|array` | Alias of `closeThroughCurrentMonth()`. |
| `reopenMonth($year, $month, $userId = null)` | Year/month required; user ID currently unused | `FiscalPeriod` | Reopens one closed period. |
| `closeThroughDate(string $cutoffDate, int\|string\|null $userId = null, ?string $reason = null, ?array $tenantIds = null): array` | Required `Y-m-d` cutoff; optional actor/reason and tenant scope | `array` | Per-tenant mode advances independent date locks. Shared mode closes complete months in every selected tenant, then atomically publishes one cutoff and audit event centrally. |
| `reopenCutoff(?string $cutoffDate, string $reason, int\|string\|null $userId = null, ?array $tenantIds = null): array` | Earlier retained cutoff, or null; nonblank reason required; actor/scope optional | `array` | Reopens local cutoffs independently or one shared cutoff. Shared reopening validates monthly locks in every participant before changing central state. |
| `getCutoffDate(): ?string` | None | `string\|null` | Reads the saved local or authoritative shared cutoff; null means no active cutoff or disabled protection. |
| `getCutoffHistory(): Collection` | None; cutoff feature must be enabled | `Collection<object>` | Returns audit events in increasing event-ID order, including prior dates, new dates, actor, reason, action and timestamp. |
| `closeMonthGlobally(int $year, int $month, ?array $tenantIds = null, int\|string\|null $actorId = null, bool $scheduled = false): array` | Year/month required; null or `[]` tenants means every tenant of `tenancy.tenant_model`; actor (max 100 chars); scheduler flag | Run array | Records a tracked run on the master connection and queues one job per tenant after commit. See [Global monthly closing](#global-monthly-closing). |
| `getGlobalCloseRun(int $runId): array` | Run id | Run array | Progress and per-tenant results with localized readable messages. |
| `retryGlobalCloseRun(int $runId, ?array $tenantIds = null): array` | Run id; optional subset of the run's tenants (ids or route keys) | Run array | Requeues failed tenants and tenants whose claim expired. Other tenants are untouched. |
| `recoverGlobalCloseRuns(): array` | None | `list<{run_id, dispatched}>` | Crash recovery: requeues stale processing tenants and pending tenants whose job never ran within one lease, for every unfinished run. |
| `processGlobalCloseTenant(int $itemId): array` | Tracking item id | `{item_id, claimed, status?, reason?}` | Queue-worker entry point used by `CloseTenantMonthJob`; hosts normally do not call it. |
| `defaultGlobalCloseMonth(): array` | None | `{year, month}` | Latest month whose `closing_day` has arrived, or the previous calendar month when `closing_day` is null. Used by the command's default period. |

Rules: closing rejects already-closed periods and unbalanced posted journals (as `ClosingPeriodException`, a subclass of `Exception` with the same messages and a `reason` of `not_found`, `already_closed` or `unbalanced`); ending balances use category normal balance and the prior month's ending balance. Reopening is blocked while subsequent periods are still closed. Queries use the resolved transaction connection and resolved tables.

Cutoff configuration and persistence:

```php
// Host application's published config/accounting.php
'closing' => [
    'closing_day' => env('ACCOUNTING_CLOSING_DAY'), // null, 1–31, or month_end.
    'closing_day_month' => env('ACCOUNTING_CLOSING_DAY_MONTH', 'next'), // current or next.
    'cutoff_enabled' => env('ACCOUNTING_CLOSING_CUTOFF_ENABLED', false),
    'cutoff_mode' => env('ACCOUNTING_CLOSING_CUTOFF_MODE', 'per_tenant'), // shared stores on master_data.connection.
],
```

Shared cutoffs do not have their own connection or tenant-list settings. Earlier `closing.shared_connection` / `ACCOUNTING_CLOSING_SHARED_CONNECTION` and `closing.tenant_ids` values are ignored. Move the connection name to `ACCOUNTING_MASTER_CONNECTION` and pass the tenant list to the first shared close.

Monthly closing day:

`closing_day` and `closing_day_month` together set the earliest execution moment (midnight, application timezone) for closing a selected month:

| `closing_day` | `closing_day_month` | `closeMonth(2026, 11)` eligible from |
| --- | --- | --- |
| null / empty (default) | any | Always; prior unrestricted timing |
| `2` | `next` (default) | 2026-12-02 00:00 |
| `month_end` | `current` | 2026-11-30 00:00 |
| `15` | `current` | 2026-11-15 00:00 |
| `month_end` | `next` | 2026-12-31 00:00 |
| `31` | `current` (for `closeMonth(2028, 2)`) | 2028-02-29 00:00 (clamped) |

- `closing_day` accepts null/empty, an integer 1–31 (environment strings such as `"5"` are accepted), or the exact lowercase string `month_end`, meaning the last day of the target month. `closing_day_month` accepts exactly `current` (the month being closed) or `next` (the month after it). Null/empty `closing_day_month` means `next`, so existing numeric settings keep their prior meaning. Numeric days past the end of the target month clamp to its last day. Any other value (for example `0`, `32`, `end`, `Month_End`, `previous`, `selected`, `Current`) fails with a `ValidationException` before any fiscal period, snapshot or cutoff changes. With a null `closing_day`, the month setting is ignored and is not validated.
- `current` lets the selected month close on or before its own last day. Closing snapshots posted activity as it stands at execution and month-locks every remaining date of that month. With `month_end`, a close at November 30 00:00 includes only November 30 entries already posted; afterwards November 30 is locked. Choose `next` when entries for the final day may arrive after midnight.
- The same eligibility applies to `closeMonth()`, `closeThroughCurrentMonth()`/`closeUntilCurrentMonth()` (whose latest month can be the current month under `current`), and shared `closeThroughDate()` preflight.
- `closing_day` controls the earliest execution date, not the transaction selection boundary. Null or an empty environment value preserves the previous unrestricted timing. Set `ACCOUNTING_CLOSING_DAY=5` to make November 2026 eligible at December 5, 2026, 00:00 in the application's timezone. Later execution is allowed for missed runs and catch-up. Invalid configured days fail validation. When enabled, selected year/month inputs must also be valid integers (year 1–9999, month 1–12).
- Interpreting day 5 as a partial November boundary would omit November 6–30 from a complete monthly snapshot. Extending November through December 5 would overlap December and count those movements twice. The implemented following-month interpretation avoids both: `closeMonth(2026, 11)` always selects posted journals with November `trx_date`, including November 30, and excludes every December transaction. Posting/execution timestamps do not change the accounting month. No journal date is rewritten and no closing journal is generated.
- Fiscal boundaries remain November 1–30. Snapshots remain keyed by November; `closed_at` records the actual execution timestamp. Existing opening-balance, movement, category-sign and carry-forward calculations are unchanged. Close earlier months first when their ending balances are needed. This setting does not repair historical gaps or recalculate later snapshots.
- Days 29–31 clamp to the final day of a shorter following month. For example, day 31 makes January 2028 eligible on February 29, 2028. December closes in January of the following year. No scheduler or command is installed: the host calls the existing service, optionally from its scheduler.
- `closeThroughCurrentMonth()` and its alias skip months not yet eligible. Before December 5 with day 5, October is the latest eligible month; on December 5, November becomes eligible. The active-database result remains a collection. Enabled shared mode uses that latest eligible month end as its default target, retaining its audit-event response. Explicit shared targets preflight every pending complete month in every tenant before creating any snapshots. A target requiring an early monthly close fails without publishing a cutoff. Existing forward-only cutoff rules still apply if a saved explicit cutoff is already ahead of the default target.
- `closeMonth()` does not advance a local or shared cutoff. In per-tenant mode, `closeThroughDate()` remains a date-lock operation independent of `closing_day`; in shared mode it also completes months and therefore checks their eligibility. Never pass the execution date merely to schedule closing: a December 5 cutoff would also lock December 1–5. Use November 30 when the intended transaction lock ends with November.
- Existing retries and reopening remain unchanged: direct `closeMonth()` rejects an already-closed month; bulk closing skips closed months; completed shared retries reuse their audit event. Reopening retains snapshots, respects later closed months, and does not move cutoff state. Reclosing still uses the selected month and the current eligibility setting, replacing its existing snapshots. A host scheduled job can reclose reopened months; pause or coordinate that job during corrections. Rebuild affected snapshots in chronological order after reopening.
- The setting also applies when cutoff protection is disabled. It changes only monthly eligibility; enabling it does not turn on date cutoffs or alter journal locks. Configuration remains publishable through `accounting-config` and compatible with `php artisan config:cache`; rebuild the host config cache after edits.

```php
// ACCOUNTING_CLOSING_DAY=5; execute on December 5, 2026 or later.
$closing = app(\ESolution\LaravelAccounting\Services\ClosingService::class);
$period = $closing->closeMonth(2026, 11, $userId); // November only; December stays open.
// Use the existing coordinated workflow when a shared cutoff must also advance:
$event = $closing->closeThroughDate('2026-11-30', $userId, 'November reviewed', $tenantIds);
```

```php
// ACCOUNTING_CLOSING_DAY=month_end, ACCOUNTING_CLOSING_DAY_MONTH=current
// On 2026-11-29: closeMonth(2026, 11) throws ValidationException (closing_day).
// From 2026-11-30 00:00:
$period = $closing->closeMonth(2026, 11, $userId);      // All posted November entries; November 30 is now locked.
$closed = $closing->closeThroughCurrentMonth($userId);   // Includes the current month once its last day arrives.

// ACCOUNTING_CLOSING_DAY=2, ACCOUNTING_CLOSING_DAY_MONTH=next (same as leaving the month setting unset)
// closeMonth(2026, 11) becomes eligible on 2026-12-02 00:00.
```

Cutoff storage setup:

- For `per_tenant`, run `2026_11_04_000016_create_acc_closing_cutoffs_tables.php` in every participating transaction database. The `accounting-migrations` tag includes these prefixed local state/audit tables. Journal entries, fiscal periods and monthly balances retain their existing transaction-database ownership in both modes. Master data continues to use its existing resolver.
- For `shared`, set `ACCOUNTING_MASTER_CONNECTION` to a named Laravel connection defined in `database.connections`, and select `ACCOUNTING_CLOSING_CUTOFF_MODE=shared`. `2026_11_04_000017_create_shared_closing_cutoffs_tables.php` sits in the same auto-loaded `database/migrations/accounting` folder (`accounting-migrations` tag). It acts only on a run whose connection is the master connection, so run `php artisan migrate --database=<master-connection>` once centrally (plain `migrate` when the master connection is the default). Tenant runs of the same file create, recreate and drop nothing. It creates prefixed `shared_closing_cutoffs` and `shared_closing_cutoff_events`, both with canonical `tenant_ids` JSON, whenever a master connection is configured. The tables stay inert while `cutoff_mode` is `per_tenant`, so switching to `shared` later needs no further migration. Without a master connection the migration is a no-op. The connection must remain stable during tenancy switching and must be separate from each tenant transaction connection. Do not configure another connection name pointing to the same tenant database.

Shared cutoff storage by master-data setting:

| `ACCOUNTING_USE_SHARED_DATABASE` | `ACCOUNTING_MASTER_CONNECTION` | Shared cutoffs |
| --- | --- | --- |
| `true` | Named central connection | Supported. Master data and shared cutoff tables share the central connection. |
| `false` | Named central connection | Supported. Master data stays on each tenant connection (the flag still controls it); only the shared cutoff tables use the master connection. |
| any | Unset/empty, or not defined in `database.connections` | Unsupported. Shared operations, journal writes with cutoffs enabled, and the central migration throw `RuntimeException`. |
| any | Same as a participating tenant's transaction connection (including single-database installs) | Unsupported. Shared close/reopen throws `RuntimeException` before any change. Use `per_tenant` instead. |
- The state table contains one seeded row (`id = 1`) with nullable `cutoff_date` and `updated_at`. The event table has an increasing ID, `action` (`close`, `advance`, `reopen`), nullable `previous_cutoff_date`/`cutoff_date`, nullable string `actor_id` (maximum 100 characters), nullable reason (maximum 1000 characters), and `created_at`. Service methods append audit events; changing state and appending its event occur in one transaction.
- Then enable `ACCOUNTING_CLOSING_CUTOFF_ENABLED=true`. Leave it enabled while using saved cutoffs; reopen through the audited service method. Default false preserves existing installations and manual test schemas without cutoff tables. Missing tables/state fail explicitly when enabled. The existing `accounting-config` publish tag exposes these editable values; they are compatible with `php artisan config:cache`. Rebuild the host config cache after edits.
- There is no environment cutoff date. Remove the old `ACCOUNTING_CLOSING_CUTOFF_DATE` / `closing.cutoff_date` setting from published configuration. Dates advance only through closing operations; changing configuration never advances an operational date. Migration between existing per-tenant and shared policies is not automatic: reconcile existing closures before switching modes. Never use a mode change to bypass a saved lock.

Tenant scope and coordination:

- `per_tenant` is the default. Omitted `tenantIds` uses the active transaction database. Explicit IDs use the existing runner's complete-list validation, primary/route-key resolution and deduplication. Each tenant commits its own state and audit independently. The result is `[{tenant_id, report: <audit event>}]`. If a later tenant fails, earlier independent operations remain committed; inspect their histories before retrying those local operations. Local same-date closing remains an error.
- `shared` takes its scope from the explicit `tenantIds` argument, otherwise from the saved central scope. Before any scope is saved, omitting `tenantIds` (including through `closeThroughCurrentMonth()`) fails with a `tenant_ids` validation error. A saved scope must match any explicit list. The first successful publication fixes canonical membership. Subsets, additional tenants, missing tenants and empty lists fail explicitly; there is no fallback database or implicit scope change. Supply the full scope for reopening too. Journal writes require an active participating tenant once the shared scope is saved, including after clearing its date.
- Shared closing validates posted debit/credit equality through the target in every tenant. It then closes complete calendar months from the earliest journal/existing fiscal period through the target, skipping closed months. A November 15 cutoff closes October and earlier complete months; November remains open without a partial snapshot. A November 30 cutoff can close November completely. Existing monthly calculations and account metadata resolution are reused; local UUIDs never cross databases. Use an actor ID compatible with each database's existing monthly `closed_by` column.
- A central row lock serializes shared closings, reopening, monthly lock changes and journal writes. Every local close commits before central publication. If initialization, validation, a tenant close or central audit fails, the previous shared cutoff and history remain unchanged. Completed local months remain closed: this is coordinated publication, not a distributed rollback. Retrying revalidates all tenants and skips closed months. Retrying the currently completed close/reopen target returns its existing event without duplicate audit. A different later target is a new operation.
- Tenant execution restores the original active tenant and default connection through the runner's nested `finally`, including initialization/query failures. All participating processes must use the same master connection, mode and table prefix. No cutoff cache exists. Connection failure or missing state fails closed.

Cutoff behavior:

- A saved `2026-11-15` cutoff rejects creation/posting dated November 15 or earlier, including backdated drafts and opening balances; November 16 onward remains writable unless a monthly lock applies. Inputs are calendar dates in `Y-m-d` format. Full datetime journal inputs are checked using their calendar date, inclusively.
- Per-tenant date closing only saves a lock; it does not automatically close fiscal months or generate snapshots. Shared date closing also completes full months as described above. Neither mode stores partial totals as complete snapshots. Explicit `closeMonth()` retains full-month behavior and does not publish or advance the cutoff by itself.
- Manual, mapped, package opening-balance, per-account opening-balance, posting and reversal paths use the same cutoff check. Editing an account opening journal checks both its original and target dates, including amount-only edits; moving a locked opening into an open date cannot bypass protection. Rejections roll back master/transaction changes through the existing service transaction handling.
- Advancing cannot move backward. Except for a completed shared retry, the date must advance strictly. Reopening requires an active cutoff, an earlier date (or null), and a reason. For example, reopening from November 20 to November 15 keeps dates through November 15 locked and opens November 16 onward. Clearing is allowed only when no closed monthly periods remain; otherwise reopen those months first. Shared reopening checks this in every participant before publishing one central event.
- Monthly locks and cutoff locks both apply. Reopening a month does not move the cutoff. Reopening a cutoff does not silently reopen monthly periods. Closed months whose end is after the proposed retained cutoff must first be reopened latest-first using `reopenMonth()`. Monthly reopening continues to retain old snapshots; recompute them with `closeMonth()` after corrections, following the existing workflow.
- In shared mode, journal services acquire the central lock before local transactions and hold it through local commit. Account opening flows also preserve enclosing central transaction levels when rolling back their own master/transaction work. If host code needs an outer tenant transaction, wrap it in `FiscalPeriodService::withClosingLock()` first; unprotected outer tenant transactions are rejected. This serializes shared-mode journal throughput. SQLite tests verify transaction boundaries/rollback, not native concurrent row locking; production drivers must provide transactional `lockForUpdate()` semantics. Direct SQL writes bypass service locks.

Example:

```php
$closing = app(\ESolution\LaravelAccounting\Services\ClosingService::class);
$event = $closing->closeThroughDate('2026-11-15', 'operator-12', 'November review');
$closing->closeThroughDate('2026-11-20', 'operator-12', 'Advance after review');
$closing->reopenCutoff('2026-11-15', 'Correct November 16 onward', 'operator-12');
$date = $closing->getCutoffDate(); // '2026-11-15'
$history = $closing->getCutoffHistory(); // close, advance, reopen
```

The initial event contains:

```json
{
  "id": 1,
  "action": "close",
  "previous_cutoff_date": null,
  "cutoff_date": "2026-11-15",
  "actor_id": "operator-12",
  "reason": "November review",
  "created_at": "2026-11-16 12:00:00"
}
```

Shared-mode example, after configuring and migrating central storage:

```php
$event = $closing->closeThroughDate('2026-11-15', 12, 'All branches reviewed', ['branch-a', 'branch-b']);
$sameEvent = $closing->closeThroughDate('2026-11-15', 12); // Saved scope; safe completed retry.
$closing->reopenCutoff('2026-11-10', 'Correct November 11 onward', 12);
// Reopen any overlapping closed months in every tenant first when moving further back.
```

Shared events use the same fields shown above plus `"tenant_ids": ["branch-a", "branch-b"]`. They are returned once, not per tenant. Histories are read centrally in increasing event-ID order; local histories remain in their original databases.

#### Global monthly closing

`closeMonthGlobally()` closes one calendar month in many tenants and keeps going when some fail. Each tenant runs the existing `closeMonth()`, so its eligibility (`closing_day`), balance check, transaction, shared closing lock, snapshots and reporting outbox row are unchanged. It is a separate operation from a shared cutoff:

| | Shared cutoff (`closeThroughDate()` in `shared` mode) | Global monthly close (`closeMonthGlobally()`) |
| --- | --- | --- |
| What it changes | One central date lock plus every complete month up to it | One month's fiscal period and snapshots in each tenant |
| Tenants | Fixed saved scope; must match exactly | Every tenant, or any explicit list |
| On failure | Stops; central date is not published | Records the failure and continues with the other tenants |
| Retry | Repeat the call; closed months are skipped | `retryGlobalCloseRun()` retries failed tenants only |
| Audit | Cutoff event history | Run and per-tenant tracking rows |

A global close never publishes, advances or changes a shared cutoff or its scope, in any mode. Use `closeThroughDate()` for that.

**Setup.** Requires `accounting.master_data.connection` (`ACCOUNTING_MASTER_CONNECTION`) to name a configured connection, with or without `use_shared_database`, and a configured `tenancy.tenant_model`. Run `2026_11_09_000020_create_acc_closing_runs_tables` against that connection (`php artisan migrate --database=<master>`). Like the shared cutoff migration, it does nothing on tenant runs. Without the tables, the methods throw a `RuntimeException` naming the connection. Run a queue worker for `accounting.closing.global.queue_connection` / `queue` (null uses the host defaults). With the `sync` driver, jobs run inline and the returned run is already complete.

| Setting | Default | Meaning |
| --- | --- | --- |
| `closing.global.queue_connection` | `env('ACCOUNTING_CLOSING_QUEUE_CONNECTION')` (null) | Queue connection for `CloseTenantMonthJob` |
| `closing.global.queue` | `env('ACCOUNTING_CLOSING_QUEUE')` (null) | Queue name |
| `closing.global.lease_seconds` | `env('ACCOUNTING_CLOSING_LEASE_SECONDS', 900)` (minimum 60) | How long a worker's claim lasts before a crashed attempt can be recovered. Set it above your longest single-tenant close. |

**Flow.**

1. Validates year, month and actor, plus the closing settings. Resolves tenants: null or `[]` means every tenant of `tenancy.tenant_model`. An explicit list accepts primary or route keys, which are deduplicated to canonical ids before anything is saved. Unknown tenants fail validation and no run is created.
2. Inserts the run (`pending`) and one `pending` row per tenant in one master transaction.
3. Queues `CloseTenantMonthJob` per tenant with `afterCommit()`. Inside a caller's transaction, jobs are queued only when it commits. If the caller rolls back, the tracking rows remain `pending` and `recoverGlobalCloseRuns()` picks them up after one lease.
4. Each job claims its row and a lease on the tenant and period, shared by every run. It then initializes the tenant through `TenantReportRunner`, which always restores the previous tenant and default connection. Each attempt rechecks the period:
   - already closed → `already_closed`;
   - before its `closing_day` → `not_due`;
   - scheduled run on a reopened period → `not_due` with code `reopened_period`;
   - otherwise `closeMonth()` → `closed`.

   Posted debit/credit totals for the month are stored when the tenant could be read. Any exception becomes `failed` with a readable code. The run's counts and status are recomputed after every tenant.

**Statuses.** Run: `pending`, `processing`, `completed` (no failures; `not_due` counts as finished), `partially_failed`, `failed` (every tenant failed). Tenant: `pending`, `processing`, `closed`, `already_closed`, `not_due`, `failed`.

**Overlap, duplicates and crashes.**

- A tenant and period is processed by at most one worker at a time, across runs (`acc_closing_run_locks`). A busy job is released for 60 seconds.
- Duplicate or late jobs find their row already finished and return `claimed: false`.
- A worker that dies leaves its row `processing`. `getGlobalCloseRun()` shows `stale: true` once the lease expires, and `recoverGlobalCloseRuns()` or `retryGlobalCloseRun()` requeues it.
- Outcomes are written only while the worker still owns its claim, so a slow worker cannot overwrite a newer attempt.
- If a crashed worker had already committed the tenant close, the next attempt reports `already_closed`.
- `attempts` counts every claim.

**Scheduled runs.** With `$scheduled = true`, a period has at most one scheduled run. Later scheduled calls for the same period return that run, requeuing stale or never-run tenants, instead of starting another. Scheduled runs never close a period that was closed before and then reopened. They detect this from retained snapshots or from an earlier run's `closed` row, and report `not_due` with code `reopened_period`. Close such periods with a manual run or `closeMonth()` once corrections are done. Tenants added after the scheduled run was created are not included; start a manual run for them.

**Results.** Every run method returns:

```json
{
  "id": 1, "year": 2026, "month": 7, "period": "2026-07", "trigger": "manual", "status": "partially_failed",
  "actor_id": "closer", "tenant_ids": ["tenant-a", "tenant-b"],
  "counts": {"total": 2, "pending": 0, "processing": 0, "closed": 1, "already_closed": 0, "not_due": 0, "failed": 1},
  "created_at": "2026-08-20 12:00:00", "started_at": "2026-08-20 12:00:00", "finished_at": "2026-08-20 12:00:01",
  "tenants": [
    {"tenant_id": "tenant-a", "status": "closed", "attempts": 1, "total_debit": 100.0, "total_credit": 100.0,
     "started_at": "…", "finished_at": "…", "stale": false, "error": null, "reason": null},
    {"tenant_id": "tenant-b", "status": "failed", "attempts": 1, "total_debit": 40.0, "total_credit": 40.0,
     "started_at": "…", "finished_at": "…", "stale": false, "reason": null,
     "error": {"code": "account_reference_mismatch",
               "message": "Account balances for July 2026 could not be saved because the accounts in this company's database do not match the chart of accounts.",
               "action": "Ask an administrator to check the accounting master-data setting and migrations, then retry. Reference: 5f0c….",
               "reference": "5f0c…"}}
  ]
}
```

`error` (failed tenants) and `reason` (`not_due` tenants) carry `code`, `message`, `action` and `reference`. They are rendered when read, in the application locale, from `lang/{en,id}/closing.php` (publish tag `accounting-lang`). They never contain SQL, table names or stack traces. The full exception is reported through the host exception handler as the previous exception of `ReferencedAccountingException`. Its log context holds the same `reference` plus `closing_run_id`, `closing_run_tenant_id`, `tenant_id`, `period` and `error_code`.

| Code | Cause |
| --- | --- |
| `not_due` | Before the configured closing day (`:date` is the due date) |
| `reopened_period` | Scheduled run skipped a deliberately reopened period |
| `unbalanced_journals` | Posted debits differ from credits (amounts included) |
| `account_reference_mismatch` | Foreign-key violation writing snapshots, typically a tenant database migrated while master data was tenant-local whose snapshot table still references its own `acc_accounts` after `use_shared_database` was enabled |
| `database_error` | Any other database failure |
| `tenant_unavailable` | The tenant could not be resolved or initialized |
| `configuration_error` | Invalid closing settings detected while closing |
| `unexpected_error` | Anything else |

**Command and scheduling.** `accounting:closing-close-month` wraps these methods. It exits 1 when any tenant failed or the input is invalid.

| Option | Effect |
| --- | --- |
| `--year= --month=` | Period to close; both or neither (neither = `defaultGlobalCloseMonth()`) |
| `--tenant=*` | Tenant ids or route keys; omit for every tenant |
| `--actor=` | Actor recorded on the run and as `closed_by` (use a value compatible with that column) |
| `--scheduled` | Scheduler mode described above |
| `--recover` | Run `recoverGlobalCloseRuns()` first; alone, only recovers |
| `--retry=ID` / `--status=ID` | Retry failed tenants of a run / print a run |

The package registers no schedule. Example host schedule:

```php
use Illuminate\Support\Facades\Schedule;

// Close the latest due month once it is due; later ticks only resume that period's scheduled run,
// so periods reopened for corrections are not closed again automatically.
Schedule::command('accounting:closing-close-month --scheduled --recover')
    ->dailyAt('01:30')->withoutOverlapping()->onOneServer();

// Optional: recover crashed workers more often than the daily close.
Schedule::command('accounting:closing-close-month --recover')->everyFifteenMinutes()->withoutOverlapping();
```

PHP usage:

```php
$closing = app(\ESolution\LaravelAccounting\Services\ClosingService::class);
$run = $closing->closeMonthGlobally(2026, 7, null, auth()->id());     // every tenant
$run = $closing->closeMonthGlobally(2026, 7, ['branch-a', 'branch-b']); // selected tenants
$progress = $closing->getGlobalCloseRun($run['id']);
$retried = $closing->retryGlobalCloseRun($run['id']);                   // failed tenants only
```

Limits:

- Each run closes exactly one month. It does not require the previous month to be closed: `closeMonth()` carries forward whatever snapshots the previous month has, as before.
- Claims and leases are atomic conditional `UPDATE`s on the master connection. The SQLite tests exercise duplicate jobs, held leases, expiry and fencing sequentially. Truly concurrent workers on MySQL, MariaDB or PostgreSQL have not been verified.

### `GeneralLedgerService`

File: [`src/Services/GeneralLedgerService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/GeneralLedgerService.php)

Purpose: monthly General Ledger summary, journal-detail source of truth, and one account's period activity across selected tenants.

Dependencies: `AccountRepository`, `AccountCategoryRepository`, `AccountBalanceService`, `AccountingConnectionResolver`, `AccountingTableResolver`, `GeneralLedgerPaginator`; `getAccountBreakdown()` resolves the existing `TenantReportRunner` from the container. The constructor is unchanged.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `getLedger(string $accountId, int $year, int $month)` | All required | `array` | Returns account, period, opening balance, total debit, total credit, and ending balance. It does not return `details`. |
| `getLedgerDetails(string $accountId, int $year, int $month, string\|int\|null $tenant = null, ?int $page = null, ?int $perPage = null)` | Account/year/month required; tenant/page/per-page optional | `GeneralLedgerPaginator` | Returns native paginator fields plus complete-period General Ledger summary. |
| `getDetails(string $accountId, string $startDate, string $endDate, float $openingBalance, bool $isDebitNormal)` | All required | `array` | Loads posted movements and calculates running ending balance per row. |
| `getAccountBreakdown(string $accountCode, string $startDate, string $endDate, array $tenantIds): array` | Nonblank account code, inclusive `Y-m-d` dates, and nonempty valid tenant list; all required | `array` | Returns `{account, period, tenant_ids, tenants, totals, calculation_basis}`. Resolves the code in each tenant and calculates posted period activity, excluding opening journals. Includes existing accounts with zero activity; missing accounts or incompatible D/K classifications raise validation errors. |

General Ledger rules:

- Summary uses `AccountBalanceService` and does not query journal detail rows.
- Detail rows include `date`, `journal_number`, `reference_no`, `description`, `debit`, `credit`, and `ending_balance`.
- Detail running balances are calculated for the complete period before pagination, so later pages continue from earlier pages.
- Opening balance, debit total, credit total, and ending balance represent the whole selected period, never only the current page.
- Page/per-page default to `1`/`15`; values below one become one. Legacy positional calls with an integer fourth argument remain interpreted as page/per-page arguments.
- `$tenant` is service-only. A non-empty value resolves and temporarily initializes that Stancl tenant, takes precedence over the runtime tenant for all account/balance/journal work, then restores the prior context. It is not read from any HTTP header or request value.
- Journal detail reads use the resolved transaction connection, resolved table prefix, and only `posted` journals.

Example:

```php
use ESolution\LaravelAccounting\Services\GeneralLedgerService;

$summary = app(GeneralLedgerService::class)->getLedger($accountId, 2026, 7);
$details = app(GeneralLedgerService::class)->getLedgerDetails($accountId, 2026, 7, null, 2, 20);
```

Account breakdown rules:

- The account code is resolved independently through `AccountRepository::findByCode()` inside each selected tenant, allowing different UUIDs. Codes remain strings, preserving leading zeros. Like the existing ledger lookup, resolution is unscoped by master-account ownership; tenant selection does not introduce an ownership filter.
- Dates must be complete calendar dates in `Y-m-d` format with start <= end. Both boundaries are inclusive, including single-day, cross-year and leap-day selections. Invalid account/date inputs fail before tenant execution.
- The existing tenant runner validates the whole nonempty list, resolves primary/route keys, deduplicates IDs and aliases, and preserves first-selected order. Results contain canonical string tenant IDs. Invalid tenants are never skipped. Shared-master and tenant-local master configurations, connection/table prefixes, and explicit repository/resolver overrides remain effective.
- `account` contains `code`, `name`, `category: {category_code, category_name, type}`, and `normal_balance` (`D` or `K`). Names/category metadata come from the first selected tenant. Assets/expenses are debit-normal; liabilities/equity/revenue are credit-normal. Different names or category types are allowed when their normal balance agrees; opposing D/K classifications are rejected under `normal_balance`. Missing or unknown categories also fail instead of assuming a classification.
- `tenants` contains `{tenant_id, total_debit, total_credit, net_amount, balance_side}` for each selected tenant. An existing account without qualifying activity returns zero amounts and a null side. Missing accounts fail the whole result under `account_code`, with a message for each affected tenant; no partial overall totals or misleading zero rows are returned.
- Amounts represent period activity only. The query sums posted journal details on each resolved transaction connection, excludes drafts/other non-posted statuses, out-of-range dates, `OPENING_BALANCE`, and `AccountOpeningBalanceService::SOURCE_TYPE` (`ACCOUNT_OPENING_BALANCE`). Opening-source reversals are excluded too. Ordinary null-source journals and posted signed reversal effects are included. No snapshots, opening/ending balances or closed fiscal periods are read or required.
- `calculation_basis` is `debit_minus_credit` for debit-normal accounts, or `credit_minus_debit` for credit-normal accounts. Per-tenant and overall `net_amount` use that basis; negative values are preserved. Amounts are rounded to two decimals. `totals` sums tenant debits/credits and calculates net on the same basis, without adding another balance or subtotal.
- `balance_side` is determined independently from actual totals: `D` when debit > credit, `K` when credit > debit, and null when equal, including equal nonzero activity. Thus a debit-normal account with debits 150 and credits 230 has `net_amount: -80` and side `K`; a credit-normal account with debits 300 and credits 100 has `net_amount: -200` and side `D`.
- The runner restores previous tenancy and the original default connection in nested `finally` blocks, including initialization/query failures. Missing-account/classification validation and overall aggregation occur after restoration. This method introduces no cache, cross-database joins, API wiring or changes to existing ledger methods.

Example:

```php
$breakdown = app(GeneralLedgerService::class)->getAccountBreakdown(
    '1001', '2026-07-01', '2026-07-31', ['tenant-a', 'tenant-b', 'tenant-c', 'tenant-a']
);
```

Example response:

```json
{
  "account": {
    "code": "1001",
    "name": "Cash",
    "category": {
      "category_code": "CURRENT_ASSET",
      "category_name": "Current Asset",
      "type": "ASSET"
    },
    "normal_balance": "D"
  },
  "period": {"start_date": "2026-07-01", "end_date": "2026-07-31"},
  "tenant_ids": ["tenant-a", "tenant-b", "tenant-c"],
  "tenants": [
    {"tenant_id": "tenant-a", "total_debit": 150, "total_credit": 230, "net_amount": -80, "balance_side": "K"},
    {"tenant_id": "tenant-b", "total_debit": 400, "total_credit": 50, "net_amount": 350, "balance_side": "D"},
    {"tenant_id": "tenant-c", "total_debit": 0, "total_credit": 0, "net_amount": 0, "balance_side": null}
  ],
  "totals": {"total_debit": 550, "total_credit": 280, "net_amount": 270},
  "calculation_basis": "debit_minus_credit"
}
```

### `ReportService`

File: [`src/Services/ReportService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/ReportService.php)

Purpose: legacy custom-range General Ledger summary, category-tree financial reports, inclusive month-range profit/loss movements, and separate results for explicitly selected tenants.

Dependencies: `AccountCategoryTreeService`, `AccountCategoryRepository`, `AccountRepository`, `AccountingTableResolver`; `MonthlyBalance` and `AccountingConnectionResolver` for transaction data; container-resolved `TenantReportRunner` when tenant IDs are supplied. Range methods query journal headers/details on the resolved transaction connection and recognize the package's opening-journal source markers. `profitLossDetail()` also resolves `ProfitLossSummaryCalculator` and `ServiceRepository` (closing-service markers through the master-data resolver).

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `generalLedger($accountId, $startDate, $endDate)` | All required | `array` | Legacy custom-date-range summary with account/category path and opening balance. It does not return journal details. |
| `trialBalance($year, $month)` | Year/month required | `array` | Tree data plus total assets, liabilities, and equity. |
| `profitLoss($year, $month, ?array $tenantIds = null)` | Year/month required; nullable tenant list defaults to `null` | `array` | Returns revenue/expense trees and `net_income` for the current context, or a list of separate `{tenant_id, report}` entries for selected tenants. It never consolidates tenants. |
| `profitLossRange(array $range, ?array $tenantIds = null): array` | Complete inclusive month range required; tenant list defaults to `null` | `array` | Active context: `{period, report: {data: {revenue, expense}, net_income}}`. Explicit tenants: `{tenant_ids, period, reports: [{tenant_id, report}, ...]}`. Reads posted movements, never snapshots; amounts remain separate per tenant. |
| `profitLossRangePeriod(array $range): array` | Complete inclusive month range required | `array` | Validates the range without executing a report; returns integer `year`, `month`, `start_year`, `start_month`, `end_year`, `end_month`. `year/month` identify the end month. Shared by the range report/summary entry points. |
| `balanceSheet($year, $month, ?array $tenantIds = null)` | Year/month required; nullable tenant list defaults to `null` | `array` | Returns asset/liability/equity trees and totals for the current context, or a list of separate `{tenant_id, report}` entries for selected tenants. It never consolidates tenants. |
| `profitLossDetail($year, $month, ?array $tenantIds = null)` | Year/month required; nullable tenant list as for `balanceSheet()` | `array` | `{prior_months_profit_loss, current_month_profit_loss}` for the active context, or runner output `[{tenant_id, report}]` when tenant IDs are supplied. Same exclusions as the consolidated profit detail methods. |
| `profitLossDetailMarkers(): array` | None | `array` | Validated `{closing_service_codes, closing_source_types}` from `accounting.profit_loss_detail`, falling back to the defaults per missing key. |
| `cashFlow($year, $month)` | Year/month required | `array` | Operating/investing/financing tree data and net cash flow. |

Report rules:

- The existing monthly methods and legacy General Ledger have no declared PHP return type; their runtime results are arrays. Year/month arguments are required, untyped, and have no defaults. Those `ReportService` methods do not themselves validate the period. The HTTP financial-report actions require an integer year and a month from 1 to 12; consolidated/summary monthly methods additionally enforce year >= 1. New range methods validate their input and declare `array` return types.
- Monthly financial reports read the requested month's stored `MonthlyBalance` rows on the resolved transaction connection and master accounts/categories through repositories. Missing snapshots contribute zero; these reports do not use the live fallback in `AccountBalanceService` or generate closing snapshots.
- Category balances include direct account balances plus descendant subtotals. Profit/loss groups root `REVENUE` and `EXPENSE` nodes and subtracts expense totals from revenue totals. Balance sheet groups root `ASSET`, `LIABILITY`, and `EQUITY` nodes.
- The legacy General Ledger returns account/category path and opening balance only. It adjusts opening balance by posted movements from month start to a non-first-day start date; it does not return detail rows.
- `trialBalance()` and `cashFlow()` remain current-context reports and do not accept a tenant list. Trial balance returns `data` and asset/liability/equity totals; cash flow returns operating/investing/financing groups and `net_cash_flow`.

Tenant-list behavior (also reused by consolidated reports and summaries):

- Omit `$tenantIds`, or pass PHP `null`, to preserve the existing current-connection payload. Direct PHP calls do not initialize a tenant from a route/header; callers use their active application/tenant context. The HTTP actions retain their existing path/`X-Tenant` initialization only when `tenant_ids` is absent.
- An explicit list must be a nonempty sequential array of nonblank integer/string identifiers. Empty, associative, nested, or otherwise invalid lists are rejected. Explicit HTTP `tenant_ids=null` is invalid rather than an omitted parameter.
- `TenantReportRunner` requires the host `tenancy()` integration and an Eloquent `tenancy.tenant_model`. It trims identifiers, resolves primary key then route key, validates the entire list before running any report, and deduplicates both repeated IDs and aliases for the same record. Results use canonical tenant primary keys as strings, in first-resolved order.
- Each tenant's report runs separately after host tenancy initialization. An unresolved tenant, unavailable integration, initialization failure, or mismatched initialized identity fails explicitly; no tenant is silently skipped and no default database is substituted for it. There is no partial-success response.
- The runner restores the previous tenant, or ends tenancy if previously inactive, in `finally`. A nested `finally` restores the original Laravel default connection even if tenancy cleanup throws. Validation failures before switching leave the caller context unchanged.
- With shared master mode enabled, master metadata stays on its configured connection while monthly balances or range journal movements follow the active tenant/application transaction connection. With shared mode disabled, master data also follows that context. Existing explicit model/resolver/repository connection overrides remain effective; no cross-database join or new report cache is introduced. Report trees use unscoped `AccountRepository::allOrdered()`; selecting tenants does not add a master-account `tenant_id` ownership filter.

Monthly return structures:

- Current-context profit/loss: `{data: {revenue: [...], expense: [...]}, net_income: number}`.
- Current-context balance sheet: `{data: {asset: [...], liability: [...], equity: [...]}, total_assets: number, total_liabilities: number, total_equity: number}`.
- An explicit list for either method: `[{tenant_id: string, report: <current-context payload>}, ...]`. There is no additional period wrapper or combined total on this list.
- The two HTTP actions wrap the returned payload/list in `{status: 200, message: string, data: ...}`. Their route/query name is `tenant_ids[]`; the PHP parameter is `$tenantIds`. Explicit lists select report targets without first initializing the path/header tenant. Consolidated reports and summaries below are PHP entry points, not additional HTTP endpoints.

Example:

```php
use ESolution\LaravelAccounting\Services\ReportService;

$reports = app(ReportService::class);
$activeProfitLoss = $reports->profitLoss(2026, 7);
$perTenantProfitLoss = $reports->profitLoss(2026, 7, ['tenant-a', 'tenant-b', 'tenant-a']);
$perTenantBalanceSheets = $reports->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']);
// The repeated tenant-a is returned once; each report remains separate.
```

See [per-tenant request/response examples](./tenant-financial-reports.md).

Month-range rules (also used by the range summaries and consolidated range methods):

- `$range` must contain `start_year`, `start_month`, `end_year`, `end_month`. Years must be integers >= 1, months integers 1..12, and the start period must not follow the end period. Integer strings accepted by Laravel validation are normalized to integers. Empty, incomplete, malformed, or reversed ranges raise `ValidationException` before tenant execution. Equal start/end periods are valid.
- The range includes all transaction dates from the first day of the start month through the final day of the end month, including leap days and cross-year periods. Only `posted` journals contribute. Both `OPENING_BALANCE` and `AccountOpeningBalanceService::SOURCE_TYPE` (`ACCOUNT_OPENING_BALANCE`) are excluded, including reversal journals carrying those markers. Ordinary null source types are included. Classification does not inspect labels or reference text to infer opening journals.
- Posted ordinary reversals retain their signed debit/credit effects on their own transaction dates. Draft and other non-posted statuses are excluded. No opening balance, cumulative snapshot, monthly ending balance, or prior-range activity is added. Open months and months without fiscal-period or snapshot rows are supported; empty periods retain the chart with zero amounts.
- Movement balances use category normal-balance conventions: revenue is credit minus debit, expense is debit minus credit. Trees, account fields, category paths and direct-account/descendant subtotals use the existing report helpers. `net_income` is revenue minus expenses, rounded to two decimals.
- Omitted/null `$tenantIds` reads the active connection and does not switch tenancy based on a route/header. Explicit lists use the validation, deduplication and `finally` restoration rules above. Each source report is fully materialized in its tenant context. Separate results never consolidate amounts.
- Range results have a period envelope; monthly payloads and behavior are unchanged. `period.year/month` identify the end month, while all four range fields identify the full selection. These are PHP service methods; existing controllers/routes are unchanged.

Example:

```php
$range = ['start_year' => 2026, 'start_month' => 11, 'end_year' => 2027, 'end_month' => 1];
$reports = app(\ESolution\LaravelAccounting\Services\ReportService::class);
$current = $reports->profitLossRange($range);
$separate = $reports->profitLossRange($range, ['tenant-a', 'tenant-b', 'tenant-a']);
// $current['report']['net_income']; $separate['reports'][0]['tenant_id'];
// $separate['reports'][0]['report']['data']['revenue']; amounts are not combined.
```

The example's `period` is:

```json
{
  "year": 2027,
  "month": 1,
  "start_year": 2026,
  "start_month": 11,
  "end_year": 2027,
  "end_month": 1
}
```

### `ConsolidatedReportService`

File: [`src/Services/ConsolidatedReportService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/ConsolidatedReportService.php)

Purpose: arithmetic consolidation of monthly and month-range profit/loss reports/summaries, compact profit/loss summaries grouped by tenant, balance-sheet reports, and category charts for service retrieval.

Dependencies: constructor-injected `ReportService`, `TenantReportRunner`, `AccountCategoryRepository`, and `AccountRepository`; consolidated summary methods resolve `ProfitLossSummaryCalculator`, grouped summary methods resolve `ProfitLossSummaryService`, and `categoryTree()` resolves `AccountBalanceService` from the Laravel container. Balance-sheet summaries also read `FiscalPeriod`, `MonthlyBalance` and `JournalEntry` on each tenant's transaction connection to classify snapshots, read `Account` with the category repository to detect legacy-signed snapshots, call `ReportService::profitLossRange()` for current-year profit/loss, and read `accounting.balance_sheet_summary` and `accounting.fiscal.start_month`. Profit detail methods call `ReportService::profitLossDetailMarkers()` and `profitLossDetail()`. The existing four-argument constructor is unchanged.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `profitLoss($year, $month, array $tenantIds): array` | All required; year integer >= 1, month 1..12, nonempty valid tenant list | `array` | Returns `{tenant_ids, period: {year, month}, report: {data: {revenue, expense}, net_income}}` with amounts combined across selected tenants. |
| `balanceSheet($year, $month, array $tenantIds): array` | All required; year integer >= 1, month 1..12, nonempty valid tenant list | `array` | Returns `{tenant_ids, period: {year, month}, report: {data: {asset, liability, equity}, total_assets, total_liabilities, total_equity}}`. |
| `profitLossSummary($year, $month, array $tenantIds): array` | All required; same period/list requirements | `array` | Calls `profitLoss()` and applies the shared summary classifications/formulas. Returns `{tenant_ids, period: {year, month}, summary}`; it does not replace or alter the ordinary report payload. |
| `profitLossRange(array $range, array $tenantIds): array` | Complete inclusive month range and nonempty valid tenant list required | `array` | Returns `{tenant_ids, period, report: {data: {revenue, expense}, net_income}}`, summing each tenant's range movements by stable codes and rebuilding subtotals. Uses the six-field range period documented above. |
| `profitLossSummaryRange(array $range, array $tenantIds): array` | Complete inclusive month range and nonempty valid tenant list required | `array` | Returns `{tenant_ids, period, summary}`. Applies the existing mappings/calculator to `profitLossRange()` account balances after context restoration. |
| `profitLossSummaryByTenant($year, $month, array $tenantIds): array` | All required; integer year >= 1, month 1..12, nonempty valid tenant list; no defaults | `array` | Delegates to `ProfitLossSummaryService::summarize()` and returns `{tenant_ids, period: {year, month}, summaries: [{tenant_id, net_sales, gross_profit, operating_profit, net_profit}, ...]}`. Each row contains only that tenant's four calculated profit values. |
| `profitLossSummaryRangeByTenant(array $range, array $tenantIds): array` | Complete inclusive month range and nonempty valid tenant list required; no defaults | `array` | Delegates to `ProfitLossSummaryService::summarizeRange()` and returns the same compact per-tenant rows with its six-field range `period`. Never combines tenant amounts. |
| `balanceSheetSummary($year, $month, array $tenantIds): array` | All required; integer year >= 1, month 1..12, nonempty valid tenant list | `array` | Returns `{tenant_ids, period: {year, month}, current_year_period, summary: {status, <metrics>, snapshots: [{tenant_id, status}, ...]}}`. Amounts are the `balanceSheet()` month-end amounts consolidated by stable codes; ratios are calculated from those consolidated amounts. Snapshot metrics are `null` unless every tenant's snapshot is `current`. |
| `balanceSheetSummaryByTenant($year, $month, array $tenantIds): array` | All required; same period/list requirements | `array` | Returns `{tenant_ids, period: {year, month}, current_year_period, summaries: [{tenant_id, status, <metrics>}, ...]}`. Each row uses only that tenant's `ReportService::balanceSheet()` tree and profit/loss. Snapshot metrics are `null` when that tenant's status is not `current`. Never combines tenant amounts. |
| `balanceSheetSummaryByMonth(array $range, array $tenantIds): array` | Complete inclusive `start_year`, `start_month`, `end_year`, `end_month` and nonempty valid tenant list required | `array` | Returns `{tenant_ids, period, summaries: [{period: {year, month}, current_year_period, summary}, ...]}`, one entry per month in chronological order. Each `summary` has the `balanceSheetSummary()` shape for that month's end, consolidated across the selected tenants. Months are never summed together. `period` is the six-field range period. |
| `balanceSheetProfitDetail($year, $month, array $tenantIds): array` | All required; integer year >= 1, month 1..12, nonempty valid tenant list | `array` | Returns `{tenant_ids, period: {year, month}, summary: {prior_months_profit_loss, current_month_profit_loss}}`. Informational posted profit before and within the month, summed across tenants. Excludes opening and closing-transfer journals. Never added to equity. |
| `balanceSheetProfitDetailByTenant($year, $month, array $tenantIds): array` | All required; same period/list requirements | `array` | Returns `{tenant_ids, period: {year, month}, summaries: [{tenant_id, prior_months_profit_loss, current_month_profit_loss}, ...]}`. Never combines tenant amounts. |
| `balanceSheetProfitDetailByMonth(array $range, array $tenantIds): array` | Complete inclusive range and nonempty valid tenant list required | `array` | Returns `{tenant_ids, period, summaries: [{period: {year, month}, summary}, ...]}`, one consolidated entry per month in chronological order. `period` is the six-field range period. |
| `categoryTree(array $tenantIds, ?int $year = null, ?int $month = null, bool $withBalance = false): array` | Required nonempty valid tenant list; optional balance period and inclusion flag | `array` | Returns `{tenant_ids, data: [category nodes], category_aliases: {source_uuid: [category_codes]}}`. Merges full visible charts before `AccountCategoryTreeService::index()` applies filters and pagination. Defaults to metadata only; balance periods default through `AccountBalanceService`. |

Tenant-grouped summary rules:

- Both `ByTenant` methods return flat `summaries` rows with exactly `tenant_id`, `net_sales` (Net Sales), `gross_profit` (Gross Profit), `operating_profit` (Operating Profit), and `net_profit` (Net Profit). These values come directly from the existing per-tenant summary calculations and configurable mappings, including account-over-category precedence and signed deductions. Components are summed within each tenant only; no overall summary or cross-tenant comparison/merge is performed.
- Monthly summaries retain the existing monthly-snapshot calculation. Range summaries retain posted-movement calculations for the inclusive `start_year`, `start_month`, `end_year`, `end_month`, including unclosed months and excluding opening journals. The range `period` retains all four fields plus `year/month` identifying the end month; monthly `period` remains `{year, month}`.
- Delegation preserves period validation, complete-list tenant validation, primary/route-key alias deduplication, canonical string tenant IDs, first-selected ordering, mapping snapshots and existing split-database/repository overrides. The underlying runner restores the original tenancy and default connection in nested `finally` blocks before the compact rows are projected. Query/initialization/classification failures propagate; invalid tenants are never skipped.
- Existing consolidated methods and responses remain unchanged. The grouped methods are PHP service entry points and introduce no route, controller, binding or cache changes.

Grouped example:

```php
$reports = app(\ESolution\LaravelAccounting\Services\ConsolidatedReportService::class);
$monthlyByTenant = $reports->profitLossSummaryByTenant(2026, 7, ['tenant-a', 'tenant-b', 'tenant-a']);
$rangeByTenant = $reports->profitLossSummaryRangeByTenant(
    ['start_year' => 2026, 'start_month' => 11, 'end_year' => 2027, 'end_month' => 1],
    ['tenant-a', 'tenant-b']
);
```

Example monthly response:

```json
{
  "tenant_ids": ["tenant-a", "tenant-b"],
  "period": {"year": 2026, "month": 7},
  "summaries": [
    {"tenant_id": "tenant-a", "net_sales": 1130, "gross_profit": 730, "operating_profit": 515, "net_profit": 547},
    {"tenant_id": "tenant-b", "net_sales": 1970, "gross_profit": 1070, "operating_profit": 745, "net_profit": 812}
  ]
}
```

Balance-sheet summary rules:

- Each month is a position as of that month's end, read from the existing `MonthlyBalance` snapshots through `ReportService::balanceSheet()`. Snapshots are never added across months, and balance-sheet amounts never fall back to live journals. Like `balanceSheet()`, amounts contain only `ASSET`, `LIABILITY` and `EQUITY` root categories.
- `<metrics>` are, in this order:

  | Key | Formula | Source | `null` when |
  | --- | --- | --- | --- |
  | `total_assets` | Sum of `ASSET` roots | Snapshot | Status not `current` |
  | `total_liabilities` | Sum of `LIABILITY` roots | Snapshot | Status not `current` |
  | `total_equity` | Sum of `EQUITY` roots | Snapshot | Status not `current` |
  | `current_assets` | Asset accounts classified `current` | Snapshot | Status not `current` |
  | `current_liabilities` | Liability accounts classified `current` | Snapshot | Status not `current` |
  | `current_ratio` | `current_assets / current_liabilities` | Derived | Status not `current`, or `current_liabilities` is 0 |
  | `debt_ratio` | `total_liabilities / total_assets × 100` | Derived | Status not `current`, or `total_assets` is 0 |
  | `current_year_profit_loss` | Posted revenue − expenses from the fiscal-year start through the reporting month | Journals | Never |
  | `balance_sheet_difference` | `total_assets − (total_liabilities + total_equity + current_year_profit_loss)` | Derived | Status not `current` |

- Amounts are rounded to 2 places. Ratios divide those rounded amounts and are then rounded to 2 places (`debt_ratio` is a percentage). A zero denominator returns `null`, never 0 or infinity. Negative denominators are calculated as stored.
- Consolidated ratios and the difference use the consolidated amounts (sum of tenant amounts by stable code). Tenant ratios are never added or averaged.
- `current_year_period` is `{start_year, start_month, end_year, end_month}`. The start is the most recent `accounting.fiscal.start_month` (1–12, default 1) on or before the reporting month. For example, with `start_month = 7`, June 2026 uses July 2025–June 2026, and July 2026 uses July 2026 only. `current_year_profit_loss` reuses `ReportService::profitLossRange()` for that range in each tenant: posted journals only, signed by category normal balance, excluding `OPENING_BALANCE` and `ACCOUNT_OPENING_BALANCE` journals. The consolidated value reuses the code merger above. It does not read snapshots, so it is reported even when the snapshot status is `missing` or `stale`.
- Current-year profit is counted exactly once. Equity totals contain only `EQUITY` categories, never revenue/expense balances. When a host posts an entry moving profit into equity, that entry also debits/credits the revenue/expense accounts, so the journal-based profit drops by the same amount that equity rises. Profit from earlier fiscal years that was never moved into equity (the package does not post year-end closing entries) remains in `balance_sheet_difference`.
- Current classification. Each asset/liability account is `current` or `non_current`: an `account_mappings` entry for its stable account code wins, otherwise the nearest ancestor category with a `category_mappings` entry, otherwise `non_current`. Defaults map the seeded `CURRENT_ASSET` and `CURRENT_LIABILITY` categories, so their descendants (cash, receivables, inventory, prepaid) are current. Host overrides are merged over the defaults:

  ```php
  // config/accounting.php
  'balance_sheet_summary' => [
      'account_mappings' => ['1601' => 'current'],            // stable account code => current|non_current
      'category_mappings' => [
          'CURRENT_ASSET' => 'current',
          'CURRENT_LIABILITY' => 'current',
          'SHORT_TERM_INVESTMENT' => 'current',                // stable category code
      ],
  ],
  ```

  No account names or UUIDs are used. Mappings that apply to equity accounts or codes absent from a tenant have no effect. An invalid shape or a value other than `current`/`non_current` throws `ValidationException` under `balance_sheet_mappings`. An invalid `fiscal.start_month` throws under `fiscal_start_month`.
- Every tenant runs once per call, through the existing `TenantReportRunner`. In that tenant's transaction database, the method reads `FiscalPeriod`, `MonthlyBalance` and the earliest posted `JournalEntry`, then classifies each requested month:

  | Status | Meaning | Snapshot metrics |
  | --- | --- | --- |
  | `current` | The month is closed, and every month from the earliest posted journal through it is closed with non-decreasing `closed_at` (the carry-forward chain is intact), with no legacy-signed snapshot in that chain. | Numbers |
  | `stale` | Snapshots exist but cannot be trusted. Either the month was reopened (open with retained snapshots), or an earlier month in its chain is open, was closed after it, or holds legacy-signed rows. | `null` |
  | `missing` | The month is not closed and has no snapshots, for example the current open month or a month before closing started. | `null` |

- Consolidated `status` is `missing` if any tenant is missing, otherwise `stale` if any tenant is stale, otherwise `current`. `snapshots` lists each tenant's own status, so callers can see which tenant blocks the metrics. Unusable months are not queried for balance-sheet amounts. Reclose them in chronological order (or close the missing months) to make them `current`. Closing writes the snapshot rows, so a closed month without rows (no accounts) counts as `current`.
- Balance signs. Report amounts are natural balances: assets debit-positive; liabilities and equity credit-positive. `closeMonth()` now determines the normal balance case-insensitively. Earlier versions compared the uppercase category type with lowercase names and stored asset/expense snapshots credit-normal (`ending = opening + credit − debit`). An ASSET/EXPENSE snapshot row with non-zero net activity that matches that legacy formula marks its month, and every later month whose chain includes it, as `stale`. Rows seeded without activity totals are never flagged. To repair, reopen the affected months (latest first) and close them again in chronological order from the first legacy month.
- Validation runs before any tenant is initialized: the year/month (`ProfitLossSummaryCalculator::period()`), the range (`ReportService::profitLossRangePeriod()`), the mappings, `fiscal.start_month` and the full tenant list. Consolidation reuses the code merger and definition-compatibility checks above: for revenue/expense in every month, and for asset/liability/equity in `current` months. The per-tenant method does not merge definitions. Query and initialization failures propagate after the runner restores the caller's tenant and default connection.

Balance-sheet summary example:

```php
$reports = app(\ESolution\LaravelAccounting\Services\ConsolidatedReportService::class);
$combined = $reports->balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b']);
$byTenant = $reports->balanceSheetSummaryByTenant(2026, 7, ['tenant-a', 'tenant-b']);
$byMonth = $reports->balanceSheetSummaryByMonth(
    ['start_year' => 2026, 'start_month' => 6, 'end_year' => 2026, 'end_month' => 8],
    ['tenant-a', 'tenant-b']
);
```

Example `balanceSheetSummary(2026, 7, ['tenant-a', 'tenant-b'])` response:

```json
{
  "tenant_ids": ["tenant-a", "tenant-b"],
  "period": {"year": 2026, "month": 7},
  "current_year_period": {"start_year": 2026, "start_month": 1, "end_year": 2026, "end_month": 7},
  "summary": {
    "status": "current",
    "total_assets": 875, "total_liabilities": 265, "total_equity": 470,
    "current_assets": 725, "current_liabilities": 205,
    "current_ratio": 3.54, "debt_ratio": 30.29,
    "current_year_profit_loss": 140, "balance_sheet_difference": 0,
    "snapshots": [{"tenant_id": "tenant-a", "status": "current"}, {"tenant_id": "tenant-b", "status": "current"}]
  }
}
```

The consolidated `current_ratio` is 725 / 205 = 3.54, not the average (3.35) of the tenant ratios 2.5 and 4.2.

Example `balanceSheetSummaryByTenant(2026, 7, ...)` rows:

```json
[
  {"tenant_id": "tenant-a", "status": "current", "total_assets": 300, "total_liabilities": 100, "total_equity": 120,
   "current_assets": 200, "current_liabilities": 80, "current_ratio": 2.5, "debt_ratio": 33.33,
   "current_year_profit_loss": 80, "balance_sheet_difference": 0},
  {"tenant_id": "tenant-b", "status": "stale", "total_assets": null, "total_liabilities": null, "total_equity": null,
   "current_assets": null, "current_liabilities": null, "current_ratio": null, "debt_ratio": null,
   "current_year_profit_loss": 60, "balance_sheet_difference": null}
]
```

(tenant-b's July was reopened.)

Example `balanceSheetSummaryByMonth()` entry for an open August:

```json
{"period": {"year": 2026, "month": 8},
 "current_year_period": {"start_year": 2026, "start_month": 1, "end_year": 2026, "end_month": 8},
 "summary": {"status": "missing", "total_assets": null, "total_liabilities": null, "total_equity": null,
   "current_assets": null, "current_liabilities": null, "current_ratio": null, "debt_ratio": null,
   "current_year_profit_loss": 140, "balance_sheet_difference": null,
   "snapshots": [{"tenant_id": "tenant-a", "status": "missing"}, {"tenant_id": "tenant-b", "status": "missing"}]}}
```

Balance-sheet profit detail rules:

- These figures are informational. They are never added to `total_equity`, `total_liabilities`, `balance_sheet_difference` or any balance-sheet tree, so profit already transferred into equity is not counted twice. Snapshots and fiscal-period status are not read. Open, reopened and never-closed months are calculated the same way. The response therefore has no `status`.
- `prior_months_profit_loss` is the posted revenue/expense movement dated before the first day of the requested month, with no lower bound, so every prior fiscal year is included. `accounting.fiscal.start_month` does not reset it.
- `current_month_profit_loss` is the posted movement from the first to the last day of the requested month.
- Profit is `Σ credit − Σ debit` over journal details whose account belongs to a `REVENUE` or `EXPENSE` category (revenue − expense). Accounts and category types come from the existing account/category repositories in the tenant context. No account IDs or names are used. Only `posted` journals count. Amounts are rounded to 2 places. `prior_months_profit_loss + current_month_profit_loss` is the cumulative operating profit through the month's end.
- Excluded journals:

  | Marker | Default values | Meaning |
  | --- | --- | --- |
  | `journal_entries.source_type` | `OPENING_BALANCE`, `ACCOUNT_OPENING_BALANCE` (always) | Opening-balance journals (`journalOpeningBalance()`, `AccountOpeningBalanceService`) |
  | Journal service (`service_id` → `acc_services.service_code`) | `accounting.profit_loss_detail.closing_service_codes`: `MONTH_END_CLOSING`, `YEAR_END_CLOSING` | Profit transfers posted with `journalByMapping()` using the existing closing service templates (revenue → Income Summary 3301 → expense; 3301 → Retained Earnings 3101) |
  | `journal_entries.source_type` | `accounting.profit_loss_detail.closing_source_types`: `MONTH_END_CLOSING`, `YEAR_END_CLOSING` | Manual closing transfers, e.g. `journalManual(['source_type' => 'MONTH_END_CLOSING', ...])` |

  Reversals copy `service_id` and `source_type`, so reversing a closing transfer is excluded too. Service codes are resolved to IDs through `ServiceRepository` in each tenant's master-data context (shared master or tenant-local). They are never joined across connections.
- Configuration:

  ```php
  // config/accounting.php
  'profit_loss_detail' => [
      'closing_service_codes' => ['MONTH_END_CLOSING', 'YEAR_END_CLOSING'],
      'closing_source_types' => ['MONTH_END_CLOSING', 'YEAR_END_CLOSING'],
  ],
  ```

  A missing key keeps its default, and `[]` disables that marker. Use `[]` for `closing_service_codes` if your host posts genuine profit-affecting adjustments (for example accruals) under `MONTH_END_CLOSING`. Values must be lists of non-empty strings, otherwise a `ValidationException` is thrown under `profit_loss_detail`.
- Contrast with the balance-sheet summary's `current_year_profit_loss`, which excludes only opening journals. That metric nets closing transfers so that `balance_sheet_difference` counts profit exactly once. The profit detail methods report actual profit instead.
- Validation runs before any tenant is initialized: the year/month (`ProfitLossSummaryCalculator::period()`), the range (`ReportService::profitLossRangePeriod()`), the markers and the full tenant list. Each tenant runs once per call through `TenantReportRunner`. Journals are read on the tenant's transaction connection, and accounts, categories and services through the master-data resolver. The consolidated amount is the sum of tenant amounts, and accounts are not merged by code. Query and initialization failures propagate after the caller's tenant and default connection are restored.

Profit detail example:

```php
$reports = app(\ESolution\LaravelAccounting\Services\ConsolidatedReportService::class);
$combined = $reports->balanceSheetProfitDetail(2026, 7, ['tenant-a', 'tenant-b']);
$byTenant = $reports->balanceSheetProfitDetailByTenant(2026, 7, ['tenant-a', 'tenant-b']);
$byMonth = $reports->balanceSheetProfitDetailByMonth(
    ['start_year' => 2026, 'start_month' => 6, 'end_year' => 2026, 'end_month' => 7],
    ['tenant-a', 'tenant-b']
);
$single = app(\ESolution\LaravelAccounting\Services\ReportService::class)->profitLossDetail(2026, 7); // active context
```

Two months with closing transfers. Tenant-a earns 50 in June and 30 in July. Tenant-b earns 100 in June and loses 40 in July. Both post month-end closing entries that move profit into equity.

```json
// balanceSheetProfitDetail(2026, 7, ...)
{"tenant_ids": ["tenant-a", "tenant-b"], "period": {"year": 2026, "month": 7},
 "summary": {"prior_months_profit_loss": 150, "current_month_profit_loss": -10}}

// balanceSheetProfitDetailByTenant(2026, 7, ...)
{"tenant_ids": ["tenant-a", "tenant-b"], "period": {"year": 2026, "month": 7},
 "summaries": [
   {"tenant_id": "tenant-a", "prior_months_profit_loss": 50, "current_month_profit_loss": 30},
   {"tenant_id": "tenant-b", "prior_months_profit_loss": 100, "current_month_profit_loss": -40}]}

// balanceSheetProfitDetailByMonth(June–July, ...)
{"tenant_ids": ["tenant-a", "tenant-b"],
 "period": {"year": 2026, "month": 7, "start_year": 2026, "start_month": 6, "end_year": 2026, "end_month": 7},
 "summaries": [
   {"period": {"year": 2026, "month": 6}, "summary": {"prior_months_profit_loss": 0, "current_month_profit_loss": 150}},
   {"period": {"year": 2026, "month": 7}, "summary": {"prior_months_profit_loss": 150, "current_month_profit_loss": -10}}]}
```

Without the exclusions, the closing entries would bring these figures down to the untransferred remainder. Year rollover: profit of 180 in November–December 2025 followed by 15 in January 2026 gives January 2026 `{"prior_months_profit_loss": 180, "current_month_profit_loss": 15}`.

Consolidation rules (for methods that combine tenants):

- There is no current-tenant/default-list shortcut: `$tenantIds` is required and cannot be empty. Tenant validation, deduplication, initialization, and `finally` restoration follow the `ReportService` tenant-list rules above. Monthly financial reports/summaries require year and month; range methods require the complete validated range instead.
- Each tenant's ordinary report and metadata are materialized in its own context. After the runner restores the caller context, consolidation aligns categories by `category_code` and accounts by `code`, not UUID. Codes are exact strings, including case and leading zeros. Categories/accounts absent from some tenants are included from the others.
- Matching category definitions must agree on parent **code**, type, category name, report type, and status. Matching account definitions must agree on category **code**, name, postable flag, and status. Every occurrence of a participating code is checked, including when another tenant places it outside the requested report. Unrelated codes outside the requested report are not compared.
- Definition conflicts throw `ValidationException` under `consolidation`, naming the code, incompatible field, and tenant. Invalid hierarchy references, duplicate source category/account occurrences, and invalid account balances also fail explicitly.
- Only corresponding account balances are summed. Category balances are rebuilt from direct accounts plus immediate child subtotals; report totals use root subtotals only. Original category/report totals are not added again. Signed values are retained and sums are rounded to two decimal places.
- Consolidated category nodes contain `category_code`, `parent_code`, `type`, `category_name`, `report_type`, `status`, `children`, `accounts`, and `balance`. Accounts contain `code`, `category_code`, `name`, `is_postable`, `status`, and `balance`. Database UUID fields are omitted; roots, children, and accounts are sorted by code.
- `profitLossSummary()` snapshots the configured mappings before calling `profitLoss()`, then calculates from the merged account balances after context restoration. It uses the same summary fields, precedence, sign rules, and formulas documented under `ProfitLossSummaryService` below. Classification errors therefore also leave the caller's tenant/connection restored.
- `profitLossRange()` collects ordinary range reports and definitions in each tenant context, then reuses the same code merger and compatibility checks after restoration. Source category/report totals are not added again. `profitLossSummaryRange()` snapshots the same configurable mappings and calculates from this merged report. Both validate ranges before source execution and retain the restoration behavior on query, initialization, definition and classification failures.
- `categoryTree()` reuses code alignment, definition checks and account aggregation, reading central plus tenant-visible accounts through the existing tenancy integration. Its nodes also include `sequence_no` and account `description`; categories follow first-selected-source sequence/name/code ordering. Without `$withBalance`, zero balance placeholders are returned without querying balances. With it, category balances use the existing `AccountBalanceService` snapshot/live fallback. `category_aliases` supports parent UUID lookup; use `AccountCategoryTreeService::index()` for filtered, API-shaped items with code-based IDs.
- This is arithmetic consolidation only: no intercompany eliminations, currency conversion, new report cache, or additional HTTP endpoint. Category and range methods are PHP service entry points only. Existing monthly financial report responses and snapshot-only behavior are preserved, as are shared/tenant-local master modes and explicit connection overrides.

Example:

```php
use ESolution\LaravelAccounting\Services\ConsolidatedReportService;

$reports = app(ConsolidatedReportService::class);
$profitLoss = $reports->profitLoss(2026, 7, ['tenant-a', 'tenant-b']);
$balanceSheet = $reports->balanceSheet(2026, 7, ['tenant-a', 'tenant-b']);
$summary = $reports->profitLossSummary(2026, 7, ['tenant-a', 'tenant-b']);
$chart = $reports->categoryTree(['tenant-a', 'tenant-b'], 2026, 7, true);
$range = ['start_year' => 2026, 'start_month' => 11, 'end_year' => 2027, 'end_month' => 1];
$combinedRange = $reports->profitLossRange($range, ['tenant-a', 'tenant-b']);
$combinedRangeSummary = $reports->profitLossSummaryRange($range, ['tenant-a', 'tenant-b']);
// $summary['tenant_ids'], $summary['period'], $summary['summary']['net_profit']
```

See [consolidated report structures and examples](./consolidated-reports.md).

### `ProfitLossSummaryService`

File: [`src/Services/ProfitLossSummaryService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/ProfitLossSummaryService.php)

Purpose: returns a four-stage profit/loss summary per selected tenant, or for the active connection with a month range, without consolidating amounts.

Dependencies: constructor-injected `ReportService` and `ProfitLossSummaryCalculator`; tenant processing delegates to `ReportService::profitLoss()` or `profitLossRange()` and its existing `TenantReportRunner` path.

| Method | Parameters | Returns | Description |
| --- | --- | --- | --- |
| `summarize($year, $month, array $tenantIds): array` | All required; year integer >= 1, month 1..12, nonempty valid tenant list; no defaults | `array` | Returns `{tenant_ids, period: {year, month}, summaries: [{tenant_id, summary}, ...]}`. Each tenant's report is classified separately using the same mapping snapshot. |
| `summarizeRange(array $range, ?array $tenantIds = null): array` | Complete inclusive month range required; tenant list defaults to `null` | `array` | Active context: `{period, summary}`. Selected tenants: `{tenant_ids, period, summaries: [{tenant_id, summary}, ...]}`. Uses posted range movements and the same classification snapshot/formulas; never consolidates tenants. |

Summary behavior (shared with both consolidated summary methods):

- Tenant IDs are validated/deduplicated and source reports are read/restored through the existing report path described above. IDs are canonical strings; period values are integers. Calculations run on materialized report data after the runner's `finally` restoration. There is no partial-success response or additional HTTP envelope on these PHP results.
- Every `summary` contains numeric `sales_revenue`, `sales_returns`, `sales_discounts`, `cogs`, `other_operating_income`, `operating_expenses`, `non_operating_income`, `non_operating_expenses`, and `income_tax`, plus the four calculated fields below. Empty components are returned as zero.

```text
net_sales        = sales_revenue - sales_returns - sales_discounts
gross_profit     = net_sales - cogs
operating_profit = gross_profit + other_operating_income - operating_expenses
net_profit       = operating_profit + non_operating_income
                   - non_operating_expenses - income_tax
```

- Each account is counted once. Parent category balances and the source `net_income` are not added to account amounts. Duplicate report codes and invalid balances fail under `summary` rather than being double counted. Unmapped nonzero accounts also fail explicitly; unmapped zero balances contribute nothing.
- Income components are credit-positive and deductions are debit-positive, normalized from the account category's `REVENUE`/`EXPENSE` type. A sales-return balance of `-50` in a revenue category becomes a deduction of `50`; a reversal remains a negative deduction. Absolute values are not used. Components and calculated values are rounded to two decimal places.
- The consolidated summary retains all existing code-definition compatibility checks above, including names/status flags. Names are not used to classify summary components; those compatibility checks are distinct from classification.
- `summarizeRange()` first validates the range and snapshots the existing mappings, then delegates to `ReportService::profitLossRange()`. Its six-field `period`, movement exclusions and tenant restoration follow the range rules above. Monthly `summarize()` remains unchanged. Both range summary entry points retain account-over-category precedence, ancestor mapping inheritance, signed deductions, and the generic-tax default; no new classification rules are introduced.

Range summary example:

```php
$range = ['start_year' => 2026, 'start_month' => 11, 'end_year' => 2027, 'end_month' => 1];
$summaries = app(\ESolution\LaravelAccounting\Services\ProfitLossSummaryService::class);
$active = $summaries->summarizeRange($range);
$separate = $summaries->summarizeRange($range, ['tenant-a', 'tenant-b']);
// $active['summary']['net_profit']; $separate['summaries'][0]['summary']['net_profit'];
```

Configurable summary mappings:

The host's published `config/accounting.php` contains editable literal arrays at `profit_loss_summary.account_mappings` and `profit_loss_summary.category_mappings`. The corresponding runtime keys are under `accounting.profit_loss_summary`. No separate mapping file or class-constant reference is required in the published config.

| Mapping | Default component |
| --- | --- |
| Account `5210` | `operating_expenses` |
| Account `5601` | `sales_discounts` |
| Account `5701` | `sales_returns` |
| Categories `SALES_REVENUE`, `SERVICE_REVENUE` | `sales_revenue` |
| Category `COST_OF_GOODS_SOLD` | `cogs` |
| Category `OPERATING_EXPENSE` | `operating_expenses` |
| Category `OTHER_REVENUE` | `non_operating_income` |
| Category `OTHER_EXPENSE` | `non_operating_expenses` |

- Exact account-code rules take precedence over the nearest category/ancestor-code rule, including seeded account defaults. Partial host overrides merge over compatibility defaults. Category/account UUIDs and translated labels do not drive classification.
- Other operating income and income tax require explicit classifications. Account `5210` remains an ordinary operating expense unless its account mapping is explicitly changed to `income_tax`; a generic or translated tax label never implies income tax.
- Each call snapshots one set of rules before tenant iteration. The per-tenant and consolidated summary entry points use the same component identifiers, precedence, and formulas. Invalid mapping configuration fails under `summary_mappings` before reports run.
- Publish with `php artisan vendor:publish --tag=accounting-config`, edit the host file, then rebuild `php artisan config:cache` when using cached configuration. Existing published files can be edited directly. Stored snapshots, seeded chart data, and existing report/API responses remain unchanged.

Example:

```php
use ESolution\LaravelAccounting\Services\ProfitLossSummaryService;

$summaries = app(ProfitLossSummaryService::class)
    ->summarize(2026, 7, ['tenant-a', 'tenant-b']);
$firstTenantProfit = $summaries['summaries'][0]['summary']['net_profit'];

// Programmatic overrides; use the published config file for persistent settings.
config([
    'accounting.profit_loss_summary.account_mappings.4101' => 'other_operating_income',
    'accounting.profit_loss_summary.account_mappings.5210' => 'income_tax',
]);
```

See [summary mapping and output examples](./profit-loss-summaries.md).

### `ReportingSyncService`

File: [`src/Services/ReportingSyncService.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Services/ReportingSyncService.php)

Copies tenant-local journals, fiscal periods, monthly snapshots and local cutoff state into central tables on `accounting.master_data.connection`. Accounts, categories and services are not copied: central reports read the existing master tables, and copied rows keep their central ids. The `accounting:reporting-*` commands wrap these methods; the host registers their schedule. See [central reporting](./central-reporting.md).

Supported only with shared master data (`accounting.master_data.use_shared_database = true`). `Account`, `AccountCategory` and `Service` must resolve to a configured `master_data.connection` that holds their tables. Every method and every command first calls `CentralReportingRepository::assertUsable()`. That is `assertSupported()` plus a check that the central reporting tables exist. It throws `RuntimeException` otherwise, and commands print the message and exit 1. The central reporting migration (`2026_11_06_000019`, in the unified `database/migrations/accounting` folder) creates its tables only on a run whose connection is the master connection, and only when `supported()` is true. In every other case it is a no-op.

| Method | Returns |
| --- | --- |
| `backfill(array $tenantIds, bool $force = false): array` | Registers tenants, copies everything, drains the outbox and reconciles. Rows: `{tenant_id, result, journals, months, applied, reconcile}`, or `skipped` when already complete. |
| `sync(?array $tenantIds = null, ?int $maxBatches = null, bool $ignoreBackoff = false): array` | Rows: `{tenant_id, result: ok\|failed\|locked\|deferred\|not_backfilled, applied, drained, synced_through}`. `null` selects every registered tenant. |
| `reconcile(?array $tenantIds = null, bool $repair = true): array` | Rows: `{tenant_id, result, status: ok\|drifted, found, remaining}`. `found` / `remaining` hold `journal_months`, `snapshot_months` and `cutoff`. |
| `prune(?int $days = null, ?array $tenantIds = null): array` | Rows: `{tenant_id, result, pruned}`. Deletes acknowledged outbox rows only. |
| `status(?array $tenantIds = null): array` | Rows: `{tenant_id, ready, reasons, lag_seconds, synced_through, backfill_status, sync_status, reconcile_status, reconciled_at, last_success_at, failures, next_attempt_at, last_error, cutoff_date, locked}`. |

Tenant lists accept primary keys or route keys, like `TenantReportRunner`. Results use canonical tenant ids. A failing tenant returns `result: failed` and is recorded for backoff; other tenants still run.

Failure text is readable (since 2026-11-09). The returned `error`, the stored `last_error`, `status()` and the `sync failed:` readiness reason (including `assertReady()` validation messages shown by central reports) never contain SQL. A database failure reads `a database error occurred (SQLSTATE <state>); see the application log`. Other failures keep their message. Each failure adds `(reference <uuid>)`, and the full exception is reported through the host exception handler with the same `reference` in its log context. Rows stored earlier with SQL are sanitized when read.

#### Central read source

`accounting.reporting.read_source` (`ACCOUNTING_REPORTING_READ_SOURCE`) selects where tenant-list reports read their data. Signatures, payloads, calculations and validation are the same in both modes.

| Value | Behaviour |
| --- | --- |
| `live` (default) | The original per-tenant path: `TenantReportRunner::run()` initializes each selected tenant, runs the queries on its transaction connection, then restores the previous tenant (or ends tenancy) and the caller's default connection. It never reads `acc_reporting_*` tables, so it works without the central or outbox migrations, without backfill and with `change_log_enabled = false`. |
| `central` | Reads the synchronized `acc_reporting_*` copies and the existing master tables on `accounting.master_data.connection`. It never initializes tenancy or opens a tenant connection. Setup and sync readiness are checked first. There is no fallback to `live`. |

How the value is resolved (`TenantReportRunner::readSource()`):
- A missing key, a missing `reporting` block, `null` and an empty or whitespace-only value all mean `live`. This covers older published `config/accounting.php` files: Laravel merges package config shallowly, so a published file without a `reporting` block receives the package block (default `live`). A published `reporting` block without `read_source`, or a config cached from an older file, has no key at all.
- Values are trimmed and case-insensitive, so `" Central "` means `central`.
- Any other value (for example `replica`, `true` or `1`) throws `RuntimeException('accounting.reporting.read_source must be live or central.')` before any tenant is resolved or initialized.

Setup examples:

```dotenv
# Existing installs: nothing to change. Omitting the variable, or leaving it empty, keeps live.
ACCOUNTING_REPORTING_READ_SOURCE=live
```

```dotenv
# Central reads, after shared master data, migrations, change log and backfill are in place
ACCOUNTING_USE_SHARED_DATABASE=true
ACCOUNTING_MASTER_CONNECTION=central
ACCOUNTING_REPORTING_CHANGE_LOG_ENABLED=true
ACCOUNTING_REPORTING_READ_SOURCE=central
ACCOUNTING_REPORTING_MAX_LAG_SECONDS=900
```

```bash
php artisan migrate --database=central                          # creates the central reporting tables
php artisan accounting:reporting-backfill --tenant=tenant-a --tenant=tenant-b
php artisan accounting:reporting-status                         # must report every tenant ready
```

If your published `config/accounting.php` predates central reporting, add the block (or republish with `php artisan vendor:publish --tag=accounting-config --force` and reapply your edits). Then run `php artisan config:clear`, or `config:cache` again if you cache config:

```php
'reporting' => [
    'read_source' => env('ACCOUNTING_REPORTING_READ_SOURCE', 'live'),
    'change_log_enabled' => env('ACCOUNTING_REPORTING_CHANGE_LOG_ENABLED', false),
    'max_lag_seconds' => env('ACCOUNTING_REPORTING_MAX_LAG_SECONDS', 900),
],
```

Omitted keys fall back to their defaults: `batch_size` 500, `lock_seconds` 600, `retry_base_seconds` 60, `retry_max_seconds` 3600 and `prune_after_days` 7.

The setting applies to every tenant-list call:
- `ReportService::profitLoss`, `balanceSheet`, `profitLossRange` and `profitLossDetail`;
- the `ConsolidatedReportService` profit/loss, balance-sheet, summary, range, by-tenant, by-month and profit-detail methods;
- `ProfitLossSummaryService::summarize` and `summarizeRange`;
- `ConsolidatedReportService::categoryTree()` and `AccountCategoryTreeService::index()` with tenant lists, including balances; `AccountBalanceService` reads central snapshots, journal totals and the earliest journal date while a central report runs;
- `GeneralLedgerService::getAccountBreakdown()`;
- the `tenant_ids` HTTP report endpoints.

Before any data is read, the configuration is validated as above (`RuntimeException` when unsupported). Then every selected tenant must be backfilled, not failed or drifted, and have `synced_through` within `accounting.reporting.max_lag_seconds`. Otherwise the call throws `ValidationException` under `reporting_sync`, naming each tenant and the reason. Master data is read from the master tables in the same central read transaction, so master edits are visible without a sync.

There is no fallback to tenant queries in central mode. Calls without a tenant list keep their existing behaviour. Methods without a tenant filter (`ReportService::generalLedger|trialBalance|cashFlow`, `GeneralLedgerService` ledger/detail methods) and closing operations remain live.

`TenantReportRunner` gains three methods:
- `runReports(array $tenantIds, callable $report): array` selects live or central;
- `readSource(): string` returns the resolved `live` or `central` value, using the rules above;
- `resolveTenants(array $tenantIds): array` validates and resolves a list without initializing tenancy.
- `allTenantIds(): array` (2026-11-09) returns every canonical tenant id of `tenancy.tenant_model`, ordered by key, without initializing tenancy (used by global monthly closing).

`run()` is unchanged.

#### Reporting outbox

With `accounting.reporting.change_log_enabled` and shared master data, package writes record `{aggregate_type: journal|month|cutoff, aggregate_key, operation}` rows in each tenant's `acc_reporting_change_log`, in the same transaction as the change. When the change log is enabled, `JournalService::journalManual($data, false)` and `post()` run their inserts/update in a transaction even without a caller transaction. Query-builder and raw SQL writes are not recorded; reconciliation repairs them. With tenant-local master data the flag is ignored and nothing is recorded.

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
| ReportController | GET | `/api/accounting/reports/general-ledger/details` |
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
- `type` `string` optional
- When provided, only categories whose stored `type` matches the requested value are returned.
- The value is validated against the distinct category types currently present in `acc_account_categories`.
- `has_accounts` `boolean` optional
- When `true`, only category branches that contain visible accounts are returned.
- A category remains when it has direct accounts or descendant categories with direct accounts.
- `search` `string` optional
- Performs a case-insensitive `LIKE` search against category `category_code` and `category_name`.
- When `with=accounts` is requested, the same search also filters returned accounts by account `code` and `name`.
- Parent categories remain when they are needed to keep matching child categories or matching accounts reachable.
- `year` `integer` optional
- Used only when `with=balance` is requested. Defaults to current year.
- `month` `integer` optional
- Used only when `with=balance` is requested. Defaults to current month.
- `page` `integer` optional
- `per_page` `integer` optional
- When either `page` or `per_page` is present, the endpoint returns Laravel's native `LengthAwarePaginator` payload directly instead of the package success envelope.

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
- When `search` is used together with `with=children`, only matching child branches remain recursively.
- When `search` is used together with `with=accounts`, only matching accounts are serialized.
- When `search` is used together with `has_accounts=true`, only matching account-bearing branches remain.

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
curl --location 'http://127.0.0.1:8000/api/accounting/categories?type=asset' \
--header 'Accept: application/json'
```

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/categories?with=children&search=fixed' \
--header 'Accept: application/json'
```

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/categories?with=children,accounts&search=cash&has_accounts=true' \
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

```bash
curl --location 'http://127.0.0.1:8000/api/accounting/categories?page=1&per_page=10&search=fixed&has_accounts=true' \
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

Example paginated response:

```json
{
  "current_page": 1,
  "data": [
    {
      "id": "uuid",
      "category_code": "FIXED_ASSET",
      "category_name": "Fixed Asset",
      "type": "ASSET",
      "parent_id": "parent-uuid",
      "sequence_no": 2,
      "is_active": true
    }
  ],
  "first_page_url": "http://127.0.0.1:8000/api/accounting/categories?page=1&per_page=10&search=fixed&has_accounts=true",
  "from": 1,
  "last_page": 1,
  "last_page_url": "http://127.0.0.1:8000/api/accounting/categories?page=1&per_page=10&search=fixed&has_accounts=true",
  "links": [
    {
      "url": null,
      "label": "Previous",
      "active": false
    },
    {
      "url": "http://127.0.0.1:8000/api/accounting/categories?page=1&per_page=10&search=fixed&has_accounts=true",
      "label": "1",
      "active": true
    },
    {
      "url": null,
      "label": "Next",
      "active": false
    }
  ],
  "next_page_url": null,
  "path": "http://127.0.0.1:8000/api/accounting/categories",
  "per_page": 10,
  "prev_page_url": null,
  "to": 1,
  "total": 1
}
```

Notes:

- `with=children` switches the endpoint into hierarchical/tree mode.
- This endpoint does not eager-load `accounts` unless `with` explicitly asks for it.
- When `with=accounts` is used, account visibility follows the resolved tenant context, not a query parameter.
- `with=balance` reuses the existing account balance service and loads account balances in bulk before aggregating category totals in memory.
- Category balance is calculated recursively from direct child accounts and descendant categories.
- `search` prunes category branches in memory after categories and visible accounts are loaded once.
- `has_accounts=true` prunes category branches in memory without recursive database queries.
- `type` applies before search and branch pruning so all later filters operate only on the selected category type.
- Cache keys are parameter-aware, so `root_only`, `parent_id`, `type`, `search`, `has_accounts`, and `with` combinations are cached separately.

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
- `opening_balance` optional
- `opening_balance_date` required when `opening_balance` is provided
- `is_postable` optional
- `status` optional

Validation rules:

- `category_id` required and must exist in the category table
- `tenant_id` nullable string max 100
- `code` required string max 30 and unique
- `name` required string max 200
- `description` nullable string
- `opening_balance` nullable numeric min 0
- `opening_balance_date` nullable date and required when `opening_balance` is provided
- `is_postable` nullable boolean
- `status` nullable boolean

Opening balance behavior:

- Opening balance is optional during account creation.
- If `opening_balance > 0`, the package automatically creates one journal through `JournalService::journalManual()`.
- The journal uses:
  - `source_type = ACCOUNT_OPENING_BALANCE`
  - `source_id = {account_id}`
  - `reference_no = OPENING-{ACCOUNT_CODE}`
  - `description = Opening Balance - {ACCOUNT_NAME}`
- Debit or credit placement for the account line follows the category normal balance.
- The balancing line uses the dedicated `Opening Balance Equity` account.
- If the contra account is missing, the request is rejected with a validation error.

Example request:

```json
{
  "category_id": "uuid",
  "tenant_id": "tenant-a",
  "code": "1001",
  "name": "Cash",
  "description": "Kas operasional perusahaan.",
  "is_postable": true,
  "status": true,
  "opening_balance": 1000000,
  "opening_balance_date": "2026-01-01"
}
```

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

Description: returns one account with its direct category, root category, and complete category parent hierarchy.

Path parameters:

- `id` required

Tenant visibility:

- The endpoint resolves the current tenant from `X-Tenant`, existing tenant context, or the tenant-aware route.
- If the requested account is not visible under the resolved tenant, the endpoint returns `404`.

Opening balance response:

- `opening_balance` is `null` when no account opening balance journal exists.
- When an opening balance journal exists, `opening_balance` includes:
  - `amount`
  - `date`
  - `journal_entry_id`
  - `can_edit`
- `can_edit` is `true` only when the opening balance journal exists and no other journal entry exists on or after the opening balance date.

Category response:

- `category` contains the account's direct category.
- `root_category` contains the highest category in the account category hierarchy.
- `category_tree` starts with the direct category and nests each parent under `parent` until the root category, whose `parent` is `null`.
- When the account category cannot be resolved, `category`, `root_category`, and `category_tree` are all `null`.

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
    "opening_balance": {
      "amount": 1000000,
      "date": "2026-01-01",
      "journal_entry_id": "journal-uuid",
      "can_edit": true
    },
    "category": {
      "id": "uuid",
      "name": "Cash"
    },
    "root_category": {
      "id": "root-category-uuid",
      "name": "Asset"
    },
    "category_tree": {
      "id": "uuid",
      "name": "Cash",
      "parent": {
        "id": "parent-category-uuid",
        "name": "Current Asset",
        "parent": {
          "id": "root-category-uuid",
          "name": "Asset",
          "parent": null
        }
      }
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
- `opening_balance` optional
- `opening_balance_date` required when `opening_balance` is provided
- `is_postable` optional
- `status` optional

Validation rules:

- `category_id` nullable and must exist in the category table
- `tenant_id` nullable string max 100
- `code` nullable string max 30 and unique except current record
- `name` nullable string max 200
- `description` nullable string
- `opening_balance` nullable numeric min 0
- `opening_balance_date` nullable date and required when `opening_balance` is provided
- `is_postable` nullable boolean
- `status` nullable boolean

Opening balance behavior:

- Opening balance can still be created the first time by sending `opening_balance` and `opening_balance_date`.
- If an `ACCOUNT_OPENING_BALANCE` journal already exists for the account, the same update endpoint edits that existing journal instead of creating a new one.
- The opening balance journal keeps the same `id`, `source_type`, `source_id`, and `reference_no`.
- Amount-only updates are allowed; the existing opening balance date is reused.
- Date-only updates are allowed; the existing opening balance amount is reused.
- Editing is rejected when other journal transactions already exist on or after the opening balance date.
- Editing is also rejected when the fiscal period is closed or the Opening Balance Equity account is missing.
- The account-side detail line and the contra detail line are both updated so the journal stays balanced.
- Updating other fields such as `name`, `code`, `description`, `status`, or `is_postable` remains allowed.

Example request:

```json
{
  "name": "Cash Updated",
  "description": "Digunakan sebagai account penampung pembayaran customer.",
  "opening_balance": 250000,
  "opening_balance_date": "2026-01-01"
}
```

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

Validation / error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "opening_balance": [
      "Opening Balance cannot be edited because journal transactions already exist."
    ]
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

Query parameters:

- `status` optional boolean filter. Use `status=true` for active services or `status=false` for inactive services. If omitted, all services are returned.

Examples:

```text
GET /api/accounting/services?status=true
GET /api/accounting/services?status=false
```

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
- `updated_by` optional and always part of the request contract
- `status` optional
- `mappings` optional array

Validation rules:

- `service_code` required string max 100 and unique
- `service_name` required string max 200
- `module_name` required string max 100
- `description` nullable string
- `updated_by` nullable and may be either integer or string
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

Notes:

- `updated_by` is stored directly in `acc_services.updated_by`.
- The package does not validate `updated_by` against any users table.
- If `updated_by` is omitted, the stored value remains `NULL`.

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
- `updated_by` optional and always part of the request contract
- `status` optional
- `mappings` optional array

Validation rules:

- `service_code` nullable string max 100 and unique except current record
- `service_name` nullable string max 200
- `module_name` nullable string max 100
- `description` nullable string
- `updated_by` nullable and may be either integer or string
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

Notes:

- When `updated_by` is provided, the package stores it exactly as sent.
- When `updated_by` is omitted, the package does not auto-generate any fallback value.

## Global Hooks

The package now exposes a reusable hook lifecycle for package services and API-backed workflows:

```text
beforeHandle()
  ↓
Business Logic
  ↓
afterHandle()
```

Default behavior:

- No hook classes are registered by default.
- The default hook implementation does nothing, so existing behavior remains unchanged.

Configuration:

- `config/accounting.php`
- `hooks.before` accepts one optional before-hook class.
- `hooks.after` accepts one optional after-hook class.
- `before` must implement `ESolution\LaravelAccounting\Contracts\BeforeApiHook`.
- `after` must implement `ESolution\LaravelAccounting\Contracts\AfterApiHook`.

Hook contract:

- `beforeHandle(string $action, array $context): array`
- `afterHandle(string $action, mixed $result, array $context): mixed`

Current packaged usage:

- `services.store`
- `services.update`

Current action to route mapping:

- `services.store` → `POST /api/accounting/services`
- `services.update` → `PUT /api/accounting/services/{id}`

Hook context:

- `request` when the flow starts from an API endpoint
- `payload` for validated input data
- additional objects such as `service` when relevant
- use `header('X-Tenant')` when hook logic needs the current tenant from the request

Usage notes:

- `beforeHandle()` may validate or rewrite payload values before the business logic runs.
- `afterHandle()` may modify the returned result or trigger post-processing.
- Consuming applications can extend hook behavior by registering custom hook classes in configuration or by overriding the hook manager binding in the container.

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
  "posted_by": 15,
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
- `posted_by` nullable and may be either integer or string
- `details` required|array|min:2
- `details.*.account_id` required and must exist in `acc_accounts`
- `details.*.type` required|in:D,K
- `details.*.amount` required|numeric|gt:0
- `details.*.description` nullable|string
- Total debit must equal total credit
- Account must be active
- Account must be postable
- Fiscal period must be open
- `posted_by` is stored as provided and is not validated against any users table

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
  "posted_by": "5f4d5c8d-1b4b-4e9d-9b5d-5b2d4c2f9c11",
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
  "posted_by": 15,
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
- `posted_by` nullable and may be either integer or string
- `details` required|array|min:2
- `details.*.account_id` required and must exist in `acc_accounts`
- `details.*.amount` required|numeric|not_in:0
- `details.*.description` nullable|string
- All accounts must exist, be active, and be postable
- Duplicate accounts are rejected
- Fiscal period must be open
- The total debit must equal the total credit after the package maps signed amounts using the account category normal balance
- Opening balance can only be created once per database or tenant because the package checks `source_type = OPENING_BALANCE`
- `posted_by` is stored exactly as provided and is not validated against any users table

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
  "posted_by": "5f4d5c8d-1b4b-4e9d-9b5d-5b2d4c2f9c11",
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

Description: returns General Ledger summary information for a single account. Journal details are available exclusively from `GET /api/accounting/reports/general-ledger/details`.

Query parameters:

- `account_id` required uuid
- `year` required integer when `start_date` is not supplied
- `month` required integer between 1 and 12 when `year` is supplied
- `start_date` optional date for the legacy custom-date-range response
- `end_date` required with `start_date` and must be greater than or equal to `start_date`

Monthly General Ledger example:

```text
GET /api/accounting/reports/general-ledger?account_id=uuid&year=2026&month=7
```

Response body:

- `account`
- `period` containing `year`, `month`, `start_date`, and `end_date`
- `opening_balance`
- `total_debit`
- `total_credit`
- `ending_balance`
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
    "period": {
      "year": 2026,
      "month": 7,
      "start_date": "2026-07-01",
      "end_date": "2026-07-31"
    },
    "opening_balance": 1000,
    "total_debit": 500000,
    "total_credit": 125000,
    "ending_balance": 376000
  }
}
```

### GET `/api/accounting/reports/general-ledger/details`

Description: returns paginated General Ledger detail for one account and one monthly period. This endpoint returns the `GeneralLedgerService` paginator directly, without the package success envelope or another pagination wrapper.

Service: `GeneralLedgerService::getLedgerDetails($accountId, $year, $month, $tenant = null, $page = 1, $perPage = 15)` returns this same paginator structure for internal callers. The optional `$tenant` argument is service-only: when supplied it temporarily resolves that Stancl tenant in preference to the current runtime tenant. It is not an API query parameter and is never read from `X-Tenant`.

Query parameters:

- `account_id` required uuid
- `year` required integer
- `month` required integer between 1 and 12
- `page` optional integer, default `1`
- `per_page` optional integer between `1` and `100`, default `15`

Example:

```text
GET /api/accounting/reports/general-ledger/details?account_id=uuid&year=2026&month=7&page=1&per_page=20
```

Response behavior:

- The response is a Laravel `LengthAwarePaginator` payload. Its standard fields include `current_page`, `data`, `per_page`, `total`, and page URLs.
- `account`, `period`, `opening_balance`, `total_debit`, `total_credit`, and `ending_balance` are ledger-wide values for the complete selected month, not just the current page.
- Each item in `data` contains `date`, `journal_number`, `reference_no`, `description`, `debit`, `credit`, and `ending_balance`.
- Running `ending_balance` is calculated from every posted movement in the selected period before pagination. It therefore continues correctly across page boundaries.
- The API returns the exact paginator returned by `GeneralLedgerService::getLedgerDetails()`; it does not add a `data` or `pagination` wrapper.

Example response:

```json
{
  "current_page": 1,
  "data": [
    {
      "date": "2026-07-10",
      "journal_number": "JV-2026-0001",
      "reference_no": "SALES-001",
      "description": "Penjualan Tunai",
      "debit": 500000,
      "credit": 0,
      "ending_balance": 501000
    }
  ],
  "per_page": 20,
  "total": 50,
  "account": {
    "id": "uuid",
    "code": "1001",
    "name": "Cash"
  },
  "period": {
    "year": 2026,
    "month": 7,
    "start_date": "2026-07-01",
    "end_date": "2026-07-31"
  },
  "opening_balance": 1000,
  "total_debit": 500000,
  "total_credit": 125000,
  "ending_balance": 376000
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

Optional `tenant_ids[]` returns each tenant's report separately, without consolidation. The list must be nonempty, contain valid tenants, and is deduplicated before execution. See [tenant financial reports](./tenant-financial-reports.md) for request/response examples and context restoration rules. Omitting it preserves the response below.

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

Optional `tenant_ids[]` returns each tenant's report separately, without consolidation. The list must be nonempty, contain valid tenants, and is deduplicated before execution. See [tenant financial reports](./tenant-financial-reports.md) for request/response examples and context restoration rules. Omitting it preserves the response below.

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
- With `accounting.reporting.read_source = central`, the profit-loss and balance-sheet endpoints answer `tenant_ids` requests from central reporting copies with unchanged payloads, and return a `reporting_sync` validation error when any selected tenant is not ready ([central reporting](./central-reporting.md)).
- Route model binding is not used for these endpoints; the controllers read `id` manually and resolve tenant context through the `tenantId` route segment or `X-Tenant` header when present.
