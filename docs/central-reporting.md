# Central reporting

Central reporting answers tenant-list balance-sheet and profit/loss reports from one central database instead of switching into every tenant database. Journals, posting, reversals, fiscal periods and book closing stay tenant-local and keep their existing rules. A tenant-side outbox records what changed. Scheduled commands copy those changes into central tables on `accounting.master_data.connection`.

The feature is opt-in. With the defaults (`change_log_enabled = false`, `read_source = live`) nothing is recorded and every report behaves as before.

## Supported configuration

Central reporting supports exactly one layout:

| Data | Location |
|---|---|
| Accounts, categories, services | Shared master tables on `accounting.master_data.connection` (`ACCOUNTING_USE_SHARED_DATABASE=true`) |
| Journals, details, fiscal periods, snapshots, local cutoff, outbox | Each tenant's own transaction database |
| Central reporting tables | `accounting.master_data.connection`, next to the master tables |

`CentralReportingRepository::assertSupported()` checks four things:
- `accounting.master_data.use_shared_database` is true;
- `accounting.master_data.connection` names a configured connection;
- `Account`, `AccountCategory` and `Service` resolve to that connection, so no explicit model connection points elsewhere;
- the `acc_account_categories`, `acc_accounts` and `acc_services` tables exist on it.

The central reporting migration creates its tables only when this check passes. Otherwise it is a no-op, so installs that don't use central reporting migrate normally. Every sync operation, command and central report read runs the check and also confirms the central tables exist. An unsupported or uninstalled configuration is rejected with a `RuntimeException` that names the problem; commands print it and exit 1. Sync also requires the master connection to differ from each tenant's transaction connection, so single-database installs are rejected.

Tenant-local master installs (`use_shared_database = false`) are not supported. All their existing live behaviour is unchanged. If `change_log_enabled` is set there, it is ignored: nothing is recorded, and accounting writes are not affected.

## How it works

```
tenant DB (per tenant)                                 master connection (central)
┌───────────────────────────────┐   accounting:reporting-sync   ┌───────────────────────────────┐
│ journals, details, periods,   │  1. read unacknowledged rows   │ acc_accounts / categories /   │
│ monthly snapshots, cutoff     │  2. re-read items (one read tx)│ services (existing master)    │
│                               │  3. replace them centrally     │                               │
│ acc_reporting_change_log  ────┼──4. commit central ───────────►│ acc_reporting_sources (state) │
│ (written in the same tx as    │  5. acknowledge those rows     │ acc_reporting_journal_entries │
│  the journal/closing change)  │◄─────────────────────────────  │ acc_reporting_journal_entry_  │
└───────────────────────────────┘                                │   details / fiscal_periods /  │
                                                                 │   monthly_balances            │
                                                                 └───────────────────────────────┘
```

- **Outbox rows identify items, not data.** Each row names a journal (`journal:<uuid>`), a fiscal month (`month:YYYY-MM`) or the local cutoff (`cutoff:1`). The sync re-reads the item's current state (state transfer). Replaying, reordering and duplicate rows are therefore harmless. A journal or period that no longer exists is deleted centrally.
- **Atomic with the source write.** Package write paths record their row inside the transaction that makes the change. A rollback removes both.
- **Acknowledge after commit.** A batch is acknowledged in the tenant database only after its central transaction commits, and only the rows that batch read. Rows that commit later, including rows with lower auto-increment ids from slower transactions, stay pending. If an acknowledgement fails, the next run re-applies the same state.
- **Exclusive, fenced per-tenant leases.** One worker per tenant holds a lease in `acc_reporting_sources`. Every central batch re-checks and extends it inside its own transaction, so a worker whose lease expired or was taken cannot commit. An expired lease (`lock_seconds`) can be taken over.
- **Consistent reads.** Each batch re-reads its items in one tenant read transaction, using REPEATABLE READ on MySQL/MariaDB/PostgreSQL. Each central report reads all its data in one central transaction: the copies and the master tables together.

## What is stored centrally

| Data | Central table | Notes |
|---|---|---|
| Sync state | `reporting_sources` | Backfill/sync/reconcile status, freshness, failures and backoff, lease, and the tenant's local cutoff metadata |
| Journal headers and details | `reporting_journal_entries`, `reporting_journal_entry_details` | All columns and statuses (drafts too); reports filter `posted` exactly as live queries do |
| Fiscal-period status | `reporting_fiscal_periods` | Open, closed and reopened, with `closed_at` (snapshot status rules need it) |
| Closing snapshots | `reporting_monthly_balances` | Copied **exactly**, never recomputed; a month is always replaced as a whole set |

