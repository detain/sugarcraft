# C-TOOL-4.h — REVIEW-1 (round-1, fresh, blob-anchored)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 2 NIT. Weld-ready.

Anchor: LANE=/home/sites/cc-cmp-C-TOOL-4.h, HEAD=1da1b7a44, 5 picks off 00fedf519
(40479d9ae helper · 8fc84812a watchdog · 01caa75fc ci+docs · 790d955d3 settle wall ·
1da1b7a44 lineage token); porcelain 0 lines at grounding and at close. Builder
REPORT.md (main tree) stat'd 11,198 B — matches lead. All judgment below from
`git show 1da1b7a44:<path>` blobs, never working-tree bytes.

## Q1 — Darwin semantics / Linux untouched — PASS
detachedSpawnPrefix() (ProcessContainment.php:225-246 blob): Windows short-circuits
with ZERO probes; candidates = located [setsid,'-w','--] FIRST (stat walk, never
spawns a missing binary) then the php stub; loop breaks on first pass, so on Linux
setsid wins and the helper probe NEVER RUNS — steady-state cost identical to base
(one probe per process, class-scope memo; the `helper not tried when setsid passes`
arm of ProcessContainmentPhpSetsidTest pins exactly this). probeDetachedCandidate
(:320-373): one proc_open per candidate, pipes drained via stream_select, pid read
once, leadership POLLED (posix_getpgid===pid), accept = leader && exit 7. Failure
arms all drop toward bare spawn: posix/pcntl missing→255, setsid()<0→127, exec
fails→127. Degradation is NOT silent: groupsSpawns() feeds the registry `grouped`
flag → ShellStop narrates per-pid-only, and TROUBLESHOOTING.md says so. Documented
deviation (b): leadership leg skipped (default true) when the PARENT lacks posix.

## Q2 — watchdog trailer, line by line — PASS
8fc84812a buildWrapper else-arm, read from the blob:
`; else ( trap 'kill $s 2>/dev/null; exit 0' TERM; sleep 1800 & s=$!; wait $s; kill -KILL -$$ || kill -KILL $$ ) & SHELL_WATCHDOG=$!; export SHELL_WATCHDOG; exec bash -c "$SHELL_INNER"; fi`
(a) `-$$` group-kill requires leadership — guaranteed by the detach-probe legs; in
the degraded bare shape the negative target ESRCHs and `|| kill -KILL $$` ends only
the wrapper — stated in the code comment. (b) Trailer
`SHELL_STATUS=$?; if [ -n "${SHELL_WATCHDOG:-}" ]; then kill "$SHELL_WATCHDOG" 2>/dev/null; fi`
fires BEFORE the drain, BEFORE the exit-file stamp, BEFORE exit — ordering verified
byte-wise in the string. (c) `; exit 0` is INSIDE the trap, so it is the
WATCHDOG-SUBSHELL's own exit status only; the real command status rides
SHELL_STATUS=$? → stamp → `exit "$SHELL_STATUS"` — it cannot mask a child failure
(watchdog is a different process; no existing pin requires non-zero passthrough
from the trap arm). (d) grouped→groupsSpawns(): grepped all blobs — zero missed
callers; the RegistryTest :912 mirror flipped in-step. timeout arm segment
byte-identical to base.

## Q3 — vacuity map (static; exec mutation denied by harness) — PASS
Delete trailer-disarm → 60s settle wall (790d955d3: fallbackWrapper('echo hi',60))
means the watchdog survives past the census window → settle pin's survivors census
goes red (builder M1: reds EXACTLY; structurally sound — the window-vs-wall math
holds). Strip `; exit 0` → trap-arm kill of the sleeping child leaves `wait`
returning 143-era status inside the subshell but the wall-kill test asserts
status===9 + no exit file — the pin discriminates via the census-empty leg
(builder M2). WallFallback test 1 carries a POSITIVE CONTROL (midway census asserts
sleepers non-empty) so a neutered group-kill cannot pass silently. PhpSetsid 9T:
each production leg (order/break, leadership refusal of a non-leading
exit-forwarder, missing-binary 127 incl drain honesty, both wrap forms, pcntl-less
passthrough) has its own arm. Lineage token (1da1b7a44): binding IS pinned — the
token must land in /proc/<pid>/environ of the real members for test 1's non-empty
midway census to pass; UNREADABLE environ counts as member (fail-closed accuses,
never softens). RegistryTest :804 flip is a truth-migration to the FULL new
else-arm byte string (STRENGTHENED, not loosened); :912 flip semantically
equivalent (prefix non-empty ⇒ head non-empty) with the assertSame($grouped) leg
intact. ShellStopTest injects `grouped` via reflection directly — no flip needed,
builder claim verified.

## Q4 — §1.8 guard law — PASS (one exec denied, disclosed)
- Doc family (DocFigure|EnvRoster|ReadmeRoster): MEASURED 139T/45353A — claim exact.
- TreeWideGuardRoster 17T/3875A GREEN; DuplicatedTestHelperDrift 14T/204A GREEN;
  DescriptorInheritanceGuard 15T/5157A GREEN.
