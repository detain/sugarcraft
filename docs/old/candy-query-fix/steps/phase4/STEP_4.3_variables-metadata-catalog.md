# STEP 4.3 — Variable metadata catalog (full ~600 vars)

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §D (Variables metadata). **Phase 4 closeout.**
> **This step very likely needs research — see below.**

## Why
`data/variable_metadata.json` has only 73 entries vs ~600 in upstream `wb_admin_variable_list`.
`SHOW GLOBAL VARIABLES` returns 500-600 rows, so the category tree, descriptions, and `[rw]`
indicator are blank for the vast majority. `editable=69/73` is also implausibly high and
conflates editable with runtime-dynamic.

## Goal
A comprehensive `data/variable_metadata.json` (name, description, editable, **dynamic**, groups)
covering the full system + status variable set, with accurate flags.

## Files
- `data/variable_metadata.json`.
- `src/Admin/Variables/{Catalog,VariableMetadata}.php` (extend schema to carry `dynamic`).
- `tests/Admin/Variables/CatalogTest.php`.

## Do
1. **Before coding:** if you do not have the upstream variable list, write
   `RESEARCH NEEDED: full MySQL system/status variable catalog — names, descriptions, editable
   (settable at runtime), dynamic-vs-readonly, category groups; source = mysql-workbench
   wb_admin_variable_list.py / backend/config/gen-opt/mysqld.xml` to `updates.md` and STOP.
   The supervisor will supply `RESEARCH FINDINGS:` and re-spawn you.
2. Extend `VariableMetadata`/`Catalog` to carry a `dynamic` flag distinct from `editable`
   (consumed by STEP 4.1's gating).
3. Generate the JSON from the researched source (normalize `-`→`_` in names). Keep it valid
   JSON; ensure `Catalog::load()` parses it and `categories()` orders sensibly.
4. Spot-check a sample of well-known vars (e.g. `max_connections` dynamic+editable;
   `innodb_log_file_size` editable-but-not-dynamic; `version` read-only) for correct flags.

## Acceptance criteria
- [ ] Catalog has on the order of hundreds of entries (not 73), parses cleanly.
- [ ] `dynamic` distinguished from `editable`; spot-checked vars correct.
- [ ] Category tree populated for the common variables.
- [ ] Full suite green.

## Out of scope / defer
- If the full upstream list can't be reproduced exactly, ship a substantially-expanded subset
  and record `DEFERRED: complete variable_metadata to full upstream set` for later.
