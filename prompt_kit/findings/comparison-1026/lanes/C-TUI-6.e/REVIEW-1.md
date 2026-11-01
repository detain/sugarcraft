# C-TUI-6.e — REVIEW-1
VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT · WELD-ELIGIBLE: YES
Reviewer: fresh r1 (this file). Lane /home/sites/cc-cmp-C-TUI-6.e, branch cc/C-TUI-6.e, base b539be830, ONE pick 4f5336d94. Name-list == EXACTLY the 6 declared files (+659/−8); porcelain 0 at entry and after gates; REPORT.md 6,299 B with STATUS=COMPLETE line re-statted and read fully.

## Q1 Swap-command integrity — CLEAN
- WindowsSwap::HELPER is a pure nowdoc (`<<<'PHP'`), zero interpolation; launchCommand() == [PHP_BINARY,'-r',self::HELPER,(string)$parentPid,$new,$phar] — the six values travel as argv slots only (WindowsSwap.php:163-166 for the construction; helper reads $argv[1..3] as data). No splice of $new/$phar/$parentPid into the -r payload anywhere.
- Pinned both sides: WindowsSwapTest.php:55-81 asserts code-slot byte-identity + uniqid sentinels absent from HELPER + literal invariance under different args; then the helper is EXECED against temp files in production argv shape on this Linux box (rename+log happy leg :105-128, vanished :154-166, usage exit 2 :168-174, never-frees exit 1 via deterministic non-empty-dir lock :130-152, end-to-end detached launch() :176-207).
- Retry literals married to the class constants (200_000 µs / 60.0 s, design-declared, the ONLY timer in the leg — E646-clean, bounds the swap never an LLM call): WindowsSwapTest.php:93-103.
- Spawn: ProcessContainment::spawnSpec (reused, never edited — no second engine), env() passed, NUL device BY DIRECTORY_SEPARATOR selector (WindowsSwap.php:129) identical to the BackgroundShellRegistry::start shape (src/Background/BackgroundShellRegistry.php:385), ['bypass_shell'=>true,'create_process_group'=>true]; bypass_shell family precedent verified (EgressProxyProcess.php:146, WorkspaceHostClient.php:120). Handle deliberately dropped (proc_close would defeat the dance) — that is what the DIG row licenses.
- Token scan of added lines for cdef/FFI/intptr: ZERO. Parent exits 0 with cli.selfupdate.staged_windows (SelfUpdate.php swap arm, test-asserted); --rollback performs the same dance on `.old` and its leg is EXEC-confirmed on Linux (testWindowsRollbackRunsTheSameDanceAndConsumesTheKeptCopy), with the NT image-lock half honestly labeled UNCONFIRMED-BY-RUN (correct 6.a/6.d conduct).

## Q2 POSIX byte-unchanged + refusal classes — CLEAN
- SelfUpdate.php diff is pure addition: every executable change sits inside a new `if (WindowsShell::isWindows())` arm placed BEFORE the untouched POSIX rename/rollback legs; leftover announce also Windows-gated. POSIX behavior byte-unchanged.
- Rewritten WINDOWS CAVEAT docblock keeps both guard tokens ('WINDOWS CAVEAT', 'rename_failed'); DocFigureProseDriftTest anchored re-run: Tests: 92, Assertions: 4269 — green.

## Q3 §1.8 per-class — CLEAN (deviations adjudicated)
- en.php: exactly +3 keys (cli.selfupdate.staged_windows/swap_leftover/swap_spawn_failed); MY measurement: 37-key prefix in file order == LC_ALL=C byte order (one SORT_STRING run, insertion between signature_missing and uncomparable); dupscan (uniq -c over full prefix) 0. Guards (CliLangCatalogueTest::|LangParityTest):: merged: Tests: 15, Assertions: 4259 — green (8+7=15/220+4039=4259 consistent with builder split).
- DescriptorInheritanceGuardTest row TIGHT: 'Cli/WindowsSwap.php::launch' count 1 + by-construction-survivor reason, SAME commit as the site. My re-run GREEN: DescriptorInheritanceGuardTest::|ReadPathCensusTest:: merged Tests: 34, Assertions: 5965 (the +2-vs-builder-5957 wobble-era note mooted: mine 5965). Load-bearing JUDGED: guard has bidirectional arms (stale-row arm at :1864-1886 only accepts the row if the site is derived) ⇒ row removal must raise the unaccounted-site failure naming 'Cli/WindowsSwap.php::launch'. Mutation RE-RUN refused by map (cp -a denied, scratch /home/sites/rev-6e-r1-mut never created, nothing to prune) ⇒ builder's removal→1F labeled UNCONFIRMED-BY-RUN on my side, credited per protocol; structural derivation is load-bearing anyway.
- ReadPathCensus NO-ROW: VERIFIED — ReadPathCensusTest::FUNCTION_SINKS (:76-79) is a READ-sink roster (file_get_contents/fopen/scandir/glob/...) with no proc_open/PHP_BINARY entry; the design's expected row is a design-vs-instrument mismatch, the exec-sink is owned by the DIG guard instead. Census guard green UNEDITED inside the merged 34T/5965A co-run; file absent from name-list. Deviation SANCTIONED.
- BARE TreeWideGuardRosterTest: Tests: 17, Assertions: 3991 green. BARE DuplicatedTestHelperDriftTest: Tests: 14, Assertions: 204 green (new helpers livePharPath-adjacent names diverge from twins).
- tools/check-child-lifetimes.php: candy-code EXCLUDED by its header ("candy-code itself is excluded: its guard owns those sites") ⇒ no repo roster row due; DIG row is the receipt.
- GlobDialect: added-line scan for wildcard-bearing string literals: ZERO. Standalone re-run 13T/364A with 1F testTheCorpusFigureInTheDocBlockIsTheDerivedOne = known base-era PathGlob docblock figure drift (forbidden file, unopened by pick) — foreign, neither number banked per brief.
- Sanctioned mirrors / protocol trio / dist / suite-figure / durations / README / Bash.php: zero-diff against base (verified by explicit path-range diff = 0 lines).

