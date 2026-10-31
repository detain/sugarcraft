# C-TOOL-6.j — REVIEW-2

VERDICT: CLEAN — F-A + F-B closed exactly as r1 prescribed; F-A EXEC-confirmed independently (3/3 legs re-derived live); zero collateral; all five gate figures byte-equal to r1; WELD-ELIGIBLE YES. Disclosures (no findings): scratch prune map-denied (/tmp/opencode/rev-6j-r2-mut owner glance), shrink-pin transient 1-in-3 (accepted-foreign, report-only).

(reviewer r2, fresh session; closure round on FIX-1 `6a6b6c9f5`; lane /home/sites/cc-cmp-C-TOOL-6.j base 895b9d6fd)

## Q1 F-A closed at the blob — YES, EXEC-CONFIRMED independently
Cured line from `git show 6a6b6c9f5:candy-code/tests/Sessions/PtyRelayTest.php` (:355):
`self::assertSame('', (string) @\file_get_contents($paths['exit']), 'a refusing relay must record nothing');`
— review-verbatim, replacing the old `assertFalse(\is_file(...))`; WHY-comment present naming the stageRelaySinks 'xb' pre-creation + builder correction (e). Root cause re-verified on disk: stageRelaySinks fopen('xb') pre-creates .exit before every launch.
EXEC re-derivation CONFIRMED-BY-RUN (r2 scratch, this pty-capable host, relay child forced through the refusal door via `-d disable_functions=posix_kill` -> exit 2):
- cured leg on a true refusal: GREEN (assertNotSame(0) + assertSame('', content) pass).
- old line on the same refusal: RED `Failed asserting that true is false` (is_file true — broken-by-construction proven live).
- forged 'X' planted post-staging: RED `'' vs 'X'` on 'a refusing relay must record nothing' (non-vacuity proven live).
Matches FIX-1's M1a/M1b/M2 receipts exactly. Scratch was at /tmp/opencode/rev-6j-r2-mut/R2RefusalReDeriveTest.php (the brief's /home/sites/rev-6j-r2-mut path was DENIED by my edit map — disclosed deviation, no dir was ever created there); `rm` is map-denied on this reviewer, so PRUNE-OWED owner glance: /tmp/opencode/rev-6j-r2-mut (self-cleaning fixtures; only the one test file remains).

## Q2 F-B closed — YES
Blob lines (git show 6a6b6c9f5, BackgroundShellInteractiveTest.php): pre-injection capture `+ $hostCapable = ProcessContainment::interactiveAvailable();` (with WHY-comment), closing leg now
`self::assertSame($hostCapable, ProcessContainment::interactiveAvailable(), 'the reset must re-derive the hosts own answer');`
Old post-reset `self::assertTrue(ProcessContainment::interactiveAvailable());` is a '-' line in the diff (gone). Coverage preserved: the memo-injection refusal legs untouched; 12T/75A figure byte-equal to r1 (in-method swap, +0T). Non-tautology: injected memo value differs from the captured host answer on this pty-capable host, so a non-clearing reset still reddens (fixer's FB-M1 receipt `false is identical to true` consistent).

## Q3 Zero-collateral + reviewed picks byte-intact — YES
- Fix = ADDITIVE sixth pick: `git rev-parse 6a6b6c9f5^` = a8628f131 (5th reviewed pick tip), no amend/rebase; per-pick `git show --name-only` filesets match r1's reviewed rows exactly (pick1 relay+registry+RPC; pick2 registry+DIG+RPC; pick3 relay+registry+PtyRelayTest; pick4 Interactive+TWGR; pick5 RegistryTest sweep).
- 6a6b6c9f5 touches ONLY the 2 test files (+12/−3); `git show 6a6b6c9f5 -- candy-code/src | wc -l` = 0 (NIT is record-only in FIX-1 §4, quoted-verbatim, src untouched).
- en.php ZERO lines across master...HEAD; no markTestSkipped in the fix diff (grep 0); residue `if (false`/`&& false`/`|| true` in fix blob = 0.
- Author law: `%ae %ce` over the 6 picks = 6/6 detain@interserver.net (separate counts); Co-Authored trailers 0; forbidden-path grep (suite-figure|durations.tsv|dist/|en.php) across the range = 0; lane porcelain clean at review time.
- DuplicatedTestHelperDriftTest bare GREEN (14T/204A).

## Q4 Gates re-run (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe) — r1 figures EXACT
- `PtyRelayTest::` → `Tests: 8, Assertions: 80, PHPUnit Deprecations: 2.` OK (non-zero sanity ✓; figure UNCHANGED post-fix — cure is in-method).
- `BackgroundShellInteractiveTest::` → `Tests: 12, Assertions: 75, PHPUnit Deprecations: 2.` OK.
- combo `(BackgroundShellRegistryTest|PtyRelayTest|BackgroundShellInteractiveTest|SuiteSkipRosterTest)::` → `Tests: 77, Assertions: 563, PHPUnit Deprecations: 2.` OK (2 of 3 runs byte-exact green).
  Run #1 of 3 hit ONE transient red: `tests/Sessions/BackgroundShellRegistryTest.php:954` = `testAStdoutThatShrinksAloneDoesNotStrandTheUnreadStderr` — the brief's named OUTSIDE-window report-only item (RegistryTest O_TRUNC family); NOT touched by any of the six picks (method absent from all fix/builder diffs); report-never-cure honored.
- `TreeWideGuardRosterTest` bare → `Tests: 17, Assertions: 3925` OK.
- `DuplicatedTestHelperDriftTest` bare → `Tests: 14, Assertions: 204` OK.
- The `PHPUnit Deprecations: 2` on every run = the foreign AccessorDeprecationTest:168/:202 non-public data-provider pair (accepted roster, report-only).
- Not run by me (outside windows, per brief): keepWarm/KeyHelp/HelpTest/BinCandyCodeDispatch/AttachPaging/OneSided/ReadmeSuiteFigureDrift/GlobDialect/SettingsEditor/ChildStderrCapture/BootstrapLaunchFormat/Cli-Help env leg/ForkedChildReaper pairing.

## Q5 Weld-readiness + §7
WELD-ELIGIBLE: **YES**. F-A cured review-verbatim AND independently EXEC-proven (all three legs re-derived live); F-B cured per r1 prescription with figure intact; zero collateral; guards green at r1 figures; residual reds observed = accepted-foreign families only (shrink-pin transient 1/3, deprecation pair).

§7 LIST (never fix here):
- durations.tsv rows: `candy-code/tests/Sessions/PtyRelayTest.php` **8T/80A**, `candy-code/tests/Sessions/BackgroundShellInteractiveTest.php` **12T/75A** — r1 figures, MY live runs agree exactly (no divergence).
- Suite-figure battery carry: +20T from the two new test files stands UNCHANGED by the fix (adds no test method — my counts confirm); README pair + suite-figure.json re-pin at K=24 battery; NEVER hand-edit those files in the lane.
- Welder note: cherry-pick the SIX picks READ-FROM-LANE on its cherry (never a range expression); master has moved (444bb0f70 base-era, 4.e/5.d/4.g-era welds landed) — DescriptorInheritanceGuard/ReadPathCensus/TreeWideGuardRoster row UNIONS must be re-derived BY TEXT on the moved master; GlobDialect/ReadmeSuiteFigureDrift figures re-derive on the merged tree.
- Disclose the shrink-pin transient (BackgroundShellRegistryTest:954) in the weld report if it reappears at battery; do NOT cure.
