# Step 10.29 — candy-code session persistence (JSONL save/restore)

**Source:** `leftover_updates_later.md` candy-code H1
**Branch:** `ai/crush-session-persistence`

## Deliverable

`SessionManager` saves chat history to JSONL on every turn; restores
on startup. Atomic save via tmp+rename (Homedash pattern).

## Files

**Create:** `candy-code/src/SessionManager.php`, `Persistence/JsonlStore.php`.

## Tests

- `candy-code/tests/SessionManagerTest.php` — round-trip across
  process restart.

## Acceptance

- `cd candy-code && vendor/bin/phpunit --filter Session` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
