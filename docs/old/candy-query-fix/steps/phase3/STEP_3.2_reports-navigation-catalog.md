# STEP 3.2 — Reports navigation + catalog completeness + unit toggle

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §D (Reports). **Phase 3 closeout.**

## Why
- `withSelectCategory()`/`withSelectReport()` exist but are never called — the report tree is
  unnavigable; the user is locked to `categories[0]`'s first report. (Key routing from STEP 1.1
  delivers keys to `ReportsPage::update()`; now add the handlers.)
- Both `AdminPane::QueryStats` and `AdminPane::TableStats` map to the same `ReportsPage`
  (`App.php:437`) — ambiguous.
- Catalog `data/sys_reports.json` has 31 reports vs ~35 in Appendix B; category order is
  alphabetical rather than the doc's problems-first ordering.
- Per-column unit toggle (latency us/ms/s, bytes KB/MB/GB) is specced but not wired to a key.

## Goal
- Category/report navigation works via keys.
- The two report panes are disambiguated (distinct report subsets or a single clearly-labelled
  Reports pane).
- Catalog matches Appendix B (~35 reports); curated category order.
- Per-column unit cycling works.

## Files
- `src/Admin/Reports/{ReportsPage,Catalog,ColumnType,UnitFormatter}.php`.
- `data/sys_reports.json`.
- `src/App.php` (`adminPage()` match for QueryStats/TableStats; pane labels).
- `src/Admin/AdminPane.php` (labels/sections).
- Tests under `tests/Admin/Reports/`.

## Do
1. In `ReportsPage::update()` add navigation: move between categories and reports
   (`withSelectCategory`/`withSelectReport`), and a key to cycle a column's display unit
   (`UnitFormatter`). Trigger the async report load (STEP 3.1) on selection change.
2. Disambiguate QueryStats vs TableStats: either give each pane a distinct report category
   subset, or collapse to one labelled "Performance Reports" pane (update `AdminPane` + sidebar
   + digit ordering from STEP 1.2 accordingly). Record the choice as a `NOTE:`.
3. Expand `sys_reports.json` to Appendix B's set (verify the missing views; they degrade
   gracefully when absent via the availability check). Apply a curated category order (problems
   first). If unsure of the exact Appendix-B view list, `RESEARCH NEEDED:` it.
4. Ensure `ColumnType::from()` won't fatal on an unknown type (use `tryFrom` with a fallback).

## Acceptance criteria
- [ ] Keys move category and report selection; selection triggers an async load.
- [ ] No two panes silently map to the same undifferentiated page.
- [ ] Catalog count matches Appendix B; categories ordered deliberately.
- [ ] Column unit cycling changes rendered units (assert via UnitFormatter).
- [ ] Full suite green.

## Out of scope / defer
- Nothing major; `DEFERRED:` any Appendix-B view that can't be confirmed.
