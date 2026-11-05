# #0011 Add following-month closing_day eligibility without changing calendar period selection, snapshots or cutoff ownership.

- 2026-11-04T04:34:11Z `issue`: Add following-month closing_day eligibility without changing calendar period selection, snapshots or cutoff ownership. [src/Services/ClosingService.php]
- 2026-11-04T04:38:03Z `attempt`: Added optional following-month eligibility with clamped days, due-only bulk selection and shared pending-month preflight. Calendar movement/snapshot logic unchanged; 46 cutoff tests/382 assertions pass. [src/Services/ClosingService.php] (worked)
- 2026-11-04T04:39:29Z `fix`: Following-month closing_day implemented on existing closeMonth flow with due-only batches, shared preflight and docs. Focused 47 tests/395 assertions; full suite 342 tests/2357 assertions, lint and test style pass. [src/Services/ClosingService.php:55]
