# STEP 4.1 — Variables edit dialog + dynamic-vs-editable

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §C, §D (Variables).

## Why
`VariablesPage::handleEdit()` has no value-input dialog, so it issues a live `SET GLOBAL <var> =
<current value>` (a pointless privileged write of the var to itself) and mutates `$this` in
place (immutability violation). It also gates only on `editable`, conflating "editable" with
"runtime-dynamic" — static vars (e.g. `innodb_log_file_size`) hit error 1238.

## Goal
- A proper value-input flow (TextInput dialog) before any `SET`.
- No self-write; immutable `update()`.
- Distinguish runtime-dynamic from editable; graceful 1238 handling (offer PERSIST_ONLY in 4.2).

## Files
- `src/Admin/Variables/{VariablesPage,VariableEditor,VariableMetadata}.php`.
- `tests/Admin/Variables/{VariablesPageTest,VariableEditorTest}.php`.

## Do
1. Add an edit dialog: enter a new value via `Forms\TextInput`, confirm to `SET GLOBAL <name> =
   ?` (prepared value, backtick-escaped identifier — already done correctly in `edit()`), cancel
   to abort. Drive it through `update()` returning a NEW page (no `$this->x =`).
2. Only `SET` when the new value differs from the current; never re-write the current value.
3. Add a `dynamic` notion (metadata flag or runtime check) distinct from `editable`; only offer
   inline `SET GLOBAL` for dynamic vars. On error 1238, surface a clear message (and, after 4.2,
   offer PERSIST_ONLY).
4. Keep the `[rw]` indicator driven by `editable`/`dynamic` from the (now-wired, STEP 1.2)
   catalog.

## Acceptance criteria
- [ ] Editing prompts for a value; confirming issues a single prepared `SET GLOBAL` with the NEW
      value; cancel makes no write (assert via fake context capturing executed SQL).
- [ ] `update()` returns a new model; no in-place mutation.
- [ ] Static var edit does not silently no-op — it reports/handles 1238.
- [ ] Full suite green.

## Out of scope / defer
- PERSIST / PERSIST_ONLY / RESET PERSIST → STEP 4.2. Catalog data expansion → STEP 4.3.
