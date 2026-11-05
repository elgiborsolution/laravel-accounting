# #0001 Investigate date cutoff closing across journal flows, monthly snapshots, fiscal locks and reopen behavior.

- 2026-11-04T02:36:21Z `issue`: Investigate date cutoff closing across journal flows, monthly snapshots, fiscal locks and reopen behavior. [src/Services/ClosingService.php]
- 2026-11-04T02:42:18Z `attempt`: Added per-transaction-DB cutoff state/audit migration, opt-in config, close/advance/reopen methods and shared fiscal date validation; tests pending. [src/Services/ClosingService.php] (partial)
- 2026-11-04T02:54:09Z `attempt`: Implemented opt-in per-database cutoff locks, ordered audit, close/advance/reopen APIs and config defaults. 22 focused cases and full suite pass (317 tests/2100 assertions). [src/Services/ClosingService.php] (worked)
- 2026-11-04T02:54:15Z `fix`: Cutoff closing is implemented without partial snapshots or controller changes; configuration/services documented. Full PHP 8.4.20 suite passes: 317 tests, 2100 assertions. [tests/Feature/ClosingCutoffTest.php]
