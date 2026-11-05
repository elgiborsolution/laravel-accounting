# #0009 Opening balance transaction setup occurs before try, so a later beginTransaction failure can leak earlier transactions.

- 2026-11-04T03:27:26Z `issue`: Opening balance transaction setup occurs before try, so a later beginTransaction failure can leak earlier transactions. [src/Services/AccountOpeningBalanceService.php:354]
- 2026-11-04T03:27:38Z `attempt`: Moved connection transaction setup inside try and track only acquired levels. Fault-injection test confirms central outer lock survives and tenant levels roll back; 35 cutoff tests pass. [src/Services/AccountOpeningBalanceService.php:354] (worked)
- 2026-11-04T03:28:25Z `fix`: Transaction initialization and rollback now preserve caller-owned levels; injected tenant begin failure leaves the enclosing central lock intact. 35 cutoff cases pass. [src/Services/AccountOpeningBalanceService.php:350]
