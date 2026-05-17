# Step 10.08 — sugar-toast persistent + maxConcurrent + custom alert types

**Source:** `leftover_updates_later.md` sugar-toast P2
**Branch:** `ai/toast-persistent-concurrent`

## Deliverable

- Persistent toasts (don't auto-dismiss).
- `withMaxConcurrent(int)` + overflow enum (drop-oldest / drop-newest /
  enqueue).
- Custom alert types via factory.

## Files

**Modify:** `sugar-toast/src/Toast.php`.

**Create:** `sugar-toast/src/Overflow.php` enum.

## Tests

- One per feature.

## Acceptance

- `cd sugar-toast && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
