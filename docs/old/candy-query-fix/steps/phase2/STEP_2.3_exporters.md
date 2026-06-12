# STEP 2.3 — CSV/SQL exporters: driver-agnostic, correct, injection-safe

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §C (SqlExporter double-quote), §D (CSV). **Phase 2 closeout.**

## Why
- `SqlExporter`/`CsvExporter` are SQLite-only (use `PRAGMA table_info` / `sqlite_master`) despite
  the "driver-agnostic, takes a `DatabaseInterface`" contract — they silently emit empty/broken
  output on MySQL/PG.
- `SqlExporter.php:75` wraps `$db->quote(...)` (already a complete quoted literal) in extra
  `'…'` → `''value''`; every INSERT is malformed.
- `CsvExporter::exportReportResults`/`exportCsv` space-pad fields to column width (invalid CSV)
  and have NO quote/comma escaping and NO formula-injection guard.
- `ReportsPage` bypasses `CsvExporter` with an inline builder whose injection guard is incomplete
  (misses `\t`/`\r`), leaving the dedicated exporter dead.

## Goal
- Exporters work for any `DatabaseInterface` driver.
- Valid RFC-4180 CSV (no width padding) with a correct formula-injection guard.
- `SqlExporter` produces valid INSERTs.
- `ReportsPage` delegates CSV to `CsvExporter` (one implementation).

## Files
- `src/Db/Export/CsvExporter.php`, `src/Db/Export/SqlExporter.php`.
- `src/Admin/Reports/ReportsPage.php` (delegate to CsvExporter).
- `tests/Db/Export/CsvExporterTest.php` (+ a SqlExporter test if missing).

## Do
1. Get column names driver-neutrally (e.g. `SELECT * … LIMIT 0` + `array_keys` of the row shape,
   or via the `SchemaProvider`) — no `PRAGMA`/`sqlite_master`.
2. `SqlExporter`: use `$db->quote($val)` ALONE (it already returns a quoted, escaped literal);
   backtick/double-quote identifiers per driver; emit valid `INSERT INTO … VALUES (…)`.
3. `CsvExporter`: use `fputcsv` (or strict RFC-4180 quoting), NO width padding. Add a formula-
   injection guard: if a cell, after trimming leading whitespace, starts with `= + - @` or a
   `\t`/`\r`, prefix it with `'`. Apply to headers + cells.
4. `ReportsPage::exportToCsv` (or equivalent) delegates to `CsvExporter` instead of building CSV
   inline; remove the duplicate/incomplete inline guard.

## Acceptance criteria
- [ ] Exporters no longer reference `PRAGMA`/`sqlite_master`; work against a fake non-sqlite db.
- [ ] SqlExporter output is valid SQL (no `''value''`).
- [ ] CSV is RFC-4180 (quoted commas/quotes/newlines), no trailing space padding, formula guard
      covers `= + - @ \t \r`.
- [ ] ReportsPage CSV path calls CsvExporter (no inline duplicate).
- [ ] Full suite green.

## Out of scope / defer
- Reports async routing/navigation/catalog → Phase 3.
