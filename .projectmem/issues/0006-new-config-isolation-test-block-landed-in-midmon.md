# #0006 New config-isolation test block landed in midmonth boundary test and expected an unadvanced cutoff date.

- 2026-11-04T02:50:10Z `issue`: New config-isolation test block landed in midmonth boundary test and expected an unadvanced cutoff date. [tests/Feature/ClosingCutoffTest.php:59]
- 2026-11-04T02:50:30Z `attempt`: Moved config-isolation assertions into the configured/explicit cutoff selection test; 22 cutoff tests pass. [tests/Feature/ClosingCutoffTest.php] (worked)
- 2026-11-04T02:50:35Z `fix`: Corrected new test placement; config default changes do not move saved tenant cutoffs or affect other databases. [tests/Feature/ClosingCutoffTest.php]
