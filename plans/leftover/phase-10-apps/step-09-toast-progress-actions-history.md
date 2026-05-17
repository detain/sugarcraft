# Step 10.09 — sugar-toast progress + action buttons + history log + animations

**Source:** `leftover_updates_later.md` sugar-toast P3-P4
**Branch:** `ai/toast-progress-actions-history-anims`

## Deliverable

- Progress toasts (embedded progress bar).
- Action buttons (clickable callbacks).
- History log of dismissed toasts.
- Fade animations (consume honey-bounce `CubicBezier` from step 09.17).

## Files

**Modify:** `sugar-toast/src/Toast.php`, `Action.php` (new),
`HistoryLog.php` (new).

## Tests

- One per feature.

## Acceptance

- `cd sugar-toast && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
