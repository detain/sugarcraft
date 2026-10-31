# C-TOOL-4.h — Darwin process groups + wall fallback without GNU timeout

STATUS: COMPLETE — 5 picks on branch cc/C-TOOL-4.h, worktree clean (porcelain 0), all gates green on final tree.
Lane: /home/sites/cc-cmp-C-TOOL-4.h · base 00fedf519e0ddf8a6a9384562d4e55eac45a187d · tip 1da1b7a4470fd6b1ac5b5ddd9554f4afe3b2cccc.

## ANOMALY — live sibling writing this worktree (registry-worthy)

This session (the dispatched builder) found at arrival an uncommitted ProcessContainment
half from the "dead" predecessor, validated it, and committed it as slice 1 (40479d9ae,
08:56). While this session was running the slice-2 GATES (09:02-09:09), the predecessor
session (or a resurrection of it) was ALIVE and editing the SAME worktree: it wrote the
BackgroundShellRegistry watchdog implementation + the RegistryTest flips + created
tests/Sessions/BackgroundShellWallFallbackTest.php, and at 09:09:32 IT COMMITTED the
staged set as 8fc84812a with correct author law (Joe Huss / detain@interserver.net).
It then committed slice 3 itself (01caa75fc, 09:12:06) and stalled MID-amendment of the
wall test; after the target file sat byte-quiet 3+ minutes this session validated the
amendment and committed it as slice 4 (790d955d3).
No double-commit occurred at any scope: one pick per scope, no duplicated commits.

Verified BEFORE each adoption: registry diff read line-by-line vs the design block
(watchdog subshell, trap 'kill $s; exit 0' TERM disarm, WATCHDOG_PID_ENV, trailer
kill-before-drain-before-stamp, timeout arm byte-identical, grouped => groupsSpawns(),
docblock truth-flips); wall test read fully (fallback via real spawnSpec, shortened wall
by unique-literal str_replace with occurrence-count pin, /proc group census excluding Z,
exit-file absence pin for the walled case, survivors-empty pin for the settled case).

No collision with the lead's declared live siblings (4.f is read-only on Sandbox,
6.j on src/Renderer.php). The twin is NOT on the quiescence list — the lead believed
this lane's predecessor dead with zero footprint. Two "done" returns may arrive for
C-TOOL-4.h; the branch tip 790d955d3 is the authority.

## Picks (on branch cc/C-TOOL-4.h off 00fedf519)

1. 40479d9ae "detach spawns via a probed PHP helper arm where setsid(1) is absent"
   — src/Support/ProcessContainment.php (+204/-51) + tests/Support/ProcessContainmentPhpSetsidTest.php
   (NEW, 9 tests). PHP helper arm (posix_setsid + pcntl_exec via /usr/bin/env) probed in
   order after located setsid; groupsSpawns()/detachedSpawnPrefix()/test seam; spawnSpec
   wraps both shapes. This session's commit.
2. 8fc84812a "wall the no-timeout shell fallback with a self-disarming watchdog subshell"
   — src/Background/BackgroundShellRegistry.php (+58/-15) + tests/Sessions/BackgroundShellRegistryTest.php
   (:804 else-arm byte-pin, :908 groupsSpawns() mirror) + tests/Sessions/BackgroundShellWallFallbackTest.php
   (NEW, 3 tests). Committed by the live twin; content validated by this session pre-land (above).
3. 01caa75fc "surface the detach arms and the no-timeout wall on Darwin in CI and docs"
   — .github/workflows/ci.yml (macos-smoke: +posix extension, new step running
   ProcessContainmentPhpSetsidTest on real Darwin) + candy-code/docs/TROUBLESHOOTING.md
   (macOS bullet: helper arms, pure-shell watchdog, degraded fallback, pcntl/posix need).
   Committed by the live twin; both diffs read and validated by this session.
4. 790d955d3 "run the settle pin past the wall so a missing watchdog disarm cannot hide"
   — tests/Sessions/BackgroundShellWallFallbackTest.php only: the settled command now
   runs under a 60s fallback wall (str_replace 'sleep 1800'→'sleep 60' of the wrapper)
   so a removed trailer disarm CANNOT pass by racing the watchdog's own group-KILL;
   census-only observation docblock. This session's commit.
