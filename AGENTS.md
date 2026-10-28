# Guidance for future enhancements

- Read `PROJECT_MAP.md`, then investigate the affected module's actual routes, controllers, services, repositories, models, configuration, migrations, and relevant tests before editing. Verify source behavior; existing prose may describe design intent or stale behavior.
- Preserve existing behavior and public contracts unless the requested enhancement explicitly changes them: namespaces, facade/container bindings, public signatures and aliases, routes, payloads, response envelopes/paginators, enum values, mapping keys, and source identifiers.
- Reuse established validation, repository, connection/table resolution, hook, response, caching, and error-handling patterns. Check the specific entry path; HTTP and direct PHP calls do not always share validation, hooks, or invalidation.
- Extend existing services for related features instead of introducing another service. Keep consolidated report features on `ConsolidatedReportService`.
- Investigate master versus transaction database ownership and tenant/account visibility for every data change. Preserve supported single-database and shared/separate-connection behavior; do not assume a tenant header or route authorizes access.
- Keep changes scoped to the requested enhancement. Avoid unrelated refactoring, cleanup, dependency changes, or fixes to limitations merely listed in the system map.
- Before changing accounting flows, trace fiscal locks, posting/reversal rules, opening balances, decimal handling, transactions, snapshots, and cache effects. Investigate affected compatibility behavior and run relevant existing checks when executable; report unavailable checks honestly.
- Update `PROJECT_MAP.md` when architecture, modules, dependencies, entry points, public contracts, database ownership, or tenant behavior changes. Distinguish verified facts from assumptions and cite actual package paths.

<!-- postman-cli:installation:start -->
Postman CLI is installed on this device, and can be used for API Engineering work.
<!-- postman-cli:installation:end -->
