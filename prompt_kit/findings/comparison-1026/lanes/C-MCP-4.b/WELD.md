# C-MCP-4.b — WELD record (W17 tick, 2026-10-28)

Lane: runtime `/mcp enable|disable <server> [--persist]` — session-scoped exact-name MCP routing.
Welded onto `c569b41e6` (W17 lane-1 weld atop 96d36db45 'weld: close W14').

## Pick
- master commit: `6beef34f4c8e11b67c1253dd6b99f67d13855230` (lane tip `796756612e591daaadd5cbbe5f86bb812f98190a`, base e4a705244-era).
- 12 files, +892/−21. Auto-merge clean (lang/en.php, docs/COMMANDS.md, docs/MCP.md all merged without conflict). Zero trailers; %ae/%ce both detain@interserver.net verified separately.
- Patch-id NOT identical to the lane commit (e53cafba vs d767d319): 26 divergent welds above the shared base shifted hunk context lines. Content-equivalence PROVEN instead: `enableForSession` (McpClient.php), `persistSessionToggle` (McpAuthCommand.php, 3 writeUserConfig sites :366/:368/:386), census row `'Commands/McpAuthCommand.php::persistSessionToggle' => 3` (SettingsWriterCensusTest), both NEW test files on disk, and the cured `disable.done` clause present with the FALSE preset-contrast clause absent (count 0) — see review history below.

## Reviewer history / duplicate-r3 disagreement (recorded per lead)
- REVIEW-2 (tip 2cfb83e04): FINDINGS — 1 MAJOR (SettingsWriterCensusTest needed explicit row `'Commands/McpAuthCommand.php::persistSessionToggle' => 3` + adjudication comment; census counts 3 write sites :366/:368/:386; :284/:343 are docblock mentions only) + 3 MINOR. All cured in-lane.
- Two r3 reviewers ran. ses_e7cc52312 returned CLEAN; ses_e7ccb9189 returned FINDINGS (REVIEW-3.md: 1 MINOR — the r2 lang cure shipped one FALSE clause: `disable.done` claimed "a preset-attached agent stops seeing them from that same turn", but `McpListTool`/`McpToolShape::scopedTo()` filter ONLY by preset allowlist, never by the session-disabled set). Disk adjudication VINDICATED the FINDINGS reviewer: the contrast was code-false. Cure = amend to tip 796756612, a ONE-LINE truthful rewrite ("the model-facing roster is not re-derived (cache law), so its tools stay listed until restart"), diff 1fbbaddc0..796756612 = exactly that line (verified pre-pick). No test byte-pinned the old sentence; zero test flips.

## Gates (merged tree, cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'McpRuntimeDisable|McpAuthCommandEnableDisable'`: OK — Tests: 12, Assertions: 53 (EXACT per lead). junit /tmp/opencode/cc-cmp-mcp4b-weld.xml.
- `--filter 'SettingsWriterCensusTest'`: OK — Tests: 5, Assertions: 4762 (reviewer era 4746A; +16A = tree-scan wobble from intervening foreign welds, same shape as W17 lane-1 census runs).
- `--filter 'DocFigureProseDriftTest'`: OK — Tests: 91, Assertions: 4031 (era 4027A, +4A wobble; lane's in-step doc-arm edits hold).
- `--filter 'MCP|Commands'`: 2783T/20310A, 1F, 1S. The 1F is MenuBarLabelDerivationTest::testEverySlashOnlyRowReadsAsThisTable — Actual gains `'pr' => 'Push the current'`: foreign standing red of C-AGT-6.d era (roster row owed by the pr-command lane's closeout; test last touched 934488495 C-AGT-5.b). This pick touches zero MenuBar/Palette files (grep of fileset = 0). NOT attributable — report-only per lead.
- Regen riders: NONE needed — all four `--check` arms rc=0 "up to date" (gen-command-docs, gen-tool-docs, gen-settings-doc, gen-protocol-schema). Lane file-set has zero src/Protocol/** and zero web; census-guarded docs (COMMANDS.md row, MCP.md paragraphs) already in-step.

## Durations
Separate scripts commit `f024af640ac276eae9bdb581f4a71034500e518d`: 2 rows from junit sums — McpAuthCommandEnableDisableTest 1.614/7, McpRuntimeDisableTest 1.176/5 (col3 = testcase COUNT 7/5, not assertions 33/20); desc-invariant insertions at lines 192/251; asc-violations 4==4; tsv 1535 → 1537.

## Carries to closeout
- Suite-figure re-pin carry: this lane adds +12T/+53A live (2 new files); combined with lane C-EXT-4.b (+2T/+17A) this tick's total pending re-pin is +14T/+70A over the pinned pair (staleness pair reds at full-battery time are expected; welder never edits suite-figure.json).
- Pre-existing ~66-file durations-row backlog (other lanes' new test files without rows) — closeout duty, NOT C-MCP-4.b-attributable.
- Foreign standing 1F above (`pr` MenuBar roster row) + 1S (in-filter skip, pre-existing shape).

## Absorption + cleanup (post-weld stamp)
- Weld commit: `0575e7c62351c7ac64cde2f15f46834a51eb2f7d` (plan §4 ✅ mark, 6 evidence files incl collected REVIEW-2.md, WELD.md, run-state welded=159).
- `git cherry master cc/C-MCP-4.b` → `+ 796756612` = patch-id false-positive from context drift (stable patch-ids 91a65e06 vs f7704259). CONCLUSIVE content-equivalence proof: mechanical walk of the full base(e4a705244)..tip(796756612) diff — 12 files with additions, EVERY non-blank added line present verbatim in the master blob (missing lines: 0). Absorbed.
- worktree `/home/sites/cc-cmp-C-MCP-4.b` removed; branch `cc/C-MCP-4.b` deleted (was 796756612). `git worktree list` → main + foreign (cc-cmp-C-AGT-5.c @a812ea659, cc-cmp-C-MCP-3.b @58966feb9) only.
