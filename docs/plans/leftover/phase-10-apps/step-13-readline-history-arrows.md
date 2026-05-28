# Step 10.13 — sugar-readline history persistence + ↑↓ navigation

**Source:** `leftover_updates_later.md` sugar-readline HIGH
**Branch:** `ai/readline-history-arrows`

## Deliverable

- `FileHistory` / `InMemoryHistory` implementations.
- ↑/↓ key bindings navigate history.

## Files

**Create:**
- `sugar-readline/src/History/HistoryInterface.php`.
- `sugar-readline/src/History/FileHistory.php`.
- `sugar-readline/src/History/InMemoryHistory.php`.

**Modify:** main `Readline.php` — key bindings.

## Tests

- One per impl.

## Acceptance

- `cd sugar-readline && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
