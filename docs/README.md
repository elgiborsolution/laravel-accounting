# Laravel Accounting Package Documentation

This documentation is the main technical reference for the package. It now uses the normalized category-tree accounting design as the documentation baseline, with `acc_account_categories` owning the hierarchy and `acc_accounts` limited to leaf posting accounts.

## Start Here

- [Technical Design](./technical-design.md)
- [Package Architecture](./architecture.md)
- [Public API Reference](./reference.md)
- [Available Services](./services.md)
- [Journal Engine](./journal-engine.md)
- [Account Mapping Engine](./mapping-engine.md)
- [Extension Guide](./extension-guide.md)

## What Exists In Source

- Facade: `Accounting`
- Service classes: `AccountingService`, `JournalService`, `CoaService`, `MappingService`, `ClosingService`, `ReportService`
- Support registries: `ServiceCatalog`, `ServiceAccountTemplateRegistry`
- Models: `AccountCategory`, `Account`, `Service`, `ServiceAccount`, `JournalEntry`, `JournalEntryDetail`, `FiscalPeriod`, `MonthlyBalance`, `ReportMapping`
- Enums: `AccountingServiceCode`, `JournalStatus`, `NormalBalance`, `ReportType`
- Traits: `ApiResponse`, `HasUuid`
- Exception: `AccountingPeriodLockedException`
- Controllers: account category, account, service, journal, report
- Seeders: default COA, default services, default service-account mappings

## Documentation Baseline

- Account hierarchy exists only in `acc_account_categories`.
- Root category types are `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, and `EXPENSE`.
- `category_name` is fully custom.
- `acc_accounts` contains posting accounts only and always requires `category_id`.
- General Ledger, Trial Balance, Profit Loss, Balance Sheet, and Cash Flow are documented as category-tree driven reports.

## Not Implemented In Source

The package does not currently include:

- Contracts / interfaces
- Repositories
- Action classes
- DTO classes
- Events
- Listeners
- Global helper functions

These are documented as planned extension areas where relevant, not as implemented features.