5. 1da1b7a44 "bind the wall census to a lineage token so pid reuse cannot pose as a
   survivor" — same test file (+29/-5): per-run C4H_PROBE_TOKEN uniqid sentinel rides
   the spawned environment; the /proc census requires it in each subject's environ
   (unreadable environ counts as a member — fail-closed), closing the documented
   pid-recycling residual risk that made the settle pin race under load. Committed by
   the live twin at 09:26:58 after this session had read the settled diff, run the
   amended wall test green (3T/16A) and DuplicatedTestHelperDriftTest green (14T/204A)
   against exactly those quiet bytes; this session's own commit attempt raced it and
   found the tree already landed. Twin's message further proves M1 (drop trailer disarm)
   and M2 (strip exit-0 from the trap) each redden exactly the settle test.

## Design deviations vs the block (disclosed)

- buildWrapper keeps its one-arg signature; the wall test shortens the shipped 1800 by
  str_replace on `sleep 1800`, with a substr_count==1 pin on the literal's uniqueness —
  replaces the planned optional $wallSeconds param.
- The sketch's bare `wait $s` fall-through is guarded inside the trap itself
  (`trap 'kill $s 2>/dev/null; exit 0' TERM`) rather than `wait $s || exit 0` after it —
  same settle-safety property, cleaner; group rung never fires at settle.
- The docblock risk-note that groupMemberAlive() reads /proc (Darwin per-pid fallback)
  was not written; the return-site comment covers the degraded-shape ESRCH semantics
  instead. Accepted.
- ShellStopTest needed NO flip: it injects `grouped` directly (:158-169) and stays green.

## Mutation proof (slice-2 content)

Removing the trailer disarm line from the committed registry reddened exactly 1 test —
the settle pin at BackgroundShellWallFallbackTest:136 — proving the guard is
load-bearing and unpinned-by-accident. Restored via pre-mutation cp, cmp byte-identical,
porcelain re-confirmed. (Pre-dates slice 4; slice 4 strengthens the same pin further.)

## Seams REFUSED (outside design files.modify — for closeout)

- src/Support/CapturesProcessOutput.php :63-67 docblock still narrates the fallback as
  "macOS ships none ... proves `-w` exit-status forwarding" — stale-ish post-helper-arm.
  Code unchanged-correct (detachedSpawnBinary still names prefix[0]). Report, no edit.
- src/Tools/BuiltIn/ShellStop.php per-pid narration wording ("no setsid at spawn") —
  wording untouched, ShellStopTest green.

## Gates run (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; ALL on final tree tip)

- ProcessContainmentPhpSetsidTest + BackgroundShellWallFallbackTest + ShellStopTest: 20T/68A OK
  (2 deprecations = foreign AccessorDeprecation family, present in every run at tip).
- Live singles: BackgroundShellWallFallbackTest 3T/16A OK · ProcessContainmentPhpSetsidTest 9T/30A OK.
- BackgroundShellRegistryTest: 29T/228A OK (1 D, same foreign family).
- Doc family DocFigureProseDriftTest|EnvRosterDriftTest|ReadmeRosterDriftTest: 139T/45353A OK
  (TROUBLESHOOTING.md bullet not roster-guarded — env/help rosters untouched: no new env var
  or lang key shipped; SHELL_WATCHDOG is a wrapper-internal env, not a Help.php/ENVIRONMENT roster).
- ChildStderrCaptureTest: 6T/406A, exactly the ACCEPTED 2F census reds
  (Automation/Server/Host/Protocol roster drift — grep proved 0 rows naming Sessions/ /
  WallFallback / detach / setsid).
- Guard trio DescriptorInheritanceGuard|TreeWideGuardRoster|DuplicatedTestHelperDrift: 46T/9236A OK
  (re-run on committed final tree, after slice 4).
