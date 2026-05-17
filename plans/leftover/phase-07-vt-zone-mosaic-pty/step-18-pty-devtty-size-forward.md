# Step 07.18 — candy-pty /dev/tty size-forwarding variant

**Source:** `leftover_updates_later.md` candy-pty research §4.2.1
**Branch:** `ai/pty-devtty-size-forward`

## Deliverable

Current `SignalForwarder::attachSigwinch` writes to a PTY master.
Add a variant that writes the host's terminal size to a `/dev/tty`
fd — useful for non-PTY workflows where the consumer holds a regular
TTY.

## Files

**Modify:**
- `candy-pty/src/SignalForwarder.php` — add
  `attachSigwinchToFd(int $fd, ?\Closure $callback = null)` that
  resizes via `SizeIoctl` against a raw fd rather than a MasterPty.

## Tests

- `candy-pty/tests/SignalForwarderDevTtyTest.php` — open `/dev/tty` if
  available, attach, send SIGWINCH; assert size is reported.

## Acceptance

- `cd candy-pty && vendor/bin/phpunit --filter "SignalForwarderDevTty"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
