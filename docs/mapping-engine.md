# Account Mapping Engine

The account mapping engine is centered on `acc_services` and `acc_service_accounts`.

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

Resolved from `account_code` during seeding for default mappings.

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

- loads the active service
- loads the service mappings
- validates each provided mapping key
- resolves dynamic account IDs when provided
- enforces required mappings
- creates a balanced journal

## Default Mapping Flow

```text
Seeder
  ↓
Resolve account_code to account_id
  ↓
Update or create mapping row
  ↓
Service loads mappings at runtime
  ↓
Journal service validates and posts journal
```

## Planned Feature

There is no separate repository layer or dedicated resolver interface in the current source tree.

If you need custom account resolution rules, the current extension path is to:

- replace the `ServiceAccountTemplateRegistry`
- replace the seeder
- override the `JournalService` binding in your application

