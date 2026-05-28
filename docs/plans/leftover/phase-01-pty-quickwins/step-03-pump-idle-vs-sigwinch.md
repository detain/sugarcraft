# Step 01.03 — Split onIdle vs onSigwinch in PosixPump; de-TODO the stale comment

**Source:** `leftover_updates.md` P2-LO-02 + P6-LO-01 (bundle)
**Branch:** `ai/pump-idle-vs-sigwinch`
**Bundle hint:** these two go together — fixing the split obsoletes the TODO

## Deliverable

`candy-pty/src/Posix/PosixPump.php` currently fires `onSigwinch(0, 0)`
on every `stream_select` idle tick. The synthetic-tick path forces
consumers (candy-vcr `RecordCommand`, etc.) to disambiguate fake
sigwinch from real sigwinch. Split into two distinct hooks.

After the split, the misleading "TODO: recordResize wiring" comment
block at the bottom of `PosixPump.php` (lines ~220-237) can be replaced
with a short "Design note: SIGWINCH detection lives in the consumer"
explanation.

## Files

**Modify:**
- `candy-pty/src/PumpOptions.php` — add `?\Closure $onIdle = null`
  field with paired `withOnIdle()` wither using the `mutate()` helper.
  Keep `onSigwinch` signature `\Closure(int $cols, int $rows): void`.
- `candy-pty/src/Posix/PosixPump.php` — the `$ready === 0` branch
  fires `onIdle()` (not `onSigwinch(0,0)`). `onSigwinch` is now only
  driven by `SignalForwarder` from the consumer side. Strip the
  220-237 comment block; replace with a 3-line design note.
- `candy-pty/src/Contract/Pump.php` — doc-comment mentions both hooks.
- `candy-wish/src/Transport/InProcessTransport.php` — if it passes
  `onSigwinch` for keepalive-ish duties, rewire to `onIdle`.
- `candy-vcr/src/Cli/RecordCommand.php` — verify it already drives
  `onSigwinch` from a real `SignalForwarder` callback; no change
  expected but cross-check.

## Tests

- `candy-pty/tests/Posix/PosixPumpKeepaliveTest.php` — update to use
  `onIdle`.
- `candy-pty/tests/Posix/PosixPumpSigwinchTest.php` — assert
  `onSigwinch` does NOT fire on idle ticks.
- New `candy-pty/tests/Posix/PosixPumpIdleVsSigwinchTest.php` exercises
  both hooks independently; fakes a SIGWINCH via `SignalForwarder`
  and asserts `onSigwinch(cols, rows)` was called with real values.

## Acceptance

- `grep -n "onSigwinch.*(0, 0)" candy-pty/src` returns nothing.
- `grep -n "TODO" candy-pty/src/Posix/PosixPump.php` returns nothing.
- All four candy-pty pump test suites green.
- candy-wish + candy-vcr regression suites green.

## Notes

- `PumpOptions` is a readonly DTO with a private `mutate()` helper
  per CLAUDE.md. Use the same pattern.
- Backwards compat: callers that previously relied on
  `onSigwinch(0, 0)` for idle-tick logic must migrate to `onIdle`.
  Grep across the monorepo: this should only be `candy-wish` and
  `candy-vcr`.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
