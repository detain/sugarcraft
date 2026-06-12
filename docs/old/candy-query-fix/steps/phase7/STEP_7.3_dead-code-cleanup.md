# STEP 7.3 — Retire/wire dead code; ResultTable adapter; layering; ::new()

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §B (phantom classes), §E. **Phase 7 closeout.**

## Why
~12 classes are built + tested but have no production caller. Earlier phases may have wired some
(check `updates.md` for wire-vs-delete `NOTE:`s). Remaining cruft: `ProcessQueryExecutor`
(**writes DB creds to a world-readable temp file** — security), `PostgresDashboardAdapter` (false
"coming soon", superseded by `PostgresWidgetCatalog`), `RawValue` (superseded by `StatusVar`),
`ResultPager` (orphan; `Lang` used only here), unused `Validation\*` validators, `AdminPane::
all()/next()` (second source of truth). `ResultTable` duplicates sugar-table. `MysqlDatabase`
imports the Admin layer (layering inversion). Some factories use `::create()`.

## Goal
A lean tree: every class either wired or deleted (with its test), duplication removed, layering
corrected, conventions met. Decisions driven by `updates.md` notes from earlier phases.

## Files
- Delete-or-wire: `src/Admin/ProcessQueryExecutor.php`, `src/Admin/Dashboard/PostgresDashboardAdapter.php`,
  `src/Admin/Calc/RawValue.php`, `src/ResultPager.php` (+ `src/Lang.php` if it becomes orphaned),
  `src/Admin/Validation/*`, `src/Admin/StatusPoller.php` (if 7.1 retired it), `src/CellEditor.php`,
  `src/SnippetStore.php`, `src/Admin/AdminPane.php` (`all()`/`next()`).
- `src/ResultTable.php` → sugar-table adapter (or fold into the `Table` path).
- `src/Db/MysqlDatabase.php` (remove Admin-layer imports — inject reconnect via a `Db/`-defined
  callback/decorator).
- `src/Db/ConnectionConfig.php` (`create`→`new`), `src/SchemaBrowser.php` (`create`→`new`).
- Delete the corresponding `tests/**` for any removed class.

## Do
1. Read `updates.md` wire-vs-delete notes. For each phantom class: if a clear consumer was
   intended and is cheap to wire, wire it; otherwise delete it **and its test**. **Always delete
   `ProcessQueryExecutor`** (creds leak; superseded by the React connections) unless a note says
   otherwise.
2. `ResultTable`: convert to an adapter emitting sugar-table `Column`/`Row` (executed-query
   path), removing the duplicated sizing/padding/scroll, OR fold query results into the existing
   `Table` path. Keep `CellValue` sanitization.
3. Remove `MysqlDatabase`→`Admin\*` imports; relocate reconnect/sampler wiring behind a
   `Db/`-level seam so the Phase-0 driver doesn't depend on the Admin layer.
4. Rename `::create()` factories to `::new()` (`ConnectionConfig`, `SchemaBrowser`); update callers.
5. Delete `AdminPane::all()`/`next()` (or make `all()` return `self::cases()`).

## Acceptance criteria
- [ ] No `ProcessQueryExecutor`; no class with zero production callers left undecided (each wired
      or deleted-with-test, recorded in `updates.md`).
- [ ] `ResultTable` is an adapter (no duplicate grid engine) or removed.
- [ ] `MysqlDatabase` has no `Admin\*` imports.
- [ ] No `::create()` factories remain.
- [ ] Full suite green (removed-class tests removed cleanly).

## Out of scope / defer
- Anything genuinely ambiguous → `DEFERRED:` with a recommendation for Step 8.1 to adjudicate.
