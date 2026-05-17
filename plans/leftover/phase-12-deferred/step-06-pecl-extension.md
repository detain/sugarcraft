# Step 12.06 — PECL extension `php_pty` (DEFERRED)

**Source:** `leftover_updates.md` deferred section
**Branch:** N/A — separate repo
**Deferred per user instruction.**

## Deliverable

Native PECL extension wrapping `forkpty(3)` / `openpty(3)`. Best
performance, deployment burden (`pecl install php-pty`).
Probably never necessary if FFI continues to work.

## Files

In a separate repo (`detain/php-pty-pecl` or similar).

## Acceptance

- Same `PtySystem` interface; same test suite passes.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
