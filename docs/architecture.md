# Package Architecture

The package architecture uses a normalized chart of accounts design:

- hierarchy lives in `acc_account_categories`
- accounts in `acc_accounts` are leaf posting accounts only
- financial reports aggregate through the category tree

## Flow

```text
Application
  ->
Accounting Facade
  ->
Service Layer
  ->
Account Mapping Engine
  ->
Journal Engine
  ->
Fiscal Period Engine
  ->
Summary Engine
  ->
Category Tree Reporting Engine
```

## Core Data Model

### Category Tree

`acc_account_categories` is the only hierarchical structure in the design.

- `parent_id` references `acc_account_categories.id`
- `type` is constrained to `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, `EXPENSE`
- `category_name` is free and custom
- `sequence_no` controls sibling ordering

### Posting Accounts

`acc_accounts` stores posting accounts only.

- every account must reference `category_id`
- `parent_id` does not exist on accounts
- `level` does not exist on accounts
- journal entries and balances always point to posting accounts

## Relationship Diagram

```text
acc_account_categories (Tree)
        |
        |-- parent_id -> acc_account_categories.id
        |
        `-- 1 : N
             |
             v
        acc_accounts
             |
             v
   acc_journal_entry_details
             |
             v
      acc_monthly_balances
             |
             v
      Financial Reports
```

## Layer Responsibilities

### Application

The Laravel application installs the package, registers the service provider, runs migrations, and seeds the default accounting data.

### Accounting Facade

[`src/Facades/Accounting.php`](/c:/laragon/www/package-custom/laravel-accounting/src/Facades/Accounting.php) resolves the container alias `laravel-accounting`, which maps to `AccountingService`.

The facade exposes the package entry points:

- `journal()`
- `coa()`
- `mapping()`
- `closing()`
- `report()`
- `service()`
- `catalog()`

### Service Layer

`AccountingServiceProvider` binds the main service classes as singletons:

- `AccountingService`
- `JournalService`
- `CoaService`
- `MappingService`
- `ClosingService`
- `FiscalPeriodService`
- `ReportService`
- `ServiceCatalog`
- `ServiceAccountTemplateRegistry`

This is the main package extension point for overriding behavior through the Laravel container.

### Chart Of Accounts Layer

The chart of accounts is split into two responsibilities:

- `acc_account_categories` defines the report tree
- `acc_accounts` defines the posting leaves

This separation keeps the reporting hierarchy stable even when posting accounts change or grow over time.

### Account Mapping Engine

The mapping engine is centered on:

- `acc_services`
- `acc_service_accounts`

It reads mapping definitions, validates required mappings, resolves leaf posting accounts, supports dynamic accounts, and feeds journal creation.

### Journal Engine

`JournalService` is responsible for:

- mapping-based journal creation
- manual journal creation
- posting
- reversal journal creation
- fiscal period checks
- journal number generation

### Fiscal Period Engine

The fiscal period engine is represented by:

- `FiscalPeriod`
- `FiscalPeriodService`
- `JournalService::checkPeriodLocked()`
- `ClosingService`

It prevents posting into a closed monthly period and supports monthly close and reopen workflows.

### Summary Engine

The summary engine is the monthly closing layer:

- `ClosingService::closeMonth()`
- `ClosingService::closeThroughCurrentMonth()`
- `MonthlyBalance`

It aggregates posted journal activity into monthly balances at posting-account level.

### Reporting Engine

`ReportService` reads:

- `JournalEntryDetail`
- `MonthlyBalance`

It produces:

- general ledger
- trial balance
- profit loss
- balance sheet
- cash flow

Report layout is category-tree driven:

- posting balances are resolved from `acc_accounts.category_id`
- balances roll up recursively through `acc_account_categories.parent_id`
- report subtotals come from category nodes, not account-parent relationships

When shared master database mode is enabled, the category and account lookup still comes from the configured master connection, while the journal and balance tables continue to use the active application or tenant connection.

## Workflow Examples

### Cash Sale

```text
Business event
  ->
Select `SALES_CASH`
  ->
Load template from `acc_service_accounts` through the repository layer
  ->
Create balanced journal
  ->
Post journal
  ->
Monthly closing picks it up
  ->
Reports roll balances through the category tree
```

### Journal Reversal

```text
Posted journal with mistake
  ->
Call reversal API or `JournalService::reverse()`
  ->
Create reversal journal with swapped debit and credit
  ->
Keep both journals in the database
  ->
Create a new correcting journal if needed
```

### Month Close

```text
Posted journals for a month
  ->
Run `ClosingService::closeMonth()`
  ->
Compute monthly balances
  ->
Mark fiscal period closed
  ->
Block late journal posting or reversal in that period
```

### Close Through Current Month

```text
Open fiscal periods up to current month
  ->
Run `ClosingService::closeThroughCurrentMonth()`
  ->
Close each open period in order
  ->
Return list of closed periods
```

## Service Provider Wiring

The provider:

- merges `config/accounting.php`
- loads package routes
- loads package migrations in console
- binds the service singletons
- publishes config and migrations

This means the package behavior can be extended by rebinding the same classes in an application service provider.
