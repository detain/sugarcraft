# Step 10.34 — candy-git-server git:// daemon + real daemon mode

**Source:** `leftover_updates_later.md` candy-git-server §3.3 + §3.4
**Branch:** `ai/serve-git-daemon-real-daemon`

## Deliverable

- `git://` daemon — implements the git-daemon protocol.
- Real daemon mode — proper double-fork, PID file, signal handling
  (HUP reloads, TERM clean shutdown).

## Files

**Create:** `candy-git-server/src/GitDaemon/Server.php`,
`Daemon/Daemonize.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-git-server && vendor/bin/phpunit` green.

## Notes

- The **Interactive SSH TUI** (research §3.1) is the marquee soft-serve
  feature and gets its own milestone plan via step 11.02
  (`plans/candy-git-server-tui.md`). It is NOT in scope here.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
