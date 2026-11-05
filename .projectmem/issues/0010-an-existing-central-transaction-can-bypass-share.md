# #0010 An existing central transaction can bypass shared lock-order guard even when it has not acquired the cutoff lock.

- 2026-11-04T03:28:44Z `issue`: An existing central transaction can bypass shared lock-order guard even when it has not acquired the cutoff lock. [src/Services/FiscalPeriodService.php:108]
- 2026-11-04T03:29:04Z `attempt`: Track actual shared-lock callback depth in the existing singleton and reset it in finally. Tests reject unrelated central/tenant outer transactions and accept correctly wrapped ones; 35 tests/251 assertions pass. [src/Services/FiscalPeriodService.php:102] (worked)
- 2026-11-04T03:29:47Z `fix`: Shared lock ownership is tracked and cleared in finally; outer-transaction tests pass. Full suite passes 330 tests/2213 assertions. [src/Services/FiscalPeriodService.php:104]
