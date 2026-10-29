# C-MCP-4.c — REVIEW-3 (round 3, 2026-10-29, worktree /home/sites/cc-cmp-C-MCP-4.c)

VERDICT: CLEAN

Chain re-derived: master..HEAD = eb3a17dcc + 628a5abf3 + 68d6b28fa on base 003cb1e4e (spawn-cited 94c919821 fix-r1 never existed — r1 cures rode 628a5abf3, matching REVIEW-2/FIX-1; judged on content). 9 files +807/−2 = exactly the BRIEF-declared set; tree clean; chain content unchanged r2→r3 except the new pin.

## r2 MINOR-A cure — CONFIRMED
testTwoConcurrentLoginWatchesStayInTheirOwnSessions (McpLoginServedTest.php:252-301): two live hub sessions created, w4ctx→x / w4cty→y registered concurrently, driven through the REAL ServerContext::pump(). Asserts each transcript gets exactly its own 2 rows (server-specific URL text), bidirectional assertStringNotContainsString cross-talk arms, and independent retirement. Mutation reasoning: the r2-proposed pollDue neuter (route to first watched session) lands X=3 rows / Y=1 — exactly FIX-1's reported "size 3 vs 2" red — plus cross-talk fires; a pump-half misroute trips the same grouping. It IS the sole two-session discriminator (other 9 methods single-session) = the BRIEF-promised independent pin, now present.

## No regressions (all re-verified at 68d6b28fa)
- register→pump→appendNotice→Message::notice (uiOnly System row, src/Message.php:331)→durable message.created kind=notice; one-shot consume (done/gone unset; post-done pollDue []).
- Paste-back: dials ONLY tcp://127.0.0.1:$expected from the slot's own announce (structural substr_count-1 pin + live-listener GET proves code+state ride through); hash_equals on scheme/host/port; 4-shape refusal battery proves never-echo (canary) + never-dial (stream_select 0); --code judged before busy.
- E646: PASTEBACK_TIMEOUT_SECONDS is a connect bound + per-read cap; human wait stays event-driven; dead-listener test <3.0s. No blanket LLM timeout.
- §1.8: zero new proc_open; guard test files byte-untouched; census family GREEN in my linked tree: GlobDialect|StderrEmitterCensus|TreeWideGuardRoster|DescriptorInheritanceGuard|ReadPathCensus = 160T/32322A OK.
- Lang: 9 new keys, all 9 consumed in code; MCP.md served-flow + paste-back + Write-scope risk sentence landed per BRIEF risks.

## Gates (cwd=candy-code in worktree, -c phpunit.xml, plain pipe)
McpLoginServed 10T (A 94-97 run-to-run: iteration-count-dependent assertNotNull in the pump-poll loops, same shape as pre-existing pump tests — disclosed, count-stable); McpLoginServed|McpLoginFork|McpAuthStore = 61T/1605A OK (FIX-1 claim EXACT); BRIEF-named DocFigure arms = 2T/16A OK; SessionHost|ServerContext = 82T/402A OK; OAuth six-class window = 102T/332A OK. No full suite run; PtyRunToolTest never touched.

## Weld carries (NOT lane findings)
- scripts/parallel-tests-durations.tsv: McpLoginServedTest.php row ABSENT (grep 0) — weld must mint it (10T; McpLoginForkTest row already present).
- suite-figure.json/README staleness pair re-pin (closeout duty; red-at-tip expected, not run here).
- Not charged: AccessorDeprecationTest private-data-provider "Deprecations: 2" is pre-existing master dirt (file outside the diff); main-tree ReadPathCensus RemoteSessionPush red is foreign (green in this worktree); parent-opened raw listener fd inherited into the forked stub child (builder-disclosed seam, benign; same family DescriptorInheritanceGuard rosters — owner glance if hardened).
- E646 clean.
