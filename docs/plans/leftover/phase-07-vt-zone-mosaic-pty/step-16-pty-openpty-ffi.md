# Step 07.16 — sugar-pty openpty() FFI binding (Darwin)

**Source:** `leftover_updates_later.md` sugar-pty research §4.1.1
**Branch:** `ai/pty-openpty-ffi`

## Deliverable

Add an `openpty()` FFI binding. Today sugar-pty uses
`posix_openpt + grantpt + unlockpt + ptsname_r` quartet. `openpty(3)`
is a single call wrapper that some BSDs (macOS, FreeBSD) provide.
Adds an alternate path that may resolve the macOS arm64 ABI issues
(step 12.01).

## Files

**Modify:**

- `sugar-pty/src/Libc.php` — extend cdef with `openpty`.
- `sugar-pty/src/Posix/PosixPtySystem.php` — try `openpty()` first
  on Darwin; fall back to the existing quartet.

## Tests

- `sugar-pty/tests/Libc/OpenptyTest.php` — verifies the symbol resolves
  and returns a valid master/slave pair.

## Acceptance

- `cd sugar-pty && vendor/bin/phpunit --filter Openpty` green.

## Notes

- Use the PHPUnit watchdog (see brief).
- Some libc implementations bury `openpty` in `libutil` (Linux) — handle
  both load paths.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