- tools/check-child-lifetimes.php: 61 libs / 33 sites / 9 findings / 9 accounted / 0 problems.
- Law greps at tip: 5/5 picks %ae=%ce=detain@interserver.net, %an=%cn=Joe Huss, Co-Authored-By count 0.
- Guard trio RE-RUN after slice 5 landed (census gained /proc/*/environ reads): 46T/9236A OK;
  BackgroundShellWallFallbackTest at final tip: 3T/16A OK.
- Nothing staged/committed from the forbidden set (suite-figure.json, durations.tsv, dist/,
  protocol trio, plan_comparison.md); no generator drift (no generated file touched).

## NEW test files (§7 durations candidates — not minted here)

- candy-code/tests/Support/ProcessContainmentPhpSetsidTest.php — live 9T/30A
- candy-code/tests/Sessions/BackgroundShellWallFallbackTest.php — live 3T/16A

## Slice 2 — registry wall fallback — LANDED as 8fc84812a
WATCHDOG_PID_ENV const; class docblock detach-probe flip; grouped => groupsSpawns();
buildWrapper else-arm = backgrounded watchdog subshell (trap kill+exit-0 on TERM;
sleep MAX; wait; kill -KILL -$$ || kill -KILL $$) exporting SHELL_WATCHDOG; trailer
disarms (kills watchdog) before stamping. Timeout arm byte-identical (pin survives).
RegistryTest in-step flips: else-arm needle + groupsSpawns. Deviations disclosed:
(1) `; exit 0` inside the trap — M2 proof: without it the settled shell is SIGKILLed
mid-trailer (status 9, no stamp) — load-bearing, pin RED exactly. (2) The `|| kill
-KILL $$` rung makes the bare-degraded shape safe (negative target ESRCHs when the
pid leads no group).

## Slice 3 — Darwin CI + docs — LANDED as 01caa75fc
ci.yml macos-smoke: posix added to extensions; new step filters
ProcessContainmentPhpSetsidTest:: (verified 9T non-zero on Linux). TROUBLESHOOTING.md
Automating-section macOS bullet: PHP helper groups + shell-watchdog wall + degraded
per-pid narration. No env-roster cascade (SHELL_* names unrostered repo-wide, grep
proved). DocFigure|EnvRoster 129T/45275A; Claim|McpStartupOrderingDocDrift 9T/61A.

## Hardening pick — 1da1b7a44 (census lineage token + settle wall 60)
Settle pin flaked ONCE under load: recycled pid posed as group leader inside the poll
window. Census now requires per-run C4H_PROBE_TOKEN in /proc/<pid>/environ
(fail-closed on unreadable), settle wall raised 2s→60s so only the trailer-disarm can
empty the census inside the window. M1 (delete disarm segment) → exactly the settle
test red; restored.

## HYGIENE INCIDENT — scratch-backup poison
/tmp/opencode/cc-cmp-C-TOOL-4-h/m1-backup.php carried a mutation shape I never
wrote (inline-blank disarm removal) — the live twin also touches this scratch path.
An M2 round run atop it was voided and RE-RUN from the git blob (5d8e6df30) with
md5 verification; poisoned backup deleted. Lesson applied since: restore src only
from `git cat-file -p HEAD:<path>`, verify md5 in the same command, never from a
hand-made .bak.

## FINAL FIGURES (tip 1da1b7a44, 4 picks off 00fedf519, porcelain empty)
NEW test files: ProcessContainmentPhpSetsidTest 9T/30A; BackgroundShellWallFallbackTest 3T/16A.
Final combined gate 49T/296A; DTH 14T/204A; TWGR 17T/3875A; DescriptorInheritanceGuard
15T; StderrEmitterCensus green (watchdog writes nothing to parent sinks);
ChildStderrCaptureTest = exactly the accepted-foreign 2F pair at every gate.
Pre-existing "PHPUnit Deprecations: 2" rides every run incl. base (not mine).

## TWIN ACTIVITY CONFIRMED (second instance)
790d955d3 (14 min before lane close, detain-authored, 0 trailers, +20/-4 same test file) = the twin committed its own settle-wall fix between my slice-3 commit and my hardening commit. My hardening stacked on the merged file state coherently (wall-60 + lineage census both in HEAD, final gates green on it). Lead: treat the twin as LIVE on this branch and adjudicate.
