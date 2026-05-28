# Step 07.19 — candy-pty public setControllingTerminal extraction

**Source:** `leftover_updates_later.md` candy-pty research §4.3.4
**Branch:** `ai/pty-setctty-extraction`

## Deliverable

`setControllingTerminal(int $fd)` logic currently lives inside
`bin/pty-shim.php` as a private routine. Lift it to a public class
method on `candy-pty` so users can claim a controlling terminal
without running the shim.

## Files

**Create:**
- `candy-pty/src/ControllingTerminal.php` — static class with
  `claim(int $fd): void` (calls `setsid()` + `ioctl(TIOCSCTTY)`).

**Modify:**
- `candy-pty/bin/pty-shim.php` — delegate to `ControllingTerminal::claim`
  so logic lives in one place.

## Tests

- `candy-pty/tests/ControllingTerminalTest.php` — fork a subprocess,
  claim, verify it's the session leader.

## Acceptance

- `cd candy-pty && vendor/bin/phpunit --filter ControllingTerminal` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
