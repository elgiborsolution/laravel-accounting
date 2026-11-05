# #0004 Manual journal wrapTransaction=false can release the cutoff read lock before writing when no outer transaction exists.

- 2026-11-04T02:47:43Z `issue`: Manual journal wrapTransaction=false can release the cutoff read lock before writing when no outer transaction exists. [src/Services/JournalService.php:156]
- 2026-11-04T02:48:10Z `attempt`: Enabled cutoff mode forces a transaction for unwrapped manual calls without an outer transaction; transaction-level test covers manual, mapped, posting and cutoff updates. [src/Services/JournalService.php:162] (worked)
- 2026-11-04T02:48:15Z `fix`: Cutoff reads remain inside write transactions; ClosingCutoffTest passes 21 tests/132 assertions. Disabled mode retains prior posting transaction behavior. [tests/Feature/ClosingCutoffTest.php]
