# C-TUI-6.e — Windows self-update relaunch swap — LANDED

STATUS=COMPLETE (this line current as of final commit below)

## Commit
- Pick: 4f5336d949d92c5f28866dc4e6dc73f881d7c225 on cc/C-TUI-6.e, base b539be8300a6a8a5c81f97a060e46775f0dae1cb
- Author/committer: %ae grep -c detain = 1, %ce grep -c detain = 1, Co-Authored-By count = 0 (all separate counts this session)
- Master at commit time read b539be830 (weld: C-TOOL-6.l run-state fold) — lane is direct descendant, zero picks touched lane files; lane never rebased.

## Files (6, +659/-8)
- candy-code/src/Cli/WindowsSwap.php NEW — HELPER fixed-literal nowdoc, launchCommand() pure argv, launch() spawnSpec + NUL-device-by-DIRECTORY_SEPARATOR + create_process_group + dropped handle; RETRY_INTERVAL_US=200_000/RETRY_BUDGET_SECONDS=60.0 (design-declared, only ceiling, E646-clean).
- candy-code/src/Cli/SelfUpdate.php — Windows legs in run() swap stage (:620-region, after chmod pair) and rollback(); leftover-stage announcement after the rollback branch; WINDOWS CAVEAT docblock rewritten for the landed dance keeping both guard tokens ('WINDOWS CAVEAT', 'rename_failed'); POSIX legs byte-unchanged.
- candy-code/lang/en.php — cli.selfupdate.staged_windows, swap_leftover, swap_spawn_failed inserted in the SORT_STRING run between signature_missing and uncomparable.
- candy-code/tests/Support/DescriptorInheritanceGuardTest.php — 'Cli/WindowsSwap.php::launch' count 1 licence row with by-construction-survivor comment.
- candy-code/tests/Cli/WindowsSwapTest.php NEW — 8 tests.
- candy-code/tests/Cli/SelfUpdateTest.php — +3 seam-driven Windows dance tests +2 private helpers; existing 41 pins untouched.

## Windows posture (what EXECs vs what is certified)
- EXECuted as data on Linux: argv construction + literal/path separation (uniqid sentinels), php -l on HELPER, helper happy rename+log with dead parent, vanished-stage exit 0, usage exit 2, never-frees gave-up exit 1 (rename onto non-empty dir = deterministic lock), end-to-end detached launch() landing the swap, and the three SelfUpdate Windows-branch runs via WindowsShell::setOsFamilyForTesting.
- UNCONFIRMED-BY-RUN (correct conduct, 6.a/6.d precedent): the NT image-lock semantics — that the rename genuinely fails WHILE the parent holds the image; the wait-out leg is pinned via the deterministic dir-target stand-in only.
- No new FFI cdef anywhere (intptr_t law honored — zero FFI in the diff).

## Swap trace (injection-class proof)
- WindowsSwap.php HELPER const: nowdoc, zero interpolation; SelfUpdate never builds code strings.
- launchCommand() returns [PHP_BINARY,'-r',self::HELPER,(string)$parentPid,$new,$phar] — paths land in argv slots 4/5 only; test asserts HELPER byte-identical to the constant AND sentinels absent from it.
- Engines reused zero-diff: ProcessContainment::spawnSpec/env called, never edited; NUL-descriptor shape copied from BackgroundShellRegistry::start; bypass_shell from EgressProxyProcess.

## §1.8 cascade
- Lang: one SORT_STRING python run — cli.selfupdate run sorted (37 keys), file-wide dupscan 0 duplicates; CliLangCatalogueTest 8T/220A green, LangParityTest 7T/4039A green (en-only additions accepted by the guard as measured). No web.* keys moved → protocol mirrors untouched (zero web movement, as expected for a PHP-only step).
- ChildLifetime/DescriptorInheritance: DIG row ADDED and proven load-bearing (row-removal mutation → exactly 1 failure naming 'Cli/WindowsSwap.php::launch'; restore → tree clean). DIG 15T/5192A green with row.
- ReadPathCensus: NO row added — design-vs-disk deviation, measured: FUNCTION_SINKS has no proc_open/PHP_BINARY entry and literal sink-names-inside-strings are only require-scanned; helper is core-functions-only. ReadPathCensusTest 19T/773A green unedited; merged DIG|ReadPathCensus co-run 34T/5957→5965A green.
- TreeWideGuardRosterTest BARE 17T/3991A green; DuplicatedTestHelperDriftTest BARE 14T/204A green.
- GlobDialect: zero glob-shaped literals in my tests (no '*' strings; suffixes are concatenations of '.new'/'.swap.log'); no **Refused:** shapes; uniqid sentinels used.

## Gate figures (targeted only, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe)
- WindowsSwapTest 8T/45A green. SelfUpdateTest 44T/794A — 1 failure = testTheRosterCarriesThirteenVerbsWithSelfUpdateInAlphaOrder, FOREIGN base-carried: base b539be830 pins the 13-verb literal while base src ParsedArgs::SUBCOMMANDS already carries 14 ('import' present in base, grepped 2 sites); ParsedArgs untouched by this lane (git diff 0). Adjudicate-only per brief; numbers reported, cure none.
- DIG|ReadPathCensus merged 34T/5965A green. Guard family (CliLangCatalogue|LangParity|TreeWideGuardRoster|DuplicatedTestHelperDrift|ChildLifetimeScanner) 46T/8454A green.
- Existing SelfUpdate* family base..tip green except the foreign roster red above.
- Full suite NOT run (battery is the gate's); suite-figure staleness pair untouched (never opened).

## Collisions
- C-TUI-6.m (HandlesKeys/Renderer/Chat regions): zero overlap — none of my six files are in its set. No STOP needed. cc/C-TOOL-6.g untouched.

## Deviations & refused seams
1. ReadPathCensusTest unedited (design listed a row; the mechanism cannot produce a derived row for it — measurement in §1.8; merged census green).
2. HELPER carries an optional argv[4] budget-seconds override — test seam for the never-frees leg (production never passes it; keeps that pin sub-second); argv contract of the design is the 3-slot prefix, unchanged.
3. spawn_spawn_failed leg has no direct unit pin (launch() returning false is not forceable on this box without widening seams); it is a fail-loud exit path in source, disclosed.
4. Windows rollback re-asserts mode on the stage (chmod $new) instead of the target (helper can't carry pharMode without widening argv); rename-publishes-mode law preserved.
5. README untouched: design's docs/guards condition ("IF it states manual replace") measured FALSE at base.
6. 'until someone rules a relaunch dance' sentence replaced by the landed-dance text — no test pinned that sentence (grep-verified at base).

## §7 new-test list (for the battery/durations mint — live counts this session)
- candy-code/tests/Cli/WindowsSwapTest.php — 8 tests / 45 assertions.
