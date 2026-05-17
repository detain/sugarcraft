# Step 01.12 — SignalForwarder ReactPHP loop + SIGHUP + no-ctty tests

**Source:** `leftover_updates.md` CC-LO-03 + CC-LO-04 + CC-LO-05 (bundle)
**Branch:** `ai/pty-signal-tests`
**Bundle hint:** three test files; no source changes

## Deliverable

Three integration tests that have been Risk-flagged but never written:

1. `SignalForwarderReactLoopTest.php` — verify SignalForwarder under
   a ReactPHP loop doesn't double-handle signals. Risk #3 of the
   original PTY plan.
2. `SIGHUPForwardingTest.php` — verify SIGHUP propagates when master
   closes. Currently only `SIGINTForwardingTest.php` exists.
3. `NoControllingTerminalTest.php` — verify processes spawned with
   `controllingTerminal: false` do NOT receive Ctrl-C from master
   (opposite of SIGINTForwardingTest).

## Files

**Create:**
- `candy-pty/tests/SignalForwarderReactLoopTest.php`:
  - Boot a `React\EventLoop\Loop::get()`.
  - Attach `SignalForwarder::attachSigwinch($master, $cb)`.
  - `posix_kill(getpid(), SIGWINCH)`.
  - Run loop for ~50ms.
  - Assert callback fired exactly once.
  - Markup with `markTestSkipped` if `react/event-loop` unavailable.
- `candy-pty/tests/Integration/SIGHUPForwardingTest.php`:
  - Open PTY, spawn `sleep 30` with `controllingTerminal: true`.
  - Close master.
  - Assert child exits within 1s.
  - Use the PHPUnit watchdog wrapper (see brief).
- `candy-pty/tests/Integration/NoControllingTerminalTest.php`:
  - Open PTY, spawn `sleep 30` with `controllingTerminal: false`.
  - Write `\x03` (Ctrl-C) to master.
  - Wait 1s.
  - Assert child still running.
  - Kill with SIGTERM, clean up.

**Modify:**
- `candy-pty/composer.json` — `require-dev` `react/event-loop` for the
  ReactPHP test. Path-repo not needed (it's a Packagist dep).

## Acceptance

- `cd candy-pty && vendor/bin/phpunit --filter "SignalForwarderReactLoop|SIGHUPForwarding|NoControllingTerminal"` green.
- `composer validate` clean.
- All three tests use the watchdog pattern and don't leak processes.

## Notes

- The ReactPHP test is the long-standing Risk #3 from the original
  plan. It should pass — if it fails, that's a real bug and a Blocker.
- Use real PTYs (no mocks) per candy-pty CALIBER convention.
- `markTestSkipped('react/event-loop not installed')` is acceptable on
  CI runners that strip dev-deps, but production CI should install
  dev-deps so the test runs.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
