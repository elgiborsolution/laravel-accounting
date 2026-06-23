# Package Architecture

The package is organized around a small number of source-backed layers.

## Flow

```text
Application
  ↓
Accounting Facade
  ↓
Service Layer
  ↓
Account Mapping Engine
  ↓
Journal Engine
  ↓
Fiscal Period Engine
  ↓
Summary Engine
  ↓
Reporting Engine
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

### Account Mapping Engine

The mapping engine is centered on:

- `acc_services`
- `acc_service_accounts`

It reads mapping definitions, validates required mappings, supports dynamic accounts, and feeds journal creation.

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

It prevents posting into a closed monthly period and supports monthly close/reopen workflows.

### Summary Engine

The summary engine is the monthly closing layer:

- `ClosingService::closeMonth()`
- `ClosingService::closeThroughCurrentMonth()`
- `MonthlyBalance`

It aggregates posted journal activity into monthly balances.

### Reporting Engine

`ReportService` reads:

- `JournalEntryDetail`
- `MonthlyBalance`
- `ReportMapping`

It produces:

- general ledger
- trial balance
- profit & loss
- balance sheet
- cash flow

## Workflow Examples

### Cash Sale

```text
Business event
  ↓
Select `SALES_CASH`
  ↓
Load template from `acc_service_accounts`
  ↓
Create balanced journal
  ↓
Post journal
  ↓
Monthly closing picks it up
  ↓
Reports read the posted data
```

### Journal Reversal

```text
Posted journal with mistake
  ↓
Call reversal API or `JournalService::reverse()`
  ↓
Create reversal journal with swapped debit/credit
  ↓
Keep both journals in the database
  ↓
Create a new correcting journal if needed
```

### Month Close

```text
Posted journals for a month
  ↓
Run `ClosingService::closeMonth()`
  ↓
Compute monthly balances
  ↓
Mark fiscal period closed
  ↓
Block late journal posting/reversal in that period
```

### Close Through Current Month

```text
Open fiscal periods up to current month
  ↓
Run `ClosingService::closeThroughCurrentMonth()`
  ↓
Close each open period in order
  ↓
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

