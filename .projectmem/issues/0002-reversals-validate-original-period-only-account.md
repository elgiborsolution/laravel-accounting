# #0002 Reversals validate original period only; account-opening edits validate target date only, leaving affected-date lock gaps.

- 2026-11-04T02:36:55Z `issue`: Reversals validate original period only; account-opening edits validate target date only, leaving affected-date lock gaps. [src/Services/JournalService.php:343]
- 2026-11-04T02:42:25Z `attempt`: Reversals now validate the new posting date; opening edits validate old and new dates through FiscalPeriodService. Posting holds a transaction across cutoff checks and writes. [src/Services/JournalService.php] (partial)
- 2026-11-04T02:53:59Z `attempt`: Shared FiscalPeriodService checks cover original/target opening edits and actual reversal dates; cutoff and legacy regressions pass in both master modes (full suite 317 tests, 2100 assertions). [src/Services/JournalService.php:343] (worked)
- 2026-11-04T02:54:04Z `fix`: Validated actual mutation dates for reversal and opening edits with rollback protection. ClosingCutoffTest passes 22 cases; full suite 317/2100 passes. [src/Services/AccountOpeningBalanceService.php:273]
