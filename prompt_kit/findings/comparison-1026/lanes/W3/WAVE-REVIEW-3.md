# W3 — WAVE-REVIEW-3 (round 3 confirmation; tip at brief-cut 6205cabf3, now d4e814e87)

VERDICT: FINDINGS — 0 code blockers found; r3 confirmation INCOMPLETE: reviewer shell hard-denied
(3 probe attempts), so items (1) filters, (2) generators --check, (3) tools rc0 are NOT RUN.
Hence NOT a CLEAN cert; W3 closable once the 6 commands below run green at a master that contains
6205cabf3 (it is an ancestor — verified via reflog chain, see STATIC PROOF).

[MAJOR·harness] r3 brief assumes a shell-capable reviewer; this session is static-only (same class
as the candy-top-refit "reviewer = SHELL DENIED" precedent). Fix: re-dispatch r3 as a coder lane, or
lead executes: (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
  php vendor/bin/phpunit -c phpunit.xml --filter 'TreeWideGuardRoster|ReadPathCensus|SwallowingCatch|TtyStreamArgumentCensus'
  php vendor/bin/phpunit -c phpunit.xml --filter 'Login|UsageRecords|McpDispatcherPresetScope'
  five-guard filter (expect ONLY staleness pair 25,537-vs-live); php scripts/gen-command-docs.php --check;
  php scripts/gen-tool-docs.php --check; php scripts/gen-settings-doc.php --check;
  php tools/check-path-repos.php --no-lib-path-repos; php tools/check-child-lifetimes.php.

[MAJOR·harness-state] master d4e814e87 = 6205cabf3 + 66559688e + d4e814e87 ("P-CTX.e: Shell wiring",
"P-CTX.e: review fixes (round 2)" — W7 lane, logs/HEAD:2747-2748, committer Joe Huss
<detain@interserver.net> each). Any gate run now tests W7-in-flight state; re-pin staleness-pair
expectation accordingly at run time.

STATIC PROOF COMPLETED (all consistent, nothing adverse):
- Refs: .git/refs/heads/master read 6205cabf39d1… at t0 (matches brief), d4e814e87 at t1; .git/HEAD =
  refs/heads/master. Cure chain 945ccf979→0a66f24b8→1cebcd1f0→e383db1d0→weld 6205cabf3 present as
  loose git objects (glob-verified) + reflog lines 2742-2746, all author/detain, messages name all
  four §1.8 debts.
- Cure sites landed on disk (claims-vs-landing, 4/4): LoginSubcommandTest.php:363-366 capture-then-
  assert ("catch only records the throwable, every claim stands after"); TtyStreamArgumentCensusTest
  .php:292 roster row 'src/Cli/Subcommands.php: constant:\STDIN'; SwallowingCatchSrcCensusTest.php:100
  'src/Session/EnhancedSessionStore.php::usageRollups' (+ UsageRecordsTest.php:471-486 structural pin);
  McpDispatcherPresetScopeTest.php:267-271 capture-then-assert.
- suite-figure.json pins 25537/609443 measured_at 2026-10-26 = stale side of the allow-red staleness
  pair (r2 shape "25,537 vs 26,082"); closeout re-pin still owed — not a W3 blocker.
- 10 W3 ✅ marks (§4 lines 420-529: 393635d2c 9c3857a5b a8ead9976 893f271cf a992413b0 0e25ae5c7
  40d19f15f f05d52c06 8c93928f2 592261f3c): lane hashes not loose (packed) — cat-file spot check
  needs shell; r2's 10/10 cat-file+ancestor+detain proof stands (lead may re-run 3-hash spot).
- Hygiene: NO W3 worktree/branch residue. Sole cc/* entries: branch refs/heads/cc/P-CTX.e
  (loose e570ec06a; stale packed-refs row c2477fe71) + .git/worktrees/cc-cmp-P-CTX.e — W7 lane per
  §3 row 143 (P-CTX.e ∈ W7), disclosed live by r2. NIT only: that row is still live-picking onto
  master; prune after W7 weld.

NOT VERIFIED (harness): filter greens, generator --check, tools rc0, DreamPassTest:288 non-appearance
(allow-red list unchanged). PtyRunToolTest untouched per brief.

LEAD NOTE @1248@: battery will be executed by the lead on the settled tree AFTER the in-flight
P-CTX.e welder's evidence commit lands; result appended below as the r3 runtime supplement, then
W3 closes together with W5 in one wave-close welder call (§3 rows + §4 marks COMMIT +
waves_closed=[1,2,3,4,5]).

## r3 RUNTIME SUPPLEMENT (lead-executed @1258@, master 112c5bfa2 = post-P-CTX.e weld)

- `--filter 'TreeWideGuardRoster|ReadPathCensus|SwallowingCatch|TtyStreamArgumentCensus'` → **OK, Tests: 94, Assertions: 8853** (2 house-wide pre-existing PHPUnit deprecations) — /tmp/opencode/cc-cmp-W3r3-a.log
- `--filter 'Login|UsageRecords|McpDispatcherPresetScope'` → **OK, Tests: 103, Assertions: 1671** — /tmp/opencode/cc-cmp-W3r3-b.log (DreamPassTest:288 did not appear in these filters)
- five-guard → 133T/15682A, **exactly 2 Failures = the allowed staleness pair** (25,537 pinned vs 26,093 live; ReadmeSuiteFigureDriftTest.php:93/:120) — /tmp/opencode/cc-cmp-W3r3-c.log
- generators: command/tool/settings docs all 'up to date' (candy-code/tools/gen-*.php --check)
- `php tools/check-path-repos.php --no-lib-path-repos` rc0; `php tools/check-child-lifetimes.php` rc0
- NOTE: the brief's scripts/gen-*-php paths are candy-code/tools/gen-*-php (reviewer cite path corrected).

**r3 CERT: CLEAN — W3 closable.**
