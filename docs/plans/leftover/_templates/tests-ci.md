# Tests + CI substep — between-step substep

Verify that the just-completed step (and its fix follow-up, if any)
have proper test coverage and CI integration. Ship a PR only if
changes are needed.

Read `_templates/subagent_brief.md` and `updates.md` first.

## Your task — in order

### 1. Test coverage

For every new public method introduced in the touched lib(s):

- Does at least one test exercise it?
- Does the test name describe behaviour, not implementation?
- Is the test in the right namespace mirroring `src/` → `tests/`?
- Does the lib's golden suite cover the visible output (snapshot
  test) if the change is visual?

If gaps exist, write the missing tests. Use the same conventions as
the rest of the lib's test tree.

### 2. PHPUnit configuration

If the lib's test directory grew a new subdirectory (e.g. `Integration/`
or `Posix/`), confirm `phpunit.xml` picks it up via the existing
`<testsuites>` / `<source>` blocks. Update if needed.

### 3. CI workflows

Check `.github/workflows/`:

- **`ci.yml`** — auto-discovered via `scripts/affected-libs.php`. You
  rarely need to touch this. Run `php scripts/affected-libs.php` and
  confirm the touched lib appears in the output for the relevant
  trigger files.
- **`vhs.yml`** — **hand-maintained** matrix at the `all=(...)` array.
  If the step added or renamed a `.vhs/*.tape` file, the matrix MUST
  be updated or the GIF will never re-render. (CLAUDE.md "Gotchas".)
- **`pty-matrix.yml`** — only relevant if the step touched candy-pty.
- **`scripts/affected-libs.php`** — `WINDOWS_LIBS` / `MACOS_LIBS` pools
  are hand-maintained. If the step touches an OS-specific code path
  for the first time, add the lib to the relevant pool. **Skip macOS
  additions for now** per user instruction (deferred phase).

### 4. Path-repo closure

Run `php tools/check-path-repos.php`. If it reports a missing entry
for a dep your step added, copy the path-repo block from
`sugar-charts/composer.json` (canonical) into the consuming lib's
`composer.json` `repositories[]`.

### 5. Caliber sync

Confirm `caliber refresh` runs cleanly (the pre-commit hook does this
automatically; you don't need to invoke it). If it errors, that's a
Blocker — append to `updates.md` and stop.

### 6. Local smoke run

For every touched lib:

```bash
cd <lib> && composer install --quiet && vendor/bin/phpunit
```

Use the PHPUnit watchdog (see brief) for candy-pty / candy-wish /
candy-vcr.

## Output

- **Nothing to change:** append `tests-ci for step <ID> · clean` under
  Done log. Report to supervisor.
- **Changes needed:** ship as one PR on a `ai/<original-slug>-tests-ci`
  branch. Same ship-as-you-go cadence. Append
  `tests-ci for step <ID> · <PR#> · <one-line summary>` to Done log.

## Things that are NEVER this substep's job

- Adding new features beyond the test/CI infrastructure.
- Rewriting existing tests that already pass.
- Refactoring the touched lib's source files.
- Adding test coverage for **other** libs not changed by the step.

## Process reminders (every subagent, every time)

- `unset GITHUB_TOKEN` **before every** `gh` invocation. Always. No
  exceptions.
- The full cycle ends on `master`: branch → commit → push →
  `unset GITHUB_TOKEN && gh pr create` →
  `unset GITHUB_TOKEN && gh pr merge <N> --merge --delete-branch` →
  `git checkout master && git pull --ff-only`. Even if there are no
  changes to ship, confirm `git status` shows clean working tree on
  master before stopping.