Each table is keyed by `(tenant_id, source id)`, so tenants never collide. Every copied row keeps its exact source attributes in a JSON `payload` column; typed columns exist for filtering. Central reports rebuild the same models from the payloads, so tree building, consolidation, stable-code checks and calculators are the existing code.

**Master data is not copied.** Accounts, categories and services are read from their existing master tables by the same repositories that live reports use. Copied journal details and snapshots keep their `account_id` / `service_id` values, which are already the central master ids. Master edits made through the API, seeders or SQL are therefore visible to central reports at once, with no sync. They also show up in live reports at the same moment, because both read the same rows.

### Snapshots, reclosing and reopening

- **Closing** records `month:YYYY-MM closed`. The sync replaces that month's period and its full snapshot set with what the tenant holds, including rows that `closeMonth()` itself would not delete.
- **Reopening** records `month:YYYY-MM reopened`. Snapshots are retained, exactly as in the tenant, so balance-sheet summaries report the month as `stale` until it is reclosed.
- **Tenants close months independently.** Statuses (`current` / `stale` / `missing`) are computed per tenant from that tenant's copied periods, snapshots and earliest posted journal, with the same rules as the live path.

## Setup and rollout

1. **Shared master data:** `ACCOUNTING_USE_SHARED_DATABASE=true` and `ACCOUNTING_MASTER_CONNECTION=<central connection>`, with the master migrations already run on that connection.
2. **Tenant tables:** run the package migrations in every tenant (transaction) database, as for any accounting install. This adds `acc_reporting_change_log` (`2026_11_06_000018`). Tenant runs skip the central migration.
3. **Central tables:** run the package migrations against the master connection itself. `2026_11_06_000019_create_acc_reporting_central_tables` is in the same `database/migrations/accounting` folder, which is auto-loaded and published under `accounting-migrations`. It creates its tables only on a run whose connection is the master connection:
   ```bash
   php artisan migrate --database=<master connection>   # or plain `migrate` when the master connection is the default
   ```
   When the configuration is unsupported, the migration creates nothing and the run continues. If it was recorded during such a run and the install later becomes supported, roll that one migration back and run it again on the master connection. Until then, the sync commands and central reads report that the central tables are missing.
4. **Enable recording** in every process that writes accounting data: `ACCOUNTING_REPORTING_CHANGE_LOG_ENABLED=true`. Backfill and sync refuse to run while it is disabled.
5. **Backfill** each tenant. This registers it, copies everything, drains the outbox and reconciles:
   ```bash
   php artisan accounting:reporting-backfill --tenant=tenant-a --tenant=tenant-b
   ```
6. **Register the schedule** in the host (the package registers no schedule):
   ```php
   // routes/console.php (Laravel 11+) or the console kernel
   Schedule::command('accounting:reporting-sync')->everyMinute()->withoutOverlapping();
   Schedule::command('accounting:reporting-reconcile')->dailyAt('02:00')->withoutOverlapping();
   Schedule::command('accounting:reporting-prune')->daily();
   ```
7. **Check readiness:** `php artisan accounting:reporting-status`.
8. **Switch reads:** `ACCOUNTING_REPORTING_READ_SOURCE=central`.

### Migration ownership

Every package migration lives in `database/migrations/accounting`, so the same files run during central and tenant migrations. Migrations that own master-connection tables (`2026_11_04_000017` shared cutoffs and `2026_11_06_000019` central reporting) use `HandlesCentralMigration`:
- **Creating:** they create tables only when the run's connection is the master connection. The migrator makes `--database` the default, and tenancy bootstrappers switch the default per tenant. Tenant runs therefore never create these tables, in either database.
- **Re-running:** existing tables are never recreated, and the shared-cutoff seed row is inserted only once.
- **Rolling back:** only a rollback on the master connection drops them. Tenant rollbacks leave them and their data alone.
- **No master connection:** without `ACCOUNTING_MASTER_CONNECTION`, both are no-ops.

The tenant outbox (`000018`) and the other tenant tables keep targeting the transaction connection.

## Configuration (`accounting.reporting`)

| Key | Default | Meaning |
|---|---|---|
| `read_source` | `live` | `live` or `central` for tenant-list reports; any other value throws |
| `change_log_enabled` | `false` | Record outbox rows (requires the tenant table and shared master data) |
| `max_lag_seconds` | `900` | Freshness limit; `null` disables the limit |
| `batch_size` | `500` | Outbox rows per central batch |
| `lock_seconds` | `600` | Lease length; extended on every batch |
| `retry_base_seconds` / `retry_max_seconds` | `60` / `3600` | Exponential backoff after failures |
| `prune_after_days` | `7` | Default age for pruning acknowledged rows |

## Commands

