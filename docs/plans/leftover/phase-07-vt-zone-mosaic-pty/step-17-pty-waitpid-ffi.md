# Step 07.17 — sugar-pty waitpid() FFI binding

**Source:** `leftover_updates_later.md` sugar-pty research §4.1.2
**Branch:** `ai/pty-waitpid-ffi`

## Deliverable

Add a `waitpid()` FFI binding that replaces the 10ms `proc_get_status`
poll in `Posix/ChildPollTrait.php` with a non-blocking syscall. Faster
process-exit detection for ChildLifecycle.

## Files

**Modify:**

- `sugar-pty/src/Libc.php` — extend cdef with `waitpid`.
- `sugar-pty/src/Posix/ChildPollTrait.php` — use `waitpid(pid, &status,
  WNOHANG)` first; fall back to `proc_get_status` if FFI unavailable.

## Tests

- `sugar-pty/tests/Posix/ChildPollWaitpidTest.php` — assert <2ms
  detection vs the 10ms poll baseline.

## Acceptance

- `cd sugar-pty && vendor/bin/phpunit --filter Waitpid` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
