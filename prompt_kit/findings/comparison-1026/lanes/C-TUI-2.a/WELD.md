# C-TUI-2.a — WELD record (2026-10-28, welder ses-run)

- Pick: `e746d3012` -> `ba057cad2` (cherry-pick -x, CLEAN zero conflicts; patch-id d0d5a700d120a79d8791ec35d284911cc851121c
  IDENTICAL lane<->pick; %ae/%ce separate greps 1/1 detain; zero Co-Authored trailers; 5 files +505/-21).
- LIB-LOCAL: sugar-core + sugar-markdown only. NO durations rows (lib tests never in candy-code tsv), NO regen,
  no candy-code/paths in the pick (verified by the file list above).
- Merged gates (plain pipe, env -u LINES -u COLUMNS):
  - sugar-core --filter 'RegexHighlighter|Highlight' -> **OK (80 tests, 165 assertions)** — FIX-1's tip truth.
    BRIEF ERRATUM: dispatch expected 81T/166A (pre-fix r1 shape); fixer deliberately kept the ruby backtick leg
    in ONE method to preserve 80T (FIX-1 finding 2) and run-state carry agrees ("gates core 80T+markdown 81T re-check").
    Disk = 80T/165A EXACT vs FIX-1. report-only, figures honest.
  - sugar-markdown --filter SyntaxHighlighter -> **OK (81 tests, 134 assertions)** EXACT vs both.
  - candy-code smoke --filter 'Highlighter|Markdown': PRE-pick **Tests: 42, Assertions: 135, Failures: 1**, POST-pick
    **identical (42/135/1F)** — the "identical to pre-pick" criterion MET. Both deviations from the brief's 62T/605A
    expected shape recorded report-only:
      (a) the 1F is MouseWiringTest::testInjectedZoneMarkup... data set "assistant markdown" — "unexpected zone id
          registered: ctxmeter". FOREIGN-INHERITED: ctxmeter entered src/Renderer.php at P-CTX.e pick 66559688e
          (welded long before this run); zero ctxmeter touches in either this run's picks; fails 3/3 datasets in
          isolation, green-independent of C-TUI-2.a content (lib-local pick). Not cured per standing-red law —
          flag to lead for a cure-lane roster of MouseWiringTest ownPrefixes vs P-CTX.e meter zone.
      (b) 42T vs 62T filter-shape drift = brief figure stale vs tree (name-matching set changed); 0F delta caused by pick.
- Evidence anomaly (recorded, NOT fabricated): REVIEW-2.md does NOT exist anywhere on disk (main-tree lanes dir,
  worktree copy, /tmp/opencode searched). Lead dispatch states "r2 CLEAN 0C/0M REVIEW-2.md on disk". Disk truth:
  r1 = 0C/0M/1MINOR/1NIT (REVIEW-1.md), FIX-1.md documents both cures landing as the d73f06c61 -> e746d3012 amend,
  and the actual d73..e74 diff = exactly the two test files FIX-1 describes (blob-match). Welded per
  EXT-2.d/W11-cure/C-WEB-3.g blob-match precedent + explicit lead instruction. Lead: re-persist r2 reviewer output.
- Figures carry: lib-local suite counts move sugar-core/sugar-markdown local figures only; candy-code suite-figure
  untouched. NEVER pushed.
- Absorption: git cherry -v master cc/C-TUI-2.a -> '-'; worktree ../cc-cmp-C-TUI-2.a + branch removed.
