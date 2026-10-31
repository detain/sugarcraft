# C-TOOL-4.f — Egress proxy core: WELD record (2026-10-31)

Welder: sole master writer, W34 LATE lane. Master before: 00fedf519.

## Merge
- Three EXPLICIT cherry-picks (never a range) off lane base 9a0c569eb, tip fd274f225:
  - 79e786867 → **fd091be62** (egress proxy core: EgressPolicy/EgressProxy/EgressProxyProcess/EgressBridge)
  - 4bbf922b2 → **cb783d505** (tests, flush-safe end() responses, guard rosters in-step)
  - fd274f225 → **1b8eb1a4a** (review fixes round 1: discardScaffold rename + structural pin)
- No -x per CHERRY-PICK-TRAILER-LAW-vs-PLAN-PRECEDENT-t646. Authors: %ae and %ce detain@interserver.net on all three (separate greps), zero Co-Authored-By, `git cat-file -t` = commit each, same shells.
- Sole shared-file overlap: ReadPathCensusTest.php AUTO-MERGED as union — sibling 'Hooks/HttpHook.php|fopen' row intact at :716 beside lane fopen SELF_LOCATED :1377 / require PROCESS_DERIVED :1382 / execute-path entry :1610. DescriptorInheritanceGuardTest '::start' ACCOUNTED_FOR row at :197 as-is. lang/en.php NOT in range (10 files, all candy-code) — no append union owed by this weld.
- TreeWideGuardRosterTest: lead-queued 4-key UNION hazard REFUTED on disk pre-weld — lane never touched the file (path log EMPTY, 0 Egress keys at tip); merged blob carries ZERO Egress keys, three sibling WALKS rows survive (:587/:645/:801). No guard key added from this lane anywhere.

## Merged-tree gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, PtyRun expect-miss never in scope)
- Egress family (4 anchored classes): **36T/117A OK** — r2-EXACT.
- ReadPathCensusTest|DescriptorInheritanceGuardTest: **34T/5894A OK** (r2 5892A, +2A assertion-only).
- TreeWideGuardRosterTest BARE: **17T/3879A OK** (r2 3873A, +6A) — GREEN as e78d5dc14/00fedf519 cures demand.
- DuplicatedTestHelperDriftTest BARE: **14T/204A OK** — EXACT.
- StderrEmitterCensusTest|ForkedChildExitConventionTest: **99T/23249A OK** (r2 23195A, +54A) — test counts identical every line; wobble disclosed, not chased.
- ReadmeSuiteFigureDriftTest: 4T/26A/2F = accepted staleness pair (test's own message: 'Failed asserting that 25537 is identical to 29365') — §7 re-pin duty carries +36T/+117A.
- Every run: PHPUnit Deprecations: 2 = accepted AccessorDeprecationTest :168/:202 global pair. Accepted-foreign set carried without re-run (r2 Q4 attests the picks moved none); GlobDialect figures asserted from NEITHER number.

## Generators / regeneration
- gen-command-docs --check, gen-tool-docs --check, gen-settings-doc --check: all "up to date" rc0 → NO regenerate leg committed.
- gen-protocol-schema: UNOWED (zero src/Protocol/* in lane name-diff). Web dist/npm chain: UNOWED, name-diff-proved (zero candy-code-web/*).

## §4 mark
- plan_comparison.md :3672 heading now `#### ✅ fd091be62,cb783d505,1b8eb1a4a C-TOOL-4.f — …` with the full disclosure record (r1 security-posture PASS incl WebFetch :976/:981→PublicUrlGuard single-sourcing + resolve-once vetted-literal dial + default-empty deny-all + 0700/0600 scaffold; honest 137-vs-127 reap pin; write()+close()→end() flush fix with r1's reasoned ≥4-red discrimination marked UNVERIFIED-BY-EXEC; FIX-1's RUN RED-before printing 'discardDirectory at line 152'; r2 CLEAN 5,499 B; NIT-2 Lang deferred to C-TOOL-4.g DORMANT-BY-DESIGN per design-tool.md 'No user docs: nothing is wired yet' — pre-empting a future reachable-gap flag; TWGR hazard-dissolved note; §7 riders incl durations rows for the 4 new Egress*Test.php at live 17/8/5/6T — EgressPolicyTest 17T/39A · EgressProxyTest 8T/24A · EgressBridgeTest 5T/19A · EgressProxyProcessTest 6T/41A; WARN NEXT WELDER block).
- Landed-blob `^#### ✅` count: 296 → **297** (verified on disk pre-commit).

## Proof status
- git cherry master cc/C-TOOL-4.f: '-' for all three, no '+' (re-checked at final master).
- Worktree /home/sites/cc-cmp-C-TOOL-4.f + branch cc/C-TOOL-4.f: removed after absorption proof.
- Commits: exactly two — the §4 mark + evidence weld commit, then the run-state fold (lead file verbatim, fresh read same shell).
