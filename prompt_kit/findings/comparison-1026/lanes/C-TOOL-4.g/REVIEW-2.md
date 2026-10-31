# C-TOOL-4.g — REVIEW-2 (r2 closure, fresh reviewer)
VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT. WELD-ELIGIBLE: YES.

Lane reads (my own): branch cc/C-TOOL-4.g, base 895b9d6fd, log = 685ffddb5 / ed626d858 / fcda09f3b (tip), porcelain 0. Law: %ae 3x detain, %ce 3x detain (separate greps), Co-Authored 0. FIX-1.md 3,475 B @14:31:07; REVIEW-1.md 9,283 B @14:17:43; REPORT.md 9,061 B @14:06:41 — sizes re-statted, match lead claims.

## Q1 — cure adequacy: YES, load-bearing discrimination (statically reasoned + EXEC receipts consistent)
Planted script is reachable ONLY via the PATH the spawned child driver prepends (putenv before run()); the word is uniqid-bare (no '/'), exists nowhere else, and is absent from the child's CWD.
- WITH EgressBridge.php:221: resolveProgram PATH-searches, is_executable hits $probeBin/$word, returns ABSOLUTE path → fork → pcntl_exec(absolute) succeeds (kernel shebang) → script exits 42 → run() reaps wifexited/wexitstatus → 'rc=42' asserted.
- WITHOUT :221 (the sed 221d mutation): pcntl_exec is execve-family — searches NO PATH; bare word ENOENT → falls to ForkedChild::exitNow(127), whose documented first act is self-SIGKILL (ForkedChild.php:28-34; pinned 4.f sibling EgressBridgeTest::testRunReportsTheHouseSignalDeathCodeWhenExecFails :131-148 asserts exactly 'rc=137') → parent maps signal death 128+9 → 'rc=137' ≠ 'rc=42' → sole red = the new leg.
No alternative code path converts bare→path (read run() fully, :219-257). Fixer EXEC claim (`sed 221d` → Tests: 12, Assertions: 100, Failures: 1, diff -'rc=42' +'rc=137', cp+md5 restore) matches the static prediction term-for-term. The honest rejection of r1's uniqid/127 candidate is CORRECT: resolveProgram returns unfindable words unchanged (by design, :201), so both shapes die identically — non-discriminating; the PATH-only-FOUND shape restores the asymmetry. Fork law honored (spawned child, suite never pcntl_forks).
Deprecations pair: my runs show EXACTLY 2 on every filter, including TreeWideGuardRosterTest-alone and the config-guard five (which never execute the new legs) → the fix introduced zero. The base-inheritance ATTRIBUTION is the fixer's stash re-measure; I did not stash-mutate the lane (read-only law) — corroborated-by-count-invariance, disclosed.

## Q2 — zero collateral: CLEAN
git show fcda09f3b --name-only = EXACTLY candy-code/tests/Tools/Sandbox/EgressAllowlistModeTest.php (+78/-0, zero src). Diff residue grep (markTestSkipped|TODO|XXX|not implemented) = 0. Helper bridgeBareWordSubprocess: tree-wide grep across candy-code/tests → ONE file (no twin). BARE DuplicatedTestHelperDriftTest MY RUN: OK — "Tests: 14, Assertions: 204" (EXACT claim) ⇒ guard itself certifies the new helper drift-free.

## Q3 — gates, MY numbers (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored full stems, NON-ZERO every):
- EgressAllowlistModeTest:: → "Tests: 12, Assertions: 100, PHPUnit Deprecations: 2" (FIX-1 EXACT; supersedes r1-era 11/90).
- tests/Tools/Sandbox dir → "OK (91 tests, 503 assertions)"; BashSandboxTest:: → "Tests: 23, Assertions: 113" ⇒ family union 114/616, FIX-1 EXACT (r1 113/606 + the new leg).
- (SettingsSchemaDocDriftTest|LayeredSettingsTest|CliLangCatalogueTest|TrustKeyDocumentationDriftTest|ConfigWriteProducerDocumentationDriftTest):: → "Tests: 153, Assertions: 1460" EXACT.
- (DocFigureProseDriftTest|ReadmeRosterDriftTest|StderrEmitterCensusTest|ReadPathCensusTest):: → "Tests: 216, Assertions: 28525" EXACT.
- BARE TreeWideGuardRosterTest → "Tests: 17, Assertions: 3922" green (fix adds no roster rows — tests-only, consistent).
- Pairing (BashSandboxTest|BubblewrapHardeningTest):: co-run → "Tests: 39, Assertions: 192" GREEN (base db1441a61 holds — no new finding).
PtyRunToolTest::testExpectMiss excluded by policy (never ran); RegistryTest O_TRUNC + ForkedChildReaper out of filters (report-only). NEVER a full suite. Scratch /home/sites/rev-4g-r2-mut NOT NEEDED and never created (du/df map-denied once; count-invariance sufficed) — nothing to prune.

## Q4 — weld-readiness: WELD-ELIGIBLE YES
Residuals = the accepted-foreign roster EXACTLY (keepWarm 1F, KeyHelp 5+1R, HelpTest[CANDY_CODE_SERVICE_TIER], BinCandyCodeDispatch --worktree :1893, AttachPaging 2F base-proven 364a52876, OneSided :435, ReadmeSuiteFigureDrift staleness pair + battery carry, GlobDialect 1F assert-NEITHER, SettingsEditor :189, ChildStderrCapture 2F, AccessorDeprecation :168/:202, BootstrapLaunchFormat doc-leg, Cli/HelpTest env leg, RegistryTest O_TRUNC, ForkedChildReaper). ZERO new family in any of my runs.
§7 LIST (never fix, LEAD/welder ledger): durations.tsv fact EgressAllowlistModeTest.php = 12T/100A at fcda09f3b (SUPERSEDES 11T/90A); suite-figure+README pair +1T-class carry — battery re-pin authority; hairpin-capable-host live 200/403 leg; Seatbelt macOS loopback-port follow-up; NIT-1 settings-audit dropped-domain count = LEAD ruling; RESTRICTED_TOOL_NAMES remains LEAD-HELD, zero new evidence.
Welder line: THREE explicit picks 685ffddb5, ed626d858, fcda09f3b off base 895b9d6fd, read from lane branch ref — NEVER a range, no -x. Landed-blob headings are the weld authority.
