# C-TOOL-4.h — FIX-1 (round-1 fixer)

## Grounding (disk, before any write)
- `rev-parse --short HEAD` = 1da1b7a44 (as expected). `status --porcelain` empty.
- `rev-list --count 00fedf519..HEAD` = 5, brief expected 6 — RESOLVED: `8e247c8e7..HEAD` = 6;
  the 6th reviewed commit is the master-side rider 00fedf519 itself (fix: pin screenReader
  roster), which `00fedf519..HEAD` by definition excludes. The 5 C-TOOL-4.h picks are
  40479d9ae / 8fc84812a / 01caa75fc / 790d955d3 / 1da1b7a44 — all `git cat-file -t` = commit.
- REVIEW-1.md re-stat: 8,829 B @ 2026-10-31 09:58:48 — matches brief. Header verdict line
  VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 2 NIT — matches.
- Design block read VERBATIM from the lane copy
  (prompt_kit/findings/comparison-1026/design-tool.md, "### C-TOOL-4.h — Darwin process
  groups + wall fallback without GNU `timeout`").
- Residue-grep of tip tree (`if (false`/`&& false`/`|| true`): 10 hits, ALL benign/pre-existing —
  mutation-history prose comments, `false === $x` identity comparisons, and shell-string
  fixtures (`make || true` in LeadingCdGrantTest). Consistent with review Q5 zero-additions
  finding. Reported, nothing committed or deleted.

## MINOR-1 (the only acted finding)
Review wording (verbatim): "tests/MCP/StdioMcpServerTest.php:186-191 hardcodes the
setsid plan SHAPE ([detachedSpawnBinary(),'-w','--]) whenever the binary is present;
on a Darwin box where the php-helper arm wins, binary=PHP_BINARY and this pin
false-reds. src stays correct; fail-loud only. Fix: derive the expected prefix from
ProcessContainment::detachedSpawnPrefix()."
The two NITs are weld-ledger items — not acted (per brief).

## Fix applied (round 1) — commit df58a9b87 "C-TOOL-4.h: review fixes (round 1)"
File: candy-code/tests/MCP/StdioMcpServerTest.php, method
testTheSpawnPlanIsTheContainmentWrapAndScrubVerbatim (+14/-7, test-only, one file).

BEFORE (guard + hardcoded shape):
    if (ProcessContainment::detachedSpawnBinary() !== '') {
        $this->assertSame(
            [ProcessContainment::detachedSpawnBinary(), '-w', '--', 'php', '/somewhere/server.php', '--flag'],
            $plan[0], 'with a usable setsid the spawn plan must lead with the wrapper',
        );
    }
AFTER (unconditional, host-derived):
    $this->assertSame(
        [...ProcessContainment::detachedSpawnPrefix(), 'php', '/somewhere/server.php', '--flag'],
        $plan[0],
        'the spawn plan must lead with exactly the detach prefix this host proved (or with nothing where none probed clean)',
    );

False-red cured: expected and planned now read the SAME memoized production accessor —
on a Darwin helper-arm host (prefix [PHP_BINARY,'-r',<stub>,'--']) the comparison holds
by construction; the Linux steady state (setsid -w --), Windows empty prefix, and the
degraded bare shape all collapse identically. No silent skip anywhere: the pin always
runs; with no detach proven it pins the bare command (stronger than the old skipped branch).
Discrimination PROVEN by live mutation: spawnSpec() prepend neutered (detach wiring
removed from the spawn path) -> StdioMcpServerTest exactly 1 failure, this very assert
(19T/46A/F1); restored cp+md5 c3e3270cde137f9d809ae30f07c59727, re-run 19T/47A green.
Assertion 1 (plan[0]===spawnSpec(cmd)) and assertion 2 (plan[0]===[...prefix,cmd])
conjoin to pin prepend-preservation AND prefix-consistency both directions.

Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, anchored):
- 'StdioMcpServerTest::' 19T/47A — IDENTICAL to base snapshot (19T/47A, cp-swap measured); zero drift.
- 'BackgroundShellWallFallbackTest::|ProcessContainmentPhpSetsidTest::' 12T/46A GREEN (== Q6 3T/16A+9T/30A).
- '(BackgroundShellRegistryTest|ShellStopTest)::' 37T/250A GREEN (combined w/ above = 49T/296A == Q6 exactly).
  Brief's literal '(BackgroundShellRegistry|ShellStop)::' matches no class (names carry Test before ::) —
  ran the review-Q6 spelling. ADJUDICATION: BackgroundShellRegistryTest::testAStdoutThatShrinksAloneDoesNotStrandTheUnreadStderr
  flaked 2/9 on my tree, 0/5 at base; live-shell truncation/cursor timing race (line ~968),
  file+class disjoint from this fix (not loaded-executed under that filter); NOT in the
  accepted-foreign list — logged for lead, not cured (not a listed finding).
- TreeWideGuardRosterTest bare: 17T/3875A GREEN (== Q6). DuplicatedTestHelperDriftTest bare: 14T/204A GREEN (== Q6).
- AccessorDeprecationTest :168/:202 deprecation pair rode every run incl base — accepted list.
- PtyRun expect-miss never matched any filter used. No full suite run.

§7: NO new *Test.php files created — nothing to mint in the §7 closeout list.
suite-figure.json/durations.tsv NON-OWED (test-only edit inside an existing method,
counts unchanged 19T/47A; §1.8 ref-scan: zero guards/rosters/workflows reference
StdioMcpServerTest). Nits NOT acted (weld-ledger items per brief).

Commit: df58a9b87 on cc/C-TOOL-4.h atop 1da1b7a44 — git cat-file -t = commit;
author+committer detain (separate %ae/%ce greps = 1 each), zero Co-Authored-By.
Working tree porcelain: 0. Forbidden files untouched.
