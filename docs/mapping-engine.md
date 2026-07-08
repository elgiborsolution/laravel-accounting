# Account Mapping Engine

The account mapping engine is centered on `acc_services` and `acc_service_accounts`.

Mappings always resolve to leaf posting accounts in `acc_accounts`. They do not define report hierarchy; report structure comes from `acc_account_categories`.

## `acc_services`

Purpose:

- registry of business transaction services
- source of truth for service codes
- entry point for journal mapping lookup

Key fields:

- `service_code`
- `service_name`
- `module_name`
- `description`
- `is_active`

## `acc_service_accounts`

Purpose:

- journal template registry
- account mapping registry
- dynamic account resolver
- validation layer for service templates

Key fields:

- `service_id`
- `mapping_key`
- `mapping_name`
- `position`
- `account_id`
- `sequence_no`
- `is_dynamic`
- `is_required`
- `status`

## Important Mapping Fields

### `mapping_key`

Stable key used by `JournalService::journalByMapping()`.

### `mapping_name`

Human-readable name shown in service setup and API responses.

### `account_id`

Resolved from `account_code` during seeding for default mappings and must point to a posting account.

### `sequence_no`

Controls mapping order for UI and processing.

### `is_required`

Marks a mapping as mandatory for posting.

### `is_dynamic`

Signals that the account can be supplied at runtime by the caller.

## Current Runtime Behavior

### Lookup

`MappingService` exposes:

- `findByKey(string $key)`
- `getByService($serviceId)`

Both return only active mappings.

### Journal creation

`JournalService::journalByMapping()`:

- resolves the active service through the service repository
- loads the service mappings through the service-account repository
- validates each provided mapping key
- resolves dynamic account IDs when provided
- enforces required mappings
- creates a balanced journal

This lookup flow is connection-aware, so master data can live in a shared database while journal entries stay on the active application or tenant connection.

## Default Mapping Flow

```text
Seeder
  ->
Resolve account_code to account_id
  ->
Update or create mapping row
  ->
Service loads mappings from the configured master or default connection at runtime
  ->
Journal service validates and posts journal
  ->
Balances roll up through the category tree at report time
```

## Planned Feature

The package now includes repository-backed lookup for services, accounts, categories, mappings, and journals.

There is still no dedicated resolver interface in the current source tree.

If you need custom account resolution rules, the current extension path is to:

- replace the `ServiceAccountTemplateRegistry`
- replace the seeder
- override the `JournalService` binding in your application
