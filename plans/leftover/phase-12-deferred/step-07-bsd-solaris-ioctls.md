# Step 12.07 — BSD / Solaris / AIX / OpenBSD ioctl constants (DEFERRED)

**Source:** `leftover_updates.md` deferred section
**Branch:** `ai/non-darwin-bsd-ioctls`
**Deferred per user instruction.**

## Deliverable

`SizeIoctl.php` and `PosixTermios.php` know about Linux + Darwin only.
Add per-platform ioctl numbers for FreeBSD, OpenBSD, NetBSD, AIX,
Solaris when a user reports needing one. Reference: `nix` crate's
per-platform `ioctl.rs` files.

## Files

**Modify:** `candy-pty/src/SizeIoctl.php` + `PosixTermios.php` to
branch on `PHP_OS_FAMILY` for the additional platforms.

## Acceptance

- A user report or community PR triggers the work; until then it's
  speculative.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
