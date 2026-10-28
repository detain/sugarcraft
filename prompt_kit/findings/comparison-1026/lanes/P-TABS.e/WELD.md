# P-TABS.e — WELD record (2026-10-28, welder ses-run)

- Pick: `3c173eb10` -> `2a6cd3c1f` (cherry-pick -x onto master 11d272903, CLEAN, zero conflicts;
  Chat.php + lang/en.php auto-merged. patch-id 5c2788a9882391a5b033a0ba71f2626930ae277b IDENTICAL
  lane<->pick. %ae/%ce separate greps 1/1 detain, zero Co-Authored trailers. 10 files +1241/-14.)
- Conflict watch resolved WITHOUT conflict: the lane's two Chat.php hunks (:1476 field docblock,
  :9261 withNoticeDrain) sit entirely below/above C-EXT-2.e pick f63217396's region start (:9719+);
  both intents live on master.
- Durations: scripts commit `26b4eac75` — 3 rows from per-file junit truth at merged tip
  (BackgroundTabStreamingTest 0.270/4T, TabScopedRoutingTest 0.236/8T, TabCloseAndQuitTest 0.228/7T;
  junit A-sums 30+39+37 = 106 matches the combined filter). tsv 1508 -> 1511.
- Merged gates (plain pipe, env -u LINES -u COLUMNS, cwd candy-code, no PtyRunToolTest):
  - --filter 'TabScopedRouting|BackgroundTabStreaming|TabCloseAndQuit' -> **Tests: 19, Assertions: 106** OK EXACT;
  - tests/App dir -> **OK (468 tests, 21283 assertions)** — 468T EXACT; +14A vs FIX-1's 21,269 =
    merged-tree wobble: zero file deltas under tests/App between base and tip (git diff --numstat
    empty), so the drift is tree-scan/census-style assertion re-derivation over the merged src — report-only, 0F;
  - --filter 'KeyBindingDrift' -> **Tests: 222, Assertions: 1800** OK EXACT;
  - --filter 'LangParity|GlobDialect|DuplicatedTestHelperDrift|SymbolCitation' -> **Tests: 44, Assertions: 20373**
    44T EXACT, +113A = tree-scan re-count over merged src (reviewer ran the same filter at 20,260 on the
    pre-merge lane tree). NOTE: the dispatch brief named the four-family filter as
    'DocFigureProseDrift|GlobDialect|...' — disk truth (REVIEW-2 §6, FIX-1) is the LangParity/SymbolCitation
    spelling; brief naming erratum, filter-as-run follows the evidence files.
  - "PHPUnit Deprecations: 2" recurring = standing foreign AccessorDeprecationTest pair (REVIEW-1 §4).
- Evidence completeness: BRIEF/REPORT/FIX-1/REVIEW-1/REVIEW-2 all on disk (main tree); r2 verdict
  CLEAN 0C/0M/0 step-local MINOR. No evidence anomaly for this lane.
- §1.6/§2 carries honored in WELD notes:
  - §6.1 R-MENU-2 conformance: the tab-scoped route returns null Cmd for parked AgentMessageSentMsg —
    menu/palette surfaces keep the single-writer foreground discipline (REVIEW-2 §3 pins parity with the
    fg arm at App.php:2917).
  - drain-shared-truth: Chat.php withNoticeDrain docblock + field docblock + ARCHITECTURE.md now agree
    on workspace-global queue / one-drainer-at-a-time (REVIEW-2 §2 re-derived the invariant against live code).
  - suite-figure carry: +19T/+106A ride the standing staleness pair — §7 closeout re-pin (pinned 25,537
    vs live ~27k), NOT a weld-time edit (lanes/welder do not touch suite-figure.json mid-campaign).
- Absorption: git cherry -v master cc/P-TABS.e -> '-'; worktree ../cc-cmp-P-TABS.e + branch cc/P-TABS.e
  removed after this record. NEVER pushed.
