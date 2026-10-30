# C-TUI-5.a FIX-1 (round 1) — commit cf9f9e634 on cc/C-TUI-5.a

FIX pass for REVIEW-1.md. 2/2 findings landed, lane stays surgical (comment-only + one import move).

## MINOR-1 — stale docblock cites orphaned by the DoctorChecks extraction (4 sites, comment-only, zero behavior)
- src/Cli/Bootstrap.php:10129 — `{@see ...Subcommands::doctorProbes()}'s `session store` check` → `{@see ...DoctorChecks::probes()}` (the row is defined in probes(); its closure calls Bootstrap::sessionStore(prune:false) — verified).
- src/Cli/Bootstrap.php:10222 — caller-roster bullet "— passes `prune: false`, so it never reaches this method at all" → `DoctorChecks::probes()`.
- src/Support/SqliteConnect.php:30 — raw-`new \PDO` sentence → `Cli\DoctorChecks::pdoDriverProbe()` (method exists there, private, :113).
- bin/candy-code:530 — "every probe in Subcommands::doctorProbes() catches its own throws" → "DoctorChecks::run() wraps every probe in its own try and catches its throws" (truth per DoctorChecks::run():56-71 foreach+try/catch; catch body is the one REVIEW-1 verified identical).

Landed-blob proof (git show cf9f9e634): grep -c doctorProbes = 0 in Bootstrap.php, SqliteConnect.php, bin/candy-code; :10129/:10222 name DoctorChecks::probes() in the blob. Tree-wide src+bin+docs+README+lang: sole remaining mention is tests/Cli/DoctorChecksTest.php:16 "used to be true of `Subcommands::doctorProbes()`" — deliberate historical prose, not an orphan, left as-is.

## NIT-1 — import ordering
tests/Integration/BinCandyCodeDispatchTest.php: DoctorChecks `use` moved between Bootstrap and Help (blob-proven alphabetical). Note: file lives at tests/Integration/, not tests/Host/ as the fix brief said — disk truth, reviewer path correct.

## Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- php -l: all 4 touched files clean.
- --filter 'Doctor': 70T/1716A OK (2 PHPUnit deprecations = environmental, same as REVIEW-1's green run).
- SymbolCitationDriftTest: 9T/18448A OK — {@see} re-points keep the guard green.

## DISCLOSURE — pre-existing base-red surfaced by full-file run (NOT lane's, NOT fixed)
testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions FAILS: "--worktree is accepted by the parser but offered by no completion" (:1890). Proven inherited: reproduces at clean tip 31ebd16fa with FIX-1 changes stashed; the test method and --worktree both exist at base 740c29868; neither lane commit touched the method or any completion source (b129f1754 touched this file only for the doctor re-points). C-AGT-6.c-era gap — flag for lead/welder, outside this lane's scope.
