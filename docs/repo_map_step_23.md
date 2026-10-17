# Step 23 — Async consumers onto candy-async <!-- @rename-keep historical provenance -->

**Branch:** `ai/async-consumers`
**Depends on:** step-08 (candy-async), step-09 (candy-core enhancements — withExceptionHandler), step-15/16 (sugar-forms / sugar-forms migrated to shared foundations) <!-- @rename-keep historical provenance -->
**Blocks:** —

## Goal

Replace scattered ReactPHP usage in `sugar-forms`, `sugar-forms`, and `candy-core` with `candy-async`'s `Cancellable`, `Subscription`, and `AsyncOps` helpers. Eliminates the "async suggestions without cancellation" gap cited in repo_map. <!-- @rename-keep historical provenance -->

Reference: §369.6 (consolidate async), §387.5 (ReactPHP reinvention).

## Files expected to be modified

- `sugar-forms/composer.json` · `sugar-forms/composer.json` · `candy-core/composer.json` — add `sugarcraft/candy-async` via `path-repo-closure`. <!-- @rename-keep historical provenance -->
- `sugar-forms/src/` async suggestion code paths → use `CancellationToken` so a typing user can cancel a pending suggestion fetch.
- `sugar-forms/src/` same.
- `candy-core/src/` `subscriptions()` plumbing → use `Subscriptions::compose()` for atomic disposal.

## Acceptance criteria

- [ ] Async suggestion code in sugar-forms / sugar-forms supports cancellation (when the user types, in-flight suggestion fetches cancel).
- [ ] candy-core's `subscriptions()` lifecycle uses candy-async's Subscriptions composition. <!-- @rename-keep historical provenance -->
- [ ] No regression on existing async tests.
- [ ] Adds a test demonstrating cancellation: kick off a debounced suggestion fetch, type again before it fires, assert the first fetch was cancelled.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **Reference the audit from step-08's coder** in `docs/repo_map_updates.md` — it should have a per-file roadmap of where ReactPHP usage lives.
2. **path-repo closure**: candy-async to the 3 libs. <!-- @rename-keep historical provenance -->
3. **sugar-forms / sugar-forms async suggestions**: wrap the suggestion fetch in a `CancellationSource`; when a new keystroke arrives, cancel the old one first.
4. **candy-core subscriptions**: refactor `Program::subscriptions()` return type from `?Cmd` to `?Subscription` (or keep both; provide an adapter). Compose multiple via `Subscriptions::compose()`.
5. **withExceptionHandler integration**: any async error caught becomes a Throwable passed to the Program's exceptionHandler.
6. Run phpunit in all 3 + dependents.

## Tester brief

- sugar-forms / sugar-forms: debounce + cancel test (described above).
- candy-core: subscriptions composed → dispose all atomically.
- Existing async tests still pass.

## Scribe brief

- Each lib's README: cite candy-async in `## Shared foundations`. <!-- @rename-keep historical provenance -->
- CALIBER_LEARNINGS in each: "Use CancellationToken for any user-cancellable async op. ReactPHP loop is shared — accept LoopInterface, don't construct."

## Ship brief

- **PR title**: `sugar-forms + sugar-forms + candy-core: adopt candy-async` <!-- @rename-keep historical provenance -->
- **PR body**:
  ```
  ## Summary
  - Async suggestions in sugar-forms / sugar-forms now cancellable via candy-async's CancellationToken. <!-- @rename-keep historical provenance -->
  - candy-core's subscriptions() lifecycle composed via candy-async's Subscriptions::compose. <!-- @rename-keep historical provenance -->
  - Eliminates the "async without cancellation" gap.
  - Async error paths flow through Program::withExceptionHandler (step-09).

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-forms / sugar-forms / candy-core (≥95%)
  - [x] New cancellation test for debounce-then-cancel scenario
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_23.md, docs/repo_map_update.md §369.6, §387.5
  ```
- Commit subject: `sugar-forms + sugar-forms + candy-core: adopt candy-async`. <!-- @rename-keep historical provenance -->
