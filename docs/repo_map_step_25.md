# Step 25 — God-class refactors (super-candy Manager + candy-query App)

**Branch:** `ai/god-class-builders`
**Depends on:** step-09 (candy-core UndoActionType + ProgressReporter)
**Blocks:** —

## Goal

Refactor the two god classes: `super-candy/src/Manager.php` (915 lines, 15-param ctor — also uses `str_starts_with($desc, 'delete ')` string-based undo routing) and `candy-query/src/App.php` (12-arg ctor). Both get builder patterns; super-candy switches to `UndoActionType` enum; candy-query gets `DatabaseInterface` extraction (§345.7) unblocking MySQL/Postgres drivers.

Reference: §345.7 (DatabaseInterface), §345.8 (UndoActionType), §387.10 (god classes), §103.5 (builder pattern), §304 (super-candy specifically).

## Files expected to be created

- `super-candy/src/Manager/ManagerBuilder.php` — fluent builder with `with*()` per param, `build(): Manager`.
- `super-candy/src/Undo/UndoActionFactory.php` — replaces string-based detection; uses `UndoActionType` enum from candy-core.
- `candy-query/src/Db/DatabaseInterface.php` — interface (7-method per §270).
- `candy-query/src/Db/SqliteDatabase.php` — current concrete impl (extracted from existing App's direct sqlite use).
- `candy-query/src/App/AppBuilder.php` — fluent builder for App.

## Files expected to be modified

- `super-candy/src/Manager.php` — keep 15-param ctor for back-compat, add `static builder(): ManagerBuilder`. Internal string-based undo detection replaced with UndoAction enum routing.
- `super-candy/composer.json` — confirm depends on candy-core (for UndoActionType). Add via `path-repo-closure` if missing.
- `candy-query/src/App.php` — keep 12-arg ctor; add `static builder()`. Replace direct sqlite usage with `DatabaseInterface`.
- `candy-query/composer.json` — no new deps unless `path-repo-closure` says so.

## Acceptance criteria

- [ ] `Manager::builder()` returns fluent builder; round-trips to identical Manager instance.
- [ ] super-candy's undo routing uses `UndoActionType` enum (no `str_starts_with` on labels).
- [ ] Existing super-candy tests pass.
- [ ] `App::builder()` returns fluent builder.
- [ ] `DatabaseInterface` interface defined with 7 methods (per repo_map §270 — likely connect/query/exec/escape/lastInsertId/quote/close — coder verifies actual API by reading existing App.php).
- [ ] `SqliteDatabase implements DatabaseInterface`.
- [ ] App can be constructed with any `DatabaseInterface` implementation (smoke-test with a fake in-memory impl).
- [ ] No semver break: existing public API surface unchanged.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **Delegate to an Explore subagent**: "Read `super-candy/src/Manager.php` (915 lines) and `candy-query/src/App.php`. For Manager: list all 15 ctor params with types + defaults; find all uses of `str_starts_with($desc, ...)` for undo routing. For App: list all 12 ctor params; identify the DB API surface actually used (the methods that will become DatabaseInterface)."
2. **Block on the report.**
3. **super-candy Manager**:
   - Builder per param.
   - Replace `str_starts_with($desc, 'delete ')` etc. — route undo by `UndoAction::type` instead. Migration: at action recording time, capture the action type as `UndoActionType` and stash the original description as a label.
4. **candy-query DatabaseInterface**:
   - Define minimal interface from the methods App actually calls on its DB.
   - Extract current sqlite usage into `SqliteDatabase implements DatabaseInterface`.
   - App takes a `DatabaseInterface` parameter via builder.
5. **App builder**: same pattern as Manager builder.
6. Run phpunit + check-path-repos.

## Tester brief

- Manager: builder round-trip with 15 params; existing tests green; undo-by-enum test for each `UndoActionType` case.
- App: builder round-trip; existing tests green; smoke test with a `FakeDatabase implements DatabaseInterface` in-memory impl.

## Scribe brief

- super-candy README: add `## Constructing Manager` with builder example; deprecation note on direct ctor for new code.
- candy-query README: add `## DatabaseInterface` section explaining how to drop in MySQL/Postgres impls in future.
- CALIBER_LEARNINGS in both: god-class history; "any future Manager ctor param goes through the builder, not the direct constructor".

## Ship brief

- **PR title**: `super-candy + candy-query: builders + UndoActionType + DatabaseInterface`
- **PR body**:
  ```
  ## Summary
  - super-candy/Manager::builder() relieves 15-param ctor (kept for back-compat).
  - super-candy undo routing uses UndoActionType enum (candy-core) — no more str_starts_with string-prefix detection.
  - candy-query/App::builder() relieves 12-arg ctor.
  - candy-query/DatabaseInterface extracted with SqliteDatabase as the current impl; MySQL/Postgres drivers now unblocked for future work.

  ## Test plan
  - [x] vendor/bin/phpunit in super-candy + candy-query (≥95% each)
  - [x] Builder round-trip tests
  - [x] DatabaseInterface fake-impl smoke test
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_25.md, docs/repo_map_update.md §345.7, §345.8, §387.10
  ```
- Commit subject: `super-candy + candy-query: builders + UndoActionType + DatabaseInterface`.
