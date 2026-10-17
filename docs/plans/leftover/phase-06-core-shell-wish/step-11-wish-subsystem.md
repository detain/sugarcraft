# Step 06.11 — sugar-ssh-server Subsystem middleware

**Source:** `leftover_updates_later.md` sugar-ssh-server §4.4 P2
**Branch:** `ai/wish-subsystem`

## Deliverable

`Subsystem` middleware so users can register `sftp`, `rsync`, custom
binary protocols, etc. against an SSH server.

## Files

**Create:**

- `sugar-ssh-server/src/Middleware/Subsystem.php` — middleware that, on
  `subsystem <name>` request from the client, dispatches to a
  registered `SubsystemHandler`.
- `sugar-ssh-server/src/Middleware/Subsystem/SubsystemHandler.php` —
  interface (`handle(Context, Session): void`).
- `sugar-ssh-server/src/Middleware/Subsystem/SftpStub.php` — stub showing
  the integration pattern. Does NOT implement SFTP; just demonstrates
  the wiring.

**Tests:**

- `sugar-ssh-server/tests/Middleware/SubsystemTest.php` — request the stub,
  assert dispatch.

## Acceptance

- `cd sugar-ssh-server && vendor/bin/phpunit --filter Subsystem` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
