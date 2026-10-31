# C-MCP-5.a — WELD.md (welder, disk-derived, 2026-10-31)

## Step 1 — picks landed (master 8ed1c78fe, explicit list, never a range)
- `be7c88bd0` → `0f0dc556e` "mcp serve core — stdio JSON-RPC host over the read-only roster" (3 files +690: McpServeHost.php, McpServeRoster.php, McpServeHostTest.php)
- `1c8166eab` → `74c521793` "HOOKS.md PreToolUse dispatch row + verbatim-deny note" (HOOKS.md 7+/5−)
- `8aeae6900` → `7c240a92f` KEPT tests-only review-fix pick (+136/−1, McpServeHostTest.php only)
- Zero conflicts. HOOKS.md had ZERO master drift since base 288b81f11 (byte-matched context); the 3 other paths are adds absent on master. All three landed picks %ae/%ce detain greps = 1/1, zero Co-Authored trailers (`git log --format='%h %ae %ce' -3`).
- Loop files read from disk before welding: REVIEW-1.md (9,808 B, FINDINGS C=0 M=1 MINOR=2 NIT=2), REVIEW-2.md (stat-confirmed **5,786 B**, VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=0). FIX-1.md carries the withdrawn-phantom round-1 opening (historical, preserved verbatim), the real MAJOR-1 fix section, and a ROUND 2-B phantom-refusal — all three sections committed as-is; tip on disk is authoritatively 3 picks.

## Step 2 — merged-tree gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, PtyRunToolTest never entered any filter)
- `--filter 'McpServeHostTest::'` → **17T / 96A OK** (2 pre-existing PHPUnit deprecations) — EXACT match to r2's lane figure (self-contained class, no merged-tree drift).
- `--filter 'DocFigureProseDriftTest::|StderrEmitterCensusTest::|ReadPathCensusTest::|DuplicatedTestHelperDriftTest::|DenialPrefixRosterTest::|GlobDialectDifferentialTest::'` → **250T / 28,559A OK**. Reconciliation: r2's six-class-with-Serve figure 253T/28,432A minus Serve 17/96 = 236/28,336 for these five, plus DuplicatedTestHelperDriftTest (14T in r2's separate run) = 250T exact; +223A is merged-tree walk drift on the guard classes (tree scans see the Serve files + e78d5dc14 roster re-derives) — DISCLOSED, not chased. The r2-predicted adjudication HOLDS: DuplicatedTestHelperDriftTest reads GREEN post-merge (e78d5dc14 cure live on master, disjoint from lane files).
- `--filter 'TreeWideGuardRosterTest'` (BARE name per the `::`-matches-zero trap) → **17T / 3,852A OK** — GREEN post-merge (was 2F/3,846A at lane tip naming ONLY foreign LoginSubcommandTest; cured by e78d5dc14; +6A drift normal).
- `php tools/check-one-type-per-file.php` (repo root) → **62 libs, 3,318 psr-4 files, 0 problems** (includes the 2 new src files, one class each).
- Merged-tree preToolUse re-derivation: master still has exactly 2 src sites outside src/Hooks/ (Chat.php, Runtime.php) — the lane's HOOKS.md row (written for 2+1=3 with its host) matches the merged derivation; DocFigure arm green proves it.
- en.php: lane touches it ZERO — no append-union needed this weld (diff 288b81f11..8aeae6900 -- candy-code/lang/en.php empty). Subcommand roster/built-ins counts: lane adds no verb and no tool; DocFigureProseDriftTest green re-derives the counters on the merged tree.

