# Laravel Accounting Package Documentation

This documentation is generated from the package source code and intended to be the single technical reference for the package.

## Start Here

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

