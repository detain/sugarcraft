# Step 06.11 — candy-wish Subsystem middleware

**Source:** `leftover_updates_later.md` candy-wish §4.4 P2
**Branch:** `ai/wish-subsystem`

## Deliverable

`Subsystem` middleware so users can register `sftp`, `rsync`, custom
binary protocols, etc. against an SSH server.

## Files

**Create:**
- `candy-wish/src/Middleware/Subsystem.php` — middleware that, on
  `subsystem <name>` request from the client, dispatches to a
  registered `SubsystemHandler`.
- `candy-wish/src/Middleware/Subsystem/SubsystemHandler.php` —
  interface (`handle(Context, Session): void`).
- `candy-wish/src/Middleware/Subsystem/SftpStub.php` — stub showing
  the integration pattern. Does NOT implement SFTP; just demonstrates
  the wiring.

**Tests:**
- `candy-wish/tests/Middleware/SubsystemTest.php` — request the stub,
  assert dispatch.

## Acceptance

- `cd candy-wish && vendor/bin/phpunit --filter Subsystem` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
