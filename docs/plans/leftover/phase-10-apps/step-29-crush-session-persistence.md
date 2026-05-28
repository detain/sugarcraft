# Step 10.29 — sugar-crush session persistence (JSONL save/restore)

**Source:** `leftover_updates_later.md` sugar-crush H1
**Branch:** `ai/crush-session-persistence`

## Deliverable

`SessionManager` saves chat history to JSONL on every turn; restores
on startup. Atomic save via tmp+rename (Homedash pattern).

## Files

**Create:** `sugar-crush/src/SessionManager.php`, `Persistence/JsonlStore.php`.

## Tests

- `sugar-crush/tests/SessionManagerTest.php` — round-trip across
  process restart.

## Acceptance

- `cd sugar-crush && vendor/bin/phpunit --filter Session` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
