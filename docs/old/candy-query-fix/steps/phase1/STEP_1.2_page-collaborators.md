# STEP 1.2 — Build admin pages with their collaborators; fix pane ordering/help

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §B, §D (Variables), §H3-equivalent (digit order / "1-6" help).

## Why
Pages are constructed with no collaborators, so core surfaces are dead even once keys route
(STEP 1.1): `VariablesPage::new($context)` (`App.php:435`) passes no `Catalog`/`VariableEditor`
→ no category tree, `isEditable()` always false, edits no-op. `ReportsPage::new($context)`
(`:437`) passes no `$db` → it falls back to sync queries (addressed in Phase 3, but it still
needs its runner/db). Also: digit keys map to `AdminPane::cases()` declaration order while the
sidebar renders section-grouped order (Management then Performance) — pressing `4` ≠ 4th visible
row; help text says "1-6" but there are 8 panes.

## Goal
- `VariablesPage` built with `Catalog::new()` + `VariableEditor::new($context, $catalog)`.
- `ReportsPage` built with whatever runner/db it needs to function (async wiring lands in 3.1;
  here just stop passing nothing — give it the collaborators its constructor expects).
- Digit-key selection matches the on-screen sidebar order; help text reflects the real pane count.

## Files
- `src/App.php` (`adminPage()` factory `match` ~430-440; `handleAdminKey` digit mapping ~239-248).
- `src/Renderer.php` (sidebar build `adminPane()` ~314-360; help/status text ~130,154).
- `src/Admin/AdminPane.php` (section grouping `section()`; reconcile with digit order).
- `src/Admin/Variables/{VariablesPage,Catalog,VariableEditor}.php` (constructor signatures).
- `src/Admin/Reports/{ReportsPage,ReportRunner}.php` (constructor signatures).

## Do
1. In `adminPage()`, construct each page with its real collaborators. For Variables: load the
   catalog (`Catalog::new()->load()` or lazy-load inside the page — ensure `loadCategories()`
   and `isEditable()` work) and pass a `VariableEditor`. For Reports: pass the db/runner.
2. Make digit `N` select the Nth pane **as displayed** in the sidebar (section-grouped), not
   `cases()[N-1]`. Build one ordered list used by BOTH the sidebar renderer and the digit
   handler (single source of truth) so they can't drift.
3. Update help/status text from "1-6" to the actual count (or render the digit beside each
   sidebar label so it's self-evident).
4. Immutability + `::new()` conventions throughout.

## Acceptance criteria
- [ ] `VariablesPage` shows categories and reports `[rw]`/editable correctly for a known
      editable var (assert with a loaded `Catalog` + fake context).
- [ ] Pressing digit `N` selects the same pane the sidebar shows at row `N`.
- [ ] Help text no longer claims "1-6" when there are more panes.
- [ ] Full suite green.

## Out of scope / defer
- Reports async routing → Phase 3.1. Reports navigation keys → Phase 3.2. Variables edit dialog
  / persist → Phase 4. Just wire constructors + ordering here. `DEFERRED:` anything bigger.
