# projectmem - laravel-accounting

_Last updated: 2026-11-11_

## Project purpose
`elgibor-solution/laravel-accounting` is a Laravel 11/12 accounting package that provides category-tree based chart-of-accounts management, journal entry storage, service-to-account mappings, monthly closing, and financial reporting.

## Recent issues
- [DONE] #0011 Add following-month closing_day eligibility without changing calendar period selection, snapshots or cutoff ownership. [src/Services/ClosingService.php] (fixed)
- [DONE] #0010 An existing central transaction can bypass shared lock-order guard even when it has not acquired the cutoff lock. [src/Services/FiscalPeriodService.php:108] (fixed)
- [DONE] #0009 Opening balance transaction setup occurs before try, so a later beginTransaction failure can leak earlier transactions. [src/Services/AccountOpeningBalanceService.php:354] (fixed)
- [DONE] #0008 Shared monthly closing fails with connection prefixes: closeMonth double-prefixes aggregate table names. [src/Services/ClosingService.php:84] (fixed)
- [DONE] #0007 Add independent/shared cutoff modes with central audited coordination, retry-safe tenant closing and shared journal enforcement. [src/Services/ClosingService.php] (fixed)
- [DONE] #0006 New config-isolation test block landed in midmonth boundary test and expected an unadvanced cutoff date. [tests/Feature/ClosingCutoffTest.php:59] (fixed)
- [DONE] #0005 Final diff review found closing configuration absent from config/accounting.php despite cutoff service implementation. [config/accounting.php] (fixed)
- [DONE] #0004 Manual journal wrapTransaction=false can release the cutoff read lock before writing when no outer transaction exists. [src/Services/JournalService.php:156] (fixed)
- [DONE] #0003 Cutoff reopen regression test expects 'subsequent periods' but existing ClosingService message says 'subsequent months'. [tests/Feature/ClosingCutoffTest.php:188] (fixed)
- [DONE] #0002 Reversals validate original period only; account-opening edits validate target date only, leaving affected-date lock gaps. [src/Services/JournalService.php:343] (fixed)
- ...and 1 older fixed issue not shown (`pjm search`, or `get_issue(issue_id)` via MCP)

## Decisions
- Cutoffs are opt-in transaction-DB date locks with singleton state and append-only audit. No partial snapshots. FiscalPeriodService checks actual mutation dates; reopening respects closed months. [src/Services/ClosingService.php]
- Shared cutoff uses a dedicated central connection and fixed canonical tenant scope. Hold central lock through local commits; publish only after every tenant succeeds. Retry revalidates and skips closed full months; partial months never snapshot. [src/Services/ClosingService.php]
- closing_day is optional next-month eligibility, not an accounting boundary. Clamp short months; allow late runs; keep calendar snapshots/cutoffs separate. Bulk calls select due months; shared preflight validates pending months before local commits. [src/Services/ClosingService.php]
- Refactor: Refactor code structure and remove redundant code blocks for improved readability and maintainability [config/accounting.php]

## Notes
- feat: Implement category tree retrieval and consolidation logic in ConsolidatedReportService
- feat: add profit/loss range and summary calculations
- feat: add getAccountBreakdown method for tenant-specific account activity analysis and implement related tests
- feat: add tenant-specific profit/loss summary methods and related tests for financial reporting
- gotcha: pwsh is unavailable in this Windows environment; context-mode shell already runs PowerShell, so use native commands directly.
- New feature: Add comprehensive tests for BalanceSheetSummaryServices [config/accounting.php]
- New feature: Add CentralReportingFixture and ReportingTenant for testing central reporting functionality [config/accounting.php]
- New feature: Add AccountingErrorReporter for improved error handling and logging [config/accounting.php]
- New feature: feat: Implement category balance tree functionality with snapshot status handling [src/Services/ConsolidatedReportService.php]
- New feature: feat(migration): add operation columns to acc_closing_runs table for global reopening tracking [database/migrations/accounting/2026_11_11_000021_add_operation_to_acc_closing_runs_table.php]

## Key files
- `database/factories/AccountFactory.php`
- `database/migrations/accounting/2026_01_01_000002_create_acc_accounts_table.php`
- `database/migrations/accounting/2026_07_15_000014_add_tenant_id_to_acc_accounts_table.php`
- `docs/README.md`
- `docs/reference.md`
- `docs/technical-design.md`
- `postman_collection.json`
- `src/Http/Controllers/Api/AccountCategoryController.php`
- `src/Http/Controllers/Api/AccountController.php`
- `src/Http/Controllers/BaseController.php`
- `src/Models/Account.php`
- `src/Repositories/AccountRepository.php`
- `tests/Feature/AccountOwnershipEnhancementTest.php`
- `tests/Feature/AccountingConnectionModesTest.php`
- `README.md`
- `src/Http/Resources/AccountCategoryResource.php`
- `src/Repositories/AccountCategoryRepository.php`
- `tests/Feature/AccountCategoryTreeTest.php`
- `database/seeders/data/id/accounts.php`
- `src/AccountingServiceProvider.php`

## Open questions
- None logged yet.
