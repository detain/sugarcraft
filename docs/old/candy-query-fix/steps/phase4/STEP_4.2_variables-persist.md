# STEP 4.2 — Variables 8.0 persistence (SET PERSIST / PERSIST_ONLY / RESET PERSIST)

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §C (invalid `SET GLOBAL PERSIST`), §D (Variables).

## Why
`VariableEditor::editGlobalPersist()` emits `SET GLOBAL PERSIST <name> = ?` — **invalid MySQL
syntax**. Valid forms are `SET PERSIST`, `SET PERSIST_ONLY`, `RESET PERSIST`. The persist path is
also unreachable (the page hardcodes `edit()`), and there's no Persisted category/columns
(`performance_schema.persisted_variables`).

## Goal
- Correct, version-gated (≥8.0) persistence: `SET PERSIST` / `SET PERSIST_ONLY` / `RESET
  PERSIST`, reachable from the page.
- A "Persisted" category sourced from `performance_schema.persisted_variables`.

## Files
- `src/Admin/Variables/{VariableEditor,VariablesPage,Catalog}.php`.
- `tests/Admin/Variables/*`.

## Do
1. Remove `editGlobalPersist` (`SET GLOBAL PERSIST` is invalid). Add `persist()` (`SET PERSIST
   <name> = ?`) and `persistOnly()` (`SET PERSIST_ONLY <name> = ?`) and `resetPersist(?name)`
   (`RESET PERSIST [<name>]`), all prepared/escaped and **gated to server ≥8.0** (version from
   `ServerContext`).
2. Wire a persist toggle/key in `VariablesPage::update()` (building on STEP 4.1's dialog): edit
   can choose GLOBAL vs PERSIST vs PERSIST_ONLY; offer PERSIST_ONLY as the 1238 fallback.
3. Add a "Persisted" category populated from `SELECT * FROM performance_schema.persisted_variables`
   (via the async cache path), shown only on ≥8.0.

## Acceptance criteria
- [ ] No `SET GLOBAL PERSIST` anywhere; `SET PERSIST` / `SET PERSIST_ONLY` / `RESET PERSIST`
      generated correctly and gated to ≥8.0 (assert via fake context + version).
- [ ] Persist actions reachable from the page; PERSIST_ONLY offered on 1238.
- [ ] Persisted category appears only on ≥8.0.
- [ ] Full suite green.

## Out of scope / defer
- Metadata catalog expansion → STEP 4.3.