| Command | Purpose |
|---|---|
| `accounting:reporting-backfill --tenant=* [--force]` | Register and copy tenants; `--force` recopies a completed tenant |
| `accounting:reporting-sync [--tenant=*] [--max-batches=] [--ignore-backoff]` | Apply pending outbox rows. Tenants in backoff report `deferred`; tenants not backfilled report `not_backfilled` |
| `accounting:reporting-reconcile [--tenant=*] [--detect-only]` | Compare per-month digests and repair (or only flag) differences |
| `accounting:reporting-prune [--tenant=*] [--days=]` | Delete acknowledged outbox rows older than N days; pending rows are never deleted |
| `accounting:reporting-status [--tenant=*]` | Readiness, freshness and the last error per tenant |

Every command exits 1 without doing any work when the configuration is unsupported or the central tables are missing. Commands also exit non-zero when any tenant fails. Reconcile exits non-zero when drift remains, and status exits non-zero when any tenant is not ready. The same operations are available on `ESolution\LaravelAccounting\Services\ReportingSyncService`: `backfill()`, `sync()`, `reconcile()`, `prune()` and `status()`; each throws `RuntimeException` for an unsupported configuration.

## Freshness and rejection

`synced_through` is the start time of the last run that drained a tenant's outbox completely. Every change committed before it is in the central copy. A run limited by `--max-batches` does not advance it.

In central mode, a request whose configuration is unsupported, or whose central tables are missing, throws `RuntimeException` before anything is read. Otherwise it is rejected with a `ValidationException` under `reporting_sync` when **any** selected tenant:
- is not registered, or its backfill is not complete;
- failed its last sync (`sync failed: ...`);
- has unrepaired drift (`drift detected`);
- is older than `max_lag_seconds`.

The message names every offending tenant. Partial totals are never returned.

Central mode covers every tenant-list entry point of:
- `ReportService::profitLoss`, `balanceSheet`, `profitLossRange` and `profitLossDetail`;
- `ConsolidatedReportService` profit/loss, balance-sheet, summary, range, by-tenant, by-month and profit-detail methods;
- `ProfitLossSummaryService::summarize` and `summarizeRange`;
- the `tenant_ids` HTTP report endpoints.

Signatures, tenant validation and aliases, payloads, calculations and stable-code checks are unchanged. Central reports do not initialize tenancy or open tenant connections.

**Not covered** (these stay live in both modes):
- calls without a tenant list, which use the active connection;
- `ConsolidatedReportService::categoryTree()` and `AccountCategoryTreeService::index()` with tenant lists, which use the live balance engine;
- general-ledger reports, trial balance and cash flow;
- all closing operations.

## Mutation paths

**Recorded atomically by package services:**
- `JournalService`: manual, opening, mapped and reversal journals (one row per created journal, plus the reversed original) and posting;
- `AccountOpeningBalanceService` journal edits;
- `ClosingService` month close and reopen;
- per-tenant cutoff close, advance and reopen (shared closing reaches these through `closeMonth()`).

**Recorded by model listeners:**
- Eloquent creates, updates and deletes of `JournalEntry` and `JournalEntryDetail`;
- creation and deletion of `FiscalPeriod` rows (periods are created implicitly when a journal lands in a new month).

Deletions record before and after the delete, so a sync between the two still sees a pending row. Outside a caller transaction, such host Eloquent writes are not atomic with their log row; reconciliation covers that window.

**Master data needs no recording.** It is read live from the master tables. Shared master rows live on another connection, so deleting one never cascades into tenant journals or snapshots. Any dangling ids look the same in live and central reports.

**Not recorded (unsupported):**
- query-builder or raw SQL writes to journal, detail, period, snapshot or cutoff tables;
- foreign-key cascades that bypass Eloquent;
- `MonthlyBalance` and `FiscalPeriod` updates outside `ClosingService`;
- data restored from backups.

Reconciliation detects and repairs the effects of all of these. It compares order-independent SHA-256 digests of every source row against the central payloads, month by month: journals plus details by `trx_date` month, and periods plus snapshots by fiscal month. It then re-copies mismatched months (including moved or deleted journals), re-verifies, and leaves the tenant `drifted` only if differences remain after three attempts. `--detect-only` flags drift (rejecting central reports) without repairing it.

## Limits

- Reconciliation reads every journal, detail and snapshot row of a tenant, on both sides. Schedule it off-peak for large tenants.
- Read isolation is explicit only for MySQL/MariaDB/PostgreSQL. SQLite transactions are serializable; SQL Server uses its default isolation unless the host enables snapshot isolation.
- Tests use SQLite and a host-tenancy double; deployed tenancy bootstrappers and other drivers are not exercised.
