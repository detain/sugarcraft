# Step 10.07 — sugar-toast Middle* positions + allowEscToClose + hasActiveAlert + stack Y-offset fix

**Source:** `leftover_updates_later.md` sugar-toast P1
**Branch:** `ai/toast-positions-esc`

## Deliverable

- `Position::MiddleLeft` / `MiddleRight` / `MiddleCenter` enum cases.
- `withAllowEscToClose(bool)` + `hasActiveAlert(): bool`.
- Stack Y-offset correctness fix.

## Files

**Modify:**
- `sugar-toast/src/Position.php` (enum).
- `sugar-toast/src/Toast.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-toast && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
