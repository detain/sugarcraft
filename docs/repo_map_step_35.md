# Step 35 — sugar-tick + sugar-post + candy-serve adopt candy-async

**Branch:** `ai/async-adopters`
**Depends on:** step-08 (candy-async), step-30 (audit)
**Blocks:** —

## Goal

Three I/O-heavy libs adopt candy-async: sugar-tick (JSONL append loop), sugar-post (message posting), candy-serve (server). Each gains cancellation, retry, and timeout semantics for free.

Reference: step-30 audit. Note: sugar-tick's `flock()` issue (§157) is OUT of this plan's scope (deferred follow-up); we don't touch it here. We only add async-side improvements.

## Files expected to be modified

- 3 composer.json — add `sugarcraft/candy-async` via `path-repo-closure`.
- sugar-tick async writer (if any) → CancellationToken + retry.
- sugar-post send queue → AsyncOps::withTimeout.
- candy-serve request handlers → Subscriptions for graceful shutdown.

## Acceptance criteria

- [ ] Each lib's async ops are cancellable + observable via candy-async primitives.
- [ ] Existing tests pass.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. Step-30 audit per lib.
2. **path-repo closure**.
3. sugar-tick: wrap any background work in CancellationToken.
4. sugar-post: AsyncOps::withTimeout on send.
5. candy-serve: Subscriptions for connection cleanup.
6. Run phpunit + check-path-repos.

## Tester brief

- Cancellation paths exercised: kick off, cancel mid-flight, assert clean shutdown.
- Timeout paths: AsyncOps::withTimeout fires on overrun.

## Scribe brief

- READMEs + CALIBER_LEARNINGS each.

## Ship brief

- **PR title**: `sugar-tick + sugar-post + candy-serve: adopt candy-async`
- **PR body**:
  ```
  ## Summary
  - Three I/O-heavy libs adopt candy-async.
  - Cancellation, retry, timeout patterns standardised.
  - sugar-tick's flock() issue NOT addressed in this plan (deferred follow-up).

  ## Test plan
  - [x] vendor/bin/phpunit in 3 libs (≥95% each)
  - [x] Cancellation + timeout paths covered
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_35.md, docs/repo_map_update_followups.md
  ```
- Commit subject: `sugar-tick + sugar-post + candy-serve: adopt candy-async`.
