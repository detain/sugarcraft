# C-TOOL-4.g — FIX-1

## Round-1 fix

MINOR-1 CURED (tests-only, one commit): `EgressBridge.php:221` run()-entry
resolveProgram call-site now pinned by
`EgressAllowlistModeTest::testTheBridgeRunResolvesAlongPathBeforeForkingSoTheCommandRuns`
+ private helper `bridgeBareWordSubprocess()`. r1's literal candidate (uniqid
bare word expecting the 127 shape) was measured NON-DISCRIMINATING against the
code: an unfindable word arrives bare with OR without the resolve line, so both
shapes die in the exec-failure branch — the pin discriminates the DOCUMENTED
promise instead (run() docblock: "Without this leg every ordinary command in an
allowlist jail would die at 127"): a planted `#!/bin/sh` script under a uniqid
bare word, found ONLY by a PATH search the spawned child prepends, must arrive
at `run()` and echo its own status (`rc=42`). Spawned-child shape honors the
file's fork law (suite never pcntl_forks); absolute-path 4.f sibling
(EgressBridgeTest::bridgeSubprocess) stays as-is, helper names collision-scanned
zero tree-wide.

DISCRIMINATING PROOF (EXEC, not UNCONFIRMED): src backup cp+md5; `sed -i 221d`
on EgressBridge.php → php -l clean → `--filter 'EgressAllowlistModeTest::'` →
`Tests: 12, Assertions: 100, Failures: 1` — the sole red is the new leg,
diff `-'rc=42' +'rc=137'` (the 137 exec-failure death shape per the 4.f sibling
pin); restore `md5sum -c` OK.

Gates re-run at the fix tip (cwd=candy-code, plain pipe, full Test stems,
NON-ZERO every):
- EgressAllowlistModeTest:: → Tests: 12, Assertions: 100 (11/90 + 1T/10A leg;
  base re-measure via stash proved the 2 PHPUnit deprecations are base-inherited).
- sandbox family (tests/Tools/Sandbox dir 91/503 + BashSandboxTest:: 23/113)
  → 114/616 = r1's EXACT 113/606 + this leg.
- (SettingsSchemaDocDriftTest|LayeredSettingsTest|CliLangCatalogueTest|TrustKeyDocumentationDriftTest|ConfigWriteProducerDocumentationDriftTest):: → 153/1460 EXACT.
- (DocFigureProseDriftTest|ReadmeRosterDriftTest|StderrEmitterCensusTest|ReadPathCensusTest):: → 216/28525 EXACT.
- BARE TreeWideGuardRosterTest → 17/3922 (r1 shape). BARE DuplicatedTestHelperDriftTest → 14/204 EXACT.
- PtyRunToolTest::testExpectMiss excluded by policy (never ran); RegistryTest O_TRUNC + ForkedChildReaper report-only (untouched).
- Zero src touched (pin is tests-only): git diff --stat = 1 file, 78 insertions.

NIT ledger (report-only, NOT fixed by law):
- NIT-1: settings-audit dropped-domain-count announcement = LEAD ruling item
  (r1 §7 item 4, "lead's taste") — listed, not fixed.
- NIT-2: future sandbox lanes should co-run GlobDialect in their gate lists —
  lead process note; this fix adds no corpus literals (GlobDialect sole-red
  stays the foreign PathGlob doc-vs-corpus 1F — assert-NEITHER per roster).
- NIT-3: design cite drifts (MODES :53-56 vs interface; tools() :8523 vs
  :9597) — left; blob-text headings remain the weld authority.

§7 FOR THE WELDER (live figures from this fix tip):
1. durations.tsv row `candy-code/tests/Tools/Sandbox/EgressAllowlistModeTest.php`
   = **12T/100A** (was 11T/90A at r1; this fix adds the call-site leg +1T/+10A).
2. battery note unchanged: hairpin-capable-host 200/403 leg.
3. Seatbelt macOS loopback-port arm follow-up unchanged.
4. NIT-1 dropped-entry count remains LEAD-HELD.
5. RESTRICTED_TOOL_NAMES seam remains LEAD-HELD, zero new evidence.
6. Landed picks for welder: 685ffddb5, ed626d858, fcda09f3b off base 895b9d6fd
   — NEVER a range.
