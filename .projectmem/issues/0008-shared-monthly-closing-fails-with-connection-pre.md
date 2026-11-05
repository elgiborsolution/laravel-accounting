# #0008 Shared monthly closing fails with connection prefixes: closeMonth double-prefixes aggregate table names.

- 2026-11-04T03:25:08Z `issue`: Shared monthly closing fails with connection prefixes: closeMonth double-prefixes aggregate table names. [src/Services/ClosingService.php:84]
- 2026-11-04T03:26:15Z `attempt`: Pass unprefixed resolved table names into grammar wrapTable so it adds the connection prefix once. ClosingCutoffTest passes 34 tests/243 assertions. [src/Services/ClosingService.php:84] (worked)
- 2026-11-04T03:26:55Z `fix`: Monthly aggregate table prefixes are applied once; shared prefix regression and all 34 ClosingCutoffTest cases pass. [src/Services/ClosingService.php:84]