## Accepted-red survey (all shapes UNCHANGED; adjudicated, not cured)
- ReadmeSuiteFigureDriftTest:: → staleness PAIR 2F: pinned **25,537** vs live **29,151** (lane carries this class's +17T; re-pin is §7/closeout duty, welder never edits suite-figure.json).
- OneSidedHomeSandboxTest:: → 1F at tests/Support/OneSidedHomeSandboxTest.php:435; the 3 surplus offenders (Host/Commands/HelpHostCommandTest.php [c829e0954], Runtime/ToolOutputRedactionTest.php [5c46e4914], Tools/SendMessageCrossSessionTest.php [c5c3d6092]) ALL PREDATE lane base 288b81f11 (verified via git cat-file at both refs); McpServeHostTest does NOT appear in the offender list — this lane adds zero offenders.
- AttachPagingTest:: → 2F, exactly the two standing methods (older-page stitch + no-paging snapshot) — exonerated base-proven red.
- `HelpTest::` (substring-covers both) → 6F: Cli/HelpTest[CANDY_CODE_SERVICE_TIER] 1F + Renderer/KeyHelpTest 5F, plus **Risky: 1** (KeyHelpTest::testItStaysWithinTheTerminalWhileScrolledToTheEnd) — same-family marker, not a failure; 168T/100,487A.
- ApplySettingsTest::|BinCandyCodeDispatchTest:: → 2F: testEveryLiveKeyTheViewCanSaveHasAnApplyArm (the keepWarm arm) 1F + testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions (--worktree, :1893 family) 1F.
- NO new red family. NO accepted red changed shape. Weld proceeds.

## Step 3 — regeneration
- gen-protocol-schema / gen-command-docs / gen-tool-docs / gen-settings-doc, all `--check`: **all up to date, rc 0. ZERO drift → NO regenerate commit.**
- `git diff 288b81f11..HEAD --name-only | grep -c 'src/Protocol/'` = **0** → protocol schema untouched by lane → web chain (npm gen:protocol/build) NOT run, per lead law.
- dist propagation fact (read-only grep, NOT rebuilt here): shipped `candy-code-web/dist/assets/index-CtqEFhtc.js` (the only bundle) contains **3** occurrences of `server.shutdown` (pre-existing from the C-WEB-5.g era; symbol lives in generated.ts :1087/:1774/:1833 as admin-gated roster + type-level entries).

## Step 4 — §4 mark
plan_comparison.md heading flipped by TEXT (was :3607, `#### C-MCP-5.a — \`mcp serve\` core: stdio JSON-RPC host over read-only tools`) to
`#### ✅ 0f0dc556e,74c521793,7c240a92f C-MCP-5.a — ...` + dated WELD comment carrying: r1 FINDINGS→fixes→r2 CLEAN (5,786 B stat-verified); KEPT tests-only pick 8aeae6900;
DocFigure intermediate-red one-commit window as LEAD-RULED weld disclosure (history NOT rewritten); §7 riders (durations row for tests/MCP/Serve/McpServeHostTest.php at live 17T/96A;
suite-figure/README staleness pair — welder never edits lane-forbidden files); WARN NEXT WELDER (base postdates merge — re-derive en.php order + counters + guard families; both cured families must read GREEN);
5.b's NIT-2 wiring obligation (run() accepts any list<Tool> — must pass McpServeRoster::build()). Proof of the mark is taken from the LANDED BLOB's own heading line after commit (no whole-file ✅ greps).
§3 WAVE TABLE UNTOUCHED — W33 needs five more members (C-WEB-5.a never started; C-TOOL-4.b/C-TUI-6.b/C-EXT-6.b/C-EXT-6.c/C-WEB-5.b loops live) — no wave closes here.

## Step 5 — record commit + Step 6 — prune
- (appended post-commit below)

## FINAL RECORD (appended post-commit, same law as weld 8ed1c78fe for C-TUI-6.i)
- Pick landings: be7c88bd0→0f0dc556e · 1c8166eab→74c521793 · 8aeae6900→7c240a92f (master 8ed1c78fe → 7c240a92f).
- Record commit: 2e38ab7d0 "weld: C-MCP-5.a" — 7 paths (plan_comparison.md, lanes/C-MCP-5.a/{REPORT,REVIEW-1,REVIEW-2,FIX-1,WELD}.md, lanes/run-state.json); AE=CE=detain@interserver.net, Co-Authored 0; mark proven from landed blob at :3607; §3 untouched.
- git cherry master cc/C-MCP-5.a: 3× '-', 0× '+' (patch-id absorption; zero conflict resolutions existed). Worktree ../cc-cmp-C-MCP-5.a removed, branch cc/C-MCP-5.a deleted (was 8aeae6900). Five sibling lanes/worktrees COMPLETELY UNTOUCHED.
- No push attempted, no fetch/pull, no caliber, no regen commit (all four generators --check clean), no web chain (zero protocol touches; dist server.shutdown count 3 reported as unchanged read-only fact).
