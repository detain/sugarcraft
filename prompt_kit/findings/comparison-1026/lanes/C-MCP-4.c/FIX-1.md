# C-MCP-4.c — fix ledger

(File newly created at round 2 — r1's cures shipped directly in commits eb3a17dcc + 628a5abf3 without a FIX file; REVIEW-2.md carries the r1 ledger. This file records subsequent rounds.)

## review fixes (round 2) — 2026-10-29

Finding A (REVIEW-2.md, MINOR step-local): the BRIEF-promised "a second session's login is independent" pin was absent — all 9 McpLoginServedTest methods were single-session.

Cure: added `McpLoginServedTest::testTwoConcurrentLoginWatchesStayInTheirOwnSessions` — two stubbed children on two server names (`w4ctx`/`w4cty`), watched concurrently for two live hub sessions (`served-two-x`/`served-two-y`), driven through the real `ServerContext::pump()`. Asserts each session's durable notice transcript receives exactly its own url-beat + closing beat (server-specific URL text per session), zero cross-talk in either direction (`assertStringNotContainsString` on the sibling server name over every row), and that both watches retire independently.

Mutation proof: inserting a session-map-ignoring route into `ServedMcpLogins::pollDue()` (`$sessionId = array_values(self::$watched)[0]` after the foreach head — routes every row to the first session) reddened exactly the new test ("session X received exactly its url beat and its closing beat", size 3 vs 2) while all 9 pre-existing single-session arms stayed green. Reverted from a /tmp/opencode cp backup; md5-verified identical (e4c0a30a774ebdc92e96f4da35d5d5fe). The test also covers the pump half (a pump that ignored the row's session id would land rows on the wrong host and trip the same grouping/cross-talk asserts).

Gates (lane tree cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe):
- before: `--filter McpLoginServed|McpLoginFork|McpAuthStore` = 60T/1561A OK (9+17+34, matches REVIEW-2).
- after: same filter = 61T/1605A OK (+43A core + iteration-dependent noticeRows assertNotNull wobble — same shape as the pre-existing pump tests). McpLoginServed alone: 10T/95A OK.
- New test alone: 1T/43A, deterministic across runs.

Harness note (not a lane finding): `scripts/refresh-deps.php --mode=linked` cannot run in this session — the box's composer 2.10.1 segfaults at "Loading composer repositories" even on an empty project in /tmp (strace: SIGSEGV NULL after heap growth, reproducible; pcre.jit/opcache/pcov-off all still crash). Workaround: copied the main tree's linked `candy-code/vendor/` into the lane worktree — composer path-repo symlinks are relative (`vendor/sugarcraft/x -> ../../../x`), so they re-resolve to the lane's own sibling checkouts; the autoloader is `__DIR__`-based. candy-code/composer.json is byte-identical between base 003cb1e4e and master, so the vendor matches the manifest. `vendor/` is gitignored; tree shows only the intended test-file change.

## round-1 findings re-pass (dispatched against REVIEW-1; ran AFTER r2/r3) — 2026-10-29

Spawn digest cited tip `eb3a17dcc` with the completion uncommitted; disk had
already moved on (tip `68d6b28fa`, tree clean, REVIEW-3 CLEAN). Disposition
of each REVIEW-1 finding, disk-verified this pass:

- MAJOR (completion uncommitted; re-issue tip SHA): ALREADY CURED.
  `628a5abf3` carries the full contract surface; diff base→tip = 9 files
  +807/−2 == exactly the BRIEF-declared set; all three commits
  `%ae`/`%ce`=detain, trailers=[]. `68d6b28fa` cat-file-verified (full SHA
  68d6b28fa1e122ff34b314d8d2dba318ab48cb11). NOTE the spawn brief's uncommitted
  file list (Chat.php dispatchMcpLoginFork / NonInteractive.php / PermissionFsm.php
  answerWithMessage / host.mcp_login.* keys / src/MCP/McpLoginFork.php new /
  tests/Tools/) matches NO generation of this step — BRIEF.md names
  `McpAuthHostCommand`/`ServedMcpLogins`/`cmd.mcp-auth.login-*`/
  `tests/Chat/McpLoginForkTest.php`; the brief's list is a stale/foreign
  digest artifact, recorded here so the loop does not chase it.
- MINOR-1 (per-session pin): ALREADY CURED by `68d6b28fa` —
  `McpLoginServedTest::testTwoConcurrentLoginWatchesStayInTheirOwnSessions`
  (REVIEW-3 verified mutation-discriminating). My spawn text said "McpLoginForkTest
  (or McpLoginServed if exists)" — McpLoginServedTest existed and holds it.
- MINOR-2 (rewrite REPORT.md for this generation): DONE THIS PASS. REPORT.md
  still carried the 2ad862baf-era OAuth-redirectUri text (REVIEW-2 had judged
  the finding closed via REPORT-r3.md, but the canonical file was never
  rewritten). Rewritten with this build's surface, true commit chain, review
  history, and fresh gate figures; generation note added so history stays honest.

Fresh gate re-run this pass (lane tree, linked vendor, plain pipe):
'McpLogin' 29T/1449A OK · 'McpLoginServedTest' 10T/95A · 'McpLoginForkTest'
16T/1337A · 'McpLoginFork' 17T/1343A · 'McpAuthStore' 34T/168A · census
five-guard 160T/32322A OK · DocFigureProseDrift 92T/4128A OK · SessionHost|
ServerContext 82T/402A OK · 2 deprecations baseline throughout. Decomposition
finding: the historical "fork 17T" = class(16) + the DescriptorInheritanceGuard
`Support/McpLoginFork.php` arm-row dataset case — no test was lost; r1/r2/r3
figures all reconcile. No Chat/PermissionFsm suites needed (no hunks). Full
suite never run; PtyRunToolTest never touched; forbidden files
(suite-figure.json, durations.tsv, dist) untouched.

No new code commit minted (nothing to commit — chain complete, tree clean);
evidence edits (this file + REPORT.md) are main-tree working-copy, uncommitted,
for the welder/lead to collect with the step.
