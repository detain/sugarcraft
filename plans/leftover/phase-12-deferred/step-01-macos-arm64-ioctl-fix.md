# Step 12.01 — macOS arm64 PTY resize ioctl better fix (DEFERRED)

**Source:** `leftover_updates.md` P5-LO-02
**Branch:** `ai/macos-arm64-ioctl`
**Deferred per user instruction — pause indefinitely until signal.**

## Deliverable

Today `SizeIoctl::setSizeViaLibc` falls back to `stty -f /dev/fd/<fd>`
on Darwin because `ioctl(TIOCSWINSZ)` returns -1 through PHP FFI's
fixed-arg cdef on arm64 (variadic vs fixed-arg ABI mismatch).

Three options (try in order, pick the first that works):

1. Wait for a PHP FFI release supporting `FFI\CData` arrays as
   variadic params (tracked upstream — may be PHP 8.5+).
2. Build a Darwin-only C shim `candy-pty/bin/macos-ioctl-resize`
   that does the syscall; invoke via `proc_open`.
3. Distribute a tiny "resize-ioctl" binary as a Composer
   post-install artifact (Go or Zig static binary per arch).

## Files

Depends on option chosen.

## Acceptance

- `SizeIoctl::setSizeViaLibc` returns true on macOS arm64 without
  shell-out.
- `pty-matrix.yml` job names stop saying "stty fallback" on Darwin.

## Notes

- This is one of the harder items in the rollout. Investigate
  upstream PHP FFI status before committing to options 2/3.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
