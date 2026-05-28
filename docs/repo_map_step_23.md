# Step 23 — Async consumers onto candy-async

**Branch:** `ai/async-consumers`
**Depends on:** step-08 (candy-async), step-09 (candy-core enhancements — withExceptionHandler), step-15/16 (candy-forms / sugar-prompt migrated to shared foundations)
**Blocks:** —

## Goal

Replace scattered ReactPHP usage in `candy-forms`, `sugar-prompt`, and `candy-core` with `candy-async`'s `Cancellable`, `Subscription`, and `AsyncOps` helpers. Eliminates the "async suggestions without cancellation" gap cited in repo_map.

Reference: §369.6 (consolidate async), §387.5 (ReactPHP reinvention).

## Files expected to be modified

- `candy-forms/composer.json` · `sugar-prompt/composer.json` · `candy-core/composer.json` — add `sugarcraft/candy-async` via `path-repo-closure`.
- `candy-forms/src/` async suggestion code paths → use `CancellationToken` so a typing user can cancel a pending suggestion fetch.
- `sugar-prompt/src/` same.
- `candy-core/src/` `subscriptions()` plumbing → use `Subscriptions::compose()` for atomic disposal.

## Acceptance criteria

- [ ] Async suggestion code in candy-forms / sugar-prompt supports cancellation (when the user types, in-flight suggestion fetches cancel).
- [ ] candy-core's `subscriptions()` lifecycle uses candy-async's Subscriptions composition.
- [ ] No regression on existing async tests.
- [ ] Adds a test demonstrating cancellation: kick off a debounced suggestion fetch, type again before it fires, assert the first fetch was cancelled.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **Reference the audit from step-08's coder** in `docs/repo_map_updates.md` — it should have a per-file roadmap of where ReactPHP usage lives.
2. **path-repo closure**: candy-async to the 3 libs.
3. **candy-forms / sugar-prompt async suggestions**: wrap the suggestion fetch in a `CancellationSource`; when a new keystroke arrives, cancel the old one first.
4. **candy-core subscriptions**: refactor `Program::subscriptions()` return type from `?Cmd` to `?Subscription` (or keep both; provide an adapter). Compose multiple via `Subscriptions::compose()`.
5. **withExceptionHandler integration**: any async error caught becomes a Throwable passed to the Program's exceptionHandler.
6. Run phpunit in all 3 + dependents.

## Tester brief

- candy-forms / sugar-prompt: debounce + cancel test (described above).
- candy-core: subscriptions composed → dispose all atomically.
- Existing async tests still pass.

## Scribe brief

- Each lib's README: cite candy-async in `## Shared foundations`.
- CALIBER_LEARNINGS in each: "Use CancellationToken for any user-cancellable async op. ReactPHP loop is shared — accept LoopInterface, don't construct."

## Ship brief

- **PR title**: `candy-forms + sugar-prompt + candy-core: adopt candy-async`
- **PR body**:
  ```
  ## Summary
  - Async suggestions in candy-forms / sugar-prompt now cancellable via candy-async's CancellationToken.
  - candy-core's subscriptions() lifecycle composed via candy-async's Subscriptions::compose.
  - Eliminates the "async without cancellation" gap.
  - Async error paths flow through Program::withExceptionHandler (step-09).

  ## Test plan
  - [x] vendor/bin/phpunit in candy-forms / sugar-prompt / candy-core (≥95%)
  - [x] New cancellation test for debounce-then-cancel scenario
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_23.md, docs/repo_map_update.md §369.6, §387.5
  ```
- Commit subject: `candy-forms + sugar-prompt + candy-core: adopt candy-async`.
