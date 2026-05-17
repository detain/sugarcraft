# Step 01.06 — Slim the deprecated candy-pty facades

**Source:** `leftover_updates.md` P4-LO-01
**Branch:** `ai/slim-pty-facades`
**Bundle hint:** standalone

## Deliverable

`candy-pty/src/{Pty,Spawn,Child,Master}.php` are marked `@deprecated`
but still contain non-trivial logic (the EINTR retry loop in
`Pty::read()`, etc.) instead of pure delegation. Reduce each to a 5-line
shim that delegates to the canonical `Posix\Posix*` class.

## Files

**Modify:**
- `candy-pty/src/Pty.php` — every public method delegates to the
  equivalent on `\SugarCraft\Pty\Posix\PosixPtySystem` or
  `\SugarCraft\Pty\Posix\PosixMasterPty`. The `read()` loop lives
  in `PosixMasterPty::read()` already; remove the duplicate body.
- `candy-pty/src/Spawn.php` — delegate to `PosixSlavePty`.
- `candy-pty/src/Child.php` — delegate to `PosixChild`.
- `candy-pty/src/Master.php` — delegate to `PosixMasterPty`. Confirm
  whether Master is needed at all; if no callers, mark for deletion
  in a follow-up.

After the slim, `git diff --stat` should show each facade is ≤30
LOC. Use `final class Foo extends Posix\\PosixFoo {}` where the
public surface is identical.

## Acceptance

- `wc -l candy-pty/src/{Pty,Spawn,Child,Master}.php` totals ≤120 LOC
  across the four files.
- Each facade still has its `@deprecated since v0.x, use <new path>`
  doc-block.
- candy-pty + candy-core + candy-shell + candy-wish + candy-vcr full
  suites green.
- `grep -rn "use SugarCraft\\\\Pty\\\\Pty;\|use SugarCraft\\\\Pty\\\\Spawn;" /home/sites/sugarcraft/*/src`
  — confirm any remaining callers still get correct behaviour (they
  hit the delegated implementation).

## Notes

- The plan says facades ship intact through v1.x. Do not remove them
  in this step. The slim is the prep work for v2.0 deletion.
- Watch for the destructor zombie-reaper in `Child.php` — that lives
  in `ChildPollTrait` now, which `PosixChild` uses. The Child shim
  inherits it transitively.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