- check-child-lifetimes: `php tools/...` EXECUTABLE BY MY MAP — **UNVERIFIED-BY-EXEC**;
  static adjudication instead: the new site is one textual proc_open inside
  probeDetachedCandidate (ProcessContainment.php:324, @-suppressed), handle NEVER
  returned, every post-open path ends at the single proc_close (:371) after
  optional proc_terminate → ChildLifetimeScanner's reaping proof holds, so the site
  derives as accounted-for and licenses no new row; the roster is
  file::function-keyed (tool :222) and ProcessContainment's spawn sites already
  carry rows on master. Battery remains the authority.
- ci.yml macos-smoke: `posix` added to extensions (mbstring,intl,json,pcntl,posix,
  ffi,:xdebug); new step filter 'ProcessContainmentPhpSetsidTest::' — class name
  VERIFIED byte-for-byte vs tests/Support/ProcessContainmentPhpSetsidTest.php;
  single-quoted, anchored, coherent; job-header comment states the full-suite-omission
  roster decision.
- TROUBLESHOOTING.md bullet: honest — promises the helper only when pcntl+posix
  exist and documents the degraded per-pid narration. Does NOT promise setsid on
  boxes without it.

## Q5 — residue/hygiene in blobs — PASS
Added-line scan of 00fedf519..1da1b7a44: zero `if (false`/`&& false`/`|| true`, no
dd-style residue, no TODO/FIXME, no `**Refused:**`-shaped literals. The discarded
second `setsid -w` idea: only ONE candidate-construction site exists — no trace
survives. No glob-shaped literals added (GlobDialect corpus safe). Lineage token
uses per-process `uniqid(getmypid,true)` sentinel — renderable, absent-needle-safe.
One type per file; E646 clean (60s settle wall is a per-process ceiling; no
request timeout anywhere). File-set == exactly the design block's create+modify
(7 files) — scope clean.

## Q6 — weld-readiness, MEASURED at tip (cwd=candy-code, linked vendor, plain pipe)
- `(BackgroundShellWallFallbackTest|ProcessContainmentPhpSetsidTest|BackgroundShellRegistryTest|ShellStopTest)::` = **49T/296A GREEN** (claim matched exactly; WallFallback 3T/16A, PhpSetsid 9T/30A singles measured; RegistryTest 29T/228A arithmetic-consistent, not run solo).
- DuplicatedTestHelperDrift 14T/204A · TreeWideGuardRoster 17T/3875A · DescriptorInheritanceGuard 15T/5157A — all GREEN (claims matched).
- "Census trio 126T/27196A": could not reproduce that exact membership; ReadPath|StderrEmitter|FixtureLifetime = 117T/23859A GREEN; a broader 6-census sweep is 188T/3F and the 3 reds are FOREIGN (SkillTool.php::execute — C-EXT-2.c lineage; App/App.php+McpServeHost.php STDIN rows — C-MCP-5.a lineage; ClaudeSettingsImportTest.php:232) — `git diff 00fedf519..1da1b7a44 --name-only` proves the lane touched none of those files. Asserting NEITHER figure.
- Foreign set — shrank for nothing this lane: keepWarm 1F, KeyHelp 5+1R, HelpTest[TIER], BinCandyCodeDispatch :1893, AttachPaging 2F, OneSidedHome :435, ReadmeSuiteFigureDrift pair, GlobDialect 1F, SettingsEditor :189, AccessorDeprecation (2 deprecations rode every run incl base), BootstrapLaunchFormat doc-roster leg, Cli/HelpTest env leg — all adjudicated foreign/unchanged. ChildStderrCaptureTest = 6T/2F EXACTLY the accepted pair; offenders are Chat/Backend test files outside the lane (inherited-stderr rows), not new. PtyRun expect-miss excluded from every filter.

## Findings
- MINOR (step-local): tests/MCP/StdioMcpServerTest.php:186-191 hardcodes the
  setsid plan SHAPE ([detachedSpawnBinary(),'-w','--]) whenever the binary is
  present; on a Darwin box where the php-helper arm wins, binary=PHP_BINARY and
  this pin false-reds. src stays correct; fail-loud only. Fix: derive the expected
  prefix from ProcessContainment::detachedSpawnPrefix().
- NIT: ci.yml comment "Darwin ships no setsid(1) with -w" — macOS ships no
  setsid(1) at all; phrasing compatible, truth-harmless.
- NIT: ShellStop.php degraded narration still says "no setsid at spawn" though
  the degraded shape can now also mean "no working helper"; builder disclosed as
  REFUSED (design files.modify omits ShellStop.php) — correctly left out of scope.

## §7 new-file mint list (gate duty, figures LIVE here)
- candy-code/tests/Support/ProcessContainmentPhpSetsidTest.php — 9T/30A
- candy-code/tests/Sessions/BackgroundShellWallFallbackTest.php — 3T/16A