## Q4 FOREIGN-13-VERBS ruling — BASE-PROVEN (pick did NOT cause) — plainly:
The roster F pre-exists at base b539be830 and is untouched here; it joins the debt ledger as a NEW base-proven foreign row (report-only, cure-none).
- (a) pick's diff on src/Cli/ParsedArgs.php = 0 lines; SelfUpdateTest hunks are pure additions after line 516 (+ top use line) — the roster test at HEAD:288 is byte-identical to base (blob read confirms same 13-verb literal);
- (b) base ParsedArgs::SUBCOMMANDS (b539be830):56 carries FOURTEEN verbs INCLUDING 'import' (2 grep sites), while the base test pins thirteen — the test re-derives from SUBCOMMANDS, so the F is arithmetic at base;
- (c) MY anchored re-run: SelfUpdateTest:: Tests: 44, Assertions: 794, Failures: 1 — sole F named testTheRosterCarriesThirteenVerbsWithSelfUpdateInAlphaOrder (name resolves, measured — zero-match trap avoided).

## Q5 Gates — MY figures (rule §7), all anchored, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe
- WindowsSwapTest:: → Tests: 8, Assertions: 45, green (matches claim exactly).
- SelfUpdateTest:: → Tests: 44, Assertions: 794, Failures: 1 (the foreign roster F; base's 41 pins survive untouched).
- DescriptorInheritanceGuardTest::|ReadPathCensusTest:: → Tests: 34, Assertions: 5965, green.
- CliLangCatalogueTest::|LangParityTest:: → Tests: 15, Assertions: 4259, green. DocFigure 92T/4269A; rosters above.
- Exclusions honored (PtyRun expectMiss, RegistryTest shrink, ForkedChildReaper item); no pairing run needed (no sandbox files touched); accepted-foreign roster untouched — the only two PHPUnit Deprecations surfacing on every co-run are the declared AccessorDeprecationTest :168/:202 rows, proven unrelated by --display-all-issues. NO full suite, no real-Windows exec demanded (static-cert + osFamily seam = 6.a/6.d law).

## Q6 Inventory + weld-readiness
Deviations #1-#6 all verified and judged sound: ReadPathCensus (above); argv[4] budget seam test-only (launchCommand never emits it — 6-slot contract pinned); spawn_spawn_failed unforceable disclosure honest (fail-loud source leg exists, .new survives by design); rollback mode re-assert rides the stage (chmod $new before handover — rename-publishes-mode law preserved); README condition measured-false (the only 'manually' passage, :140, is about pre-v0.1.0 unsigned builds, not the Windows replace); the dropped "until someone rules a relaunch dance" sentence — no base pin survived it (DocFigure green). Code conventions: declare(strict_types=1), final, one type per file, private ctor, Lang::t for all user strings, WHY-comments accurate (every cited site verified on disk). uniqid sentinels; zero markTestSkipped; zero weakened legs; residue 0.
WELD-ELIGIBLE: YES.
§7 handover for the welder/battery:
1. Durations row WindowsSwapTest.php 8T/45A (MY live count); SelfUpdateTest MODIFIED note (+3 tests; 44T/794A at tip). Suite carry +11T-class; figure pair untouched in-lane (staleness pair is the battery's).
2. NEW DEBT ROW (report-only): SelfUpdateTest::testTheRosterCarriesThirteenVerbsWithSelfUpdateInAlphaOrder — base-proven foreign at b539be830.
3. Weld as ONE explicit pick 4f5336d94 read from the LANE (never a range, no -x) onto MOVED master; siblings in queue (6.m live; zero file overlap).
4. PRESERVE Bash.php :535 double-quote — lane never touched Bash (zero-diff proven).
5. trio/dist/protocol UNOWED (zero-diff). VITEST-28/4 rider N/A — zero web in this pick.
