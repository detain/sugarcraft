# C-TOOL-4.e — WELD record (welder turn, 2026-10-31)

STEP=C-TOOL-4.e ROLE=welder · master before: 895b9d6fd (== lane base, unmoved at dispatch and at pick time — disk-verified).

## Step 1 — Picks
Four explicit picks read from the lane (`git -C /home/sites/cc-cmp-C-TOOL-4.e log --format='%h %s' 895b9d6fd..HEAD`): d5112901b (lsp scope src+lang) / 340876035 (mcp scope src+Bootstrap+ToolSettings+docs+regen+DocFigure widen) / 027ef2464 (server-jail pins incl NEW ServerJailTest.php) / 55a934ef0 (fixer, 2 files). Cherry-picked IN ORDER, NEVER a range, no -x. ALL FOUR LANDED CONFLICT-FREE:
- landed shas (LANDED order, cite these in the §4 mark): **1c138d298** (lsp) / **4664ec680** (mcp) / **2d7110462** (pins) / **0b7805b44** (fix-1).
- law re-derived on LANDED commits: %ae detain COUNT 4 · %ce detain SEPARATE COUNT 4 · Co-Authored-By 0.
- dispatch precondition re-verified pre-pick: `#### C-TOOL-4.e —` present-UNMARKED at plan:3819; zero ✅-prefixed C-TOOL-4.e lines (exact startswith forms).

## Step 2 — Absorption (preliminary)
`git cherry master cc/C-TOOL-4.e` = `-` × 4 (d5112901b/340876035/027ef2464/55a934ef0), ZERO `+`. No conflict resolutions occurred → no content-proof needed. Final proof re-run at close.

## Step 3 — Merged-tree checks
(appended below as run)

## Step 3 — Merged-tree checks (ALL at merged tip 0b7805b44; cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored FULL-Test-suffix stems, all NON-ZERO)
- PAIRING co-run `(BashSandboxTest|BubblewrapHardeningTest)` = **Tests: 39, Assertions: 190 — OK GREEN** (expected shape, cure db1441a61 rides base; no STOP).
- `LayeredSettingsTest::` = **Tests: 99, Assertions: 411, 0F** (cure in force, nothing folded, no settings key added).
- en.php: clean apply (no conflict resolution occurred; git cherry `-`×4). PYTHON dupscan over the key column: 3,967 keys, **0 dups**; all three new keys (sandbox.lsp.not_started / sandbox.mcp.not_started / sandbox.mcp.opt_out_ignored) + settings.sandboxScope.help present. `(CliLangCatalogueTest::|LangParityTest::)` = **Tests: 15, Assertions: 4206 — OK** (r2-exact).
- TWGR/DTH: lane adds ZERO roster rows — per-pick name-diff proves the 18-path span contains no roster files. BARE `TreeWideGuardRosterTest::` = **17T/3922A OK**; BARE `DuplicatedTestHelperDriftTest::` = **14T/204A OK**.
- Family `(ServerJailTest|ScopedRunnerJailTest|LspLauncherTest|LspHandshakeBudgetTest|StdioMcpServerTest|McpTrustPinsTest)::` = **Tests: 64, Assertions: 336 — OK** (r2/FIX-1 byte-exact at merged). `ServerJailTest::` alone = **9T/62A OK** (fixer-tip figure confirmed at merged; SUPERSEDES r1's 8T/57A). `McpClientTest::` = **66T/134A, Skipped: 1 — OK** (class-exact).
- `DocFigureProseDriftTest::` FULL = **92T/4236A OK** (the 3000→3600 bodyExcerpt widen licenses exactly one row — r1's offset measurement).
- Forbidden-path scan of merged span (895b9d6fd..0b7805b44): 18 files, ZERO hits on suite-figure.json / durations.tsv / dist / protocol trio / SERVER.md / generated.ts / run-state / plan files / .opencode.
- Generators --check at merged: gen-tool-docs rc0 · gen-settings-doc rc0 · gen-command-docs rc0 ("up to date" ×3 — NO regen leg needed, zero drift). tools/check-child-lifetimes.php (ROOT tools/) rc0: 61 libs, 33 proc_open sites, 9 findings/9 accounted/0 problems — the StdioMcpServer::start row rides unchanged (zero executable proc_open sites added, per-pick name-diff + r1 machine diff).
- Assertion-drift note: every figure above is 0F; no foreign-red classes re-run here (accepted-foreign roster unchanged-shape; weld carries ~+11T-class: ServerJailTest +9 new file, LspLauncherTest +1, StdioMcpServerTest +2, +1 fixer leg = the +1T/+5A ride the battery re-pin; suite-figure pair NEVER touched per law).

## Step 4 — §4 mark
§4 line 3819 flipped to `#### ✅ 1c138d298,4664ec680,2d7110462,0b7805b44 C-TOOL-4.e — Jail LSP and MCP stdio servers (…)` carrying the loop arc, the FIX-1.md:14 lang-keys-ride-pick-1 TRUTH-NOTE, r2's 9T/62A-SUPERSEDES ruling, the security-posture line, merged-tree figures, and §7 hand-offs LISTED-NEVER-FIXED. Proof (python, landed blob authority): master blob `#### ✅` count 309 -> worktree 310; exactly ONE ✅-prefixed C-TOOL-4.e line; zero unmarked own-headings. cat-file -t commit ×4 + merge-base --is-ancestor vs master YES ×4.

## Step 5 — Commits
