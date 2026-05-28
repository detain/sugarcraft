# Step 12.13 — PtyPool SSH-server profile (DEFERRED)

**Source:** `leftover_updates.md` deferred section
**Branch:** `ai/ptypool-ssh-profile`
**Deferred per user instruction.**

## Deliverable

Current pool is generic. SSH servers have a specific churn profile
(many short-lived sessions, fresh termios snapshot each). Profile
against a `candy-wish` SSH server fixture with N concurrent connections;
tune defaults; expose a `PtyPool::sshProfile(int $maxConcurrent)`
named-constructor.

## Files

**Modify:** `candy-pty/src/PtyPool.php`.

## Tests

- `candy-pty/tests/PtyPoolSshProfileTest.php` — fixture SSH server,
  100 churn rounds, assert no leak.

## Acceptance

- `cd candy-pty && vendor/bin/phpunit --filter PtyPoolSshProfile` green.
- Documented in README with the measured churn baseline.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
