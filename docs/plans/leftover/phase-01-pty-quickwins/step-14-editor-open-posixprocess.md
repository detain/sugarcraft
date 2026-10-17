# Step 01.14 — sugar-core Util/Editor + Util/Open use PosixProcess

**Source:** `leftover_updates.md` CC-LO-07
**Branch:** `ai/editor-open-via-posixprocess`
**Bundle hint:** two small refactors in sugar-core utilities

## Deliverable

`sugar-core/src/Util/Editor.php` and `sugar-core/src/Util/Open.php`
shell out via direct `proc_open` blocks. The PTY consolidation made
`\SugarCraft\Pty\Posix\PosixProcess` the canonical home for non-PTY
subprocess spawning. Migrate both files to use PosixProcess so they
inherit zombie-reap safety, exit-code propagation, and stderr capture
for free.

## Files

**Modify:**

- `sugar-core/src/Util/Editor.php`:
  - Replace the `proc_open` block with `new PosixProcess(...)`.
  - Use `wait()` for blocking semantics; capture exit code.
  - Preserve current public API (return shape, exceptions thrown).
- `sugar-core/src/Util/Open.php`:
  - Same migration — `xdg-open` / `open` / `start` invocations now
    go through PosixProcess.
- `sugar-core/composer.json` — confirm `sugarcraft/sugar-pty` is in
  `require` (yes, from P1.4 of original plan). Verify with
  `tools/check-path-repos.php`.

## Tests

- `sugar-core/tests/Util/EditorTest.php` — verify behaviour parity:
  same exit codes, same stdout/stderr semantics, same exception
  on failure. Use a fixture editor script.
- `sugar-core/tests/Util/OpenTest.php` — same parity check for the
  Open helper.

## Acceptance

- `grep -n "proc_open" sugar-core/src/Util/Editor.php sugar-core/src/Util/Open.php`
  returns nothing.
- `cd sugar-core && vendor/bin/phpunit --filter "Editor|Open"` green.
- Manual smoke: `EDITOR=true Util\Editor::edit($file)` returns exit
  code 0 without hanging.

## Notes

- These are user-facing utilities — preserve every public method's
  signature and return shape.
- PosixProcess gives stderr capture for free. If Editor/Open currently
  discard stderr, that's fine; keep the behaviour. If they
  intentionally surface stderr (Editor specifically), capture it.
- After this lands, `grep -rn "proc_open" sugar-core/src` should only
  flag `Cmd` machinery (intentional) and the test fixtures.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
