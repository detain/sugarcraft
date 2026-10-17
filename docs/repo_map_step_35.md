# Step 35 — candy-codetime + candy-mail + candy-git-server adopt candy-async <!-- @rename-keep historical provenance -->

**Branch:** `ai/async-adopters`
**Depends on:** step-08 (candy-async), step-30 (audit) <!-- @rename-keep historical provenance -->
**Blocks:** —

## Goal

Three I/O-heavy libs adopt candy-async: candy-codetime (JSONL append loop), candy-mail (message posting), candy-git-server (server). Each gains cancellation, retry, and timeout semantics for free. <!-- @rename-keep historical provenance -->

Reference: step-30 audit. Note: candy-codetime's `flock()` issue (§157) is OUT of this plan's scope (deferred follow-up); we don't touch it here. We only add async-side improvements.

## Files expected to be modified

- 3 composer.json — add `sugarcraft/candy-async` via `path-repo-closure`. <!-- @rename-keep historical provenance -->
- candy-codetime async writer (if any) → CancellationToken + retry.
- candy-mail send queue → AsyncOps::withTimeout.
- candy-git-server request handlers → Subscriptions for graceful shutdown.

## Acceptance criteria

- [ ] Each lib's async ops are cancellable + observable via candy-async primitives. <!-- @rename-keep historical provenance -->
- [ ] Existing tests pass.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. Step-30 audit per lib.
2. **path-repo closure**.
3. candy-codetime: wrap any background work in CancellationToken.
4. candy-mail: AsyncOps::withTimeout on send.
5. candy-git-server: Subscriptions for connection cleanup.
6. Run phpunit + check-path-repos.

## Tester brief

- Cancellation paths exercised: kick off, cancel mid-flight, assert clean shutdown.
- Timeout paths: AsyncOps::withTimeout fires on overrun.

## Scribe brief

- READMEs + CALIBER_LEARNINGS each.

## Ship brief

- **PR title**: `candy-codetime + candy-mail + candy-git-server: adopt candy-async` <!-- @rename-keep historical provenance -->
- **PR body**:
  ```
  ## Summary
  - Three I/O-heavy libs adopt candy-async. <!-- @rename-keep historical provenance -->
  - Cancellation, retry, timeout patterns standardised.
  - candy-codetime's flock() issue NOT addressed in this plan (deferred follow-up).

  ## Test plan
  - [x] vendor/bin/phpunit in 3 libs (≥95% each)
  - [x] Cancellation + timeout paths covered
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_35.md, docs/repo_map_update_followups.md
  ```
- Commit subject: `candy-codetime + candy-mail + candy-git-server: adopt candy-async`. <!-- @rename-keep historical provenance -->
