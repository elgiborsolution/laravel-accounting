# Extension Guide

This guide explains the extension points that are actually present in the package source.

## Add New Services

1. Add a new enum case in `AccountingServiceCode`.
2. Add the service definition to `ServiceCatalog`.
3. Seed the service in `DefaultAccountingServicesSeeder`.
4. Add mappings in `ServiceAccountTemplateRegistry`.
5. Seed mappings in `DefaultServiceAccountMappingsSeeder`.

## Add New Journal Templates

1. Add the template to `ServiceAccountTemplateRegistry`.
2. Ensure the referenced `account_code` exists in the default COA or in the application database.
3. Re-run the seeder.

## Add New Mappings

The package does not currently have a separate repository or action layer for mappings.

The supported extension mechanism is the seeder and registry pair:

- `ServiceAccountTemplateRegistry`
- `DefaultServiceAccountMappingsSeeder`

## Override Package Behavior

The package uses Laravel container bindings. You can replace these in your application service provider:

- `AccountingService`
- `JournalService`
- `CoaService`
- `MappingService`
- `ClosingService`
- `ReportService`
- `ServiceCatalog`
- `ServiceAccountTemplateRegistry`

## Register Custom Account Resolvers

### Planned Feature

There is no dedicated account-resolver contract or interface in the source tree.

For now, the practical extension options are:

- override `JournalService`
- extend `ServiceAccountTemplateRegistry`
- pre-process payloads before calling `journalByMapping()`

## Register Custom Posting Rules

### Planned Feature

There is no posting-rule engine, event dispatcher, or listener pipeline in the source tree.

Today, posting rules are enforced directly inside `JournalService`.

If you need custom rules, override the service binding and implement your own subclass or wrapper.

## Add Custom API Behavior

The package exposes routes through `routes/api.php`. If you need additional endpoints:

- add application routes alongside the package routes
- or extend the controller classes in your application layer

## Recommended Extension Order

```text
Add enum case
  ↓
Add service catalog entry
  ↓
Add mapping template
  ↓
Add default account code
  ↓
Seed and verify
```

