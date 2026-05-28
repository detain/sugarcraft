# Step 07.17 — candy-pty waitpid() FFI binding

**Source:** `leftover_updates_later.md` candy-pty research §4.1.2
**Branch:** `ai/pty-waitpid-ffi`

## Deliverable

Add a `waitpid()` FFI binding that replaces the 10ms `proc_get_status`
poll in `Posix/ChildPollTrait.php` with a non-blocking syscall. Faster
process-exit detection for ChildLifecycle.

## Files

**Modify:**
- `candy-pty/src/Libc.php` — extend cdef with `waitpid`.
- `candy-pty/src/Posix/ChildPollTrait.php` — use `waitpid(pid, &status,
  WNOHANG)` first; fall back to `proc_get_status` if FFI unavailable.

## Tests

- `candy-pty/tests/Posix/ChildPollWaitpidTest.php` — assert <2ms
  detection vs the 10ms poll baseline.

## Acceptance

- `cd candy-pty && vendor/bin/phpunit --filter Waitpid` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
