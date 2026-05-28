# Step 01.11 — tools/check-path-repos.php --fix flag

**Source:** `leftover_updates.md` CC-LO-01
**Branch:** `ai/check-path-repos-fix`
**Bundle hint:** standalone, ~100 LOC

## Deliverable

`tools/check-path-repos.php` currently REPORTS missing path-repo
entries. Add a `--fix` flag that AUTO-FIXES them by copying the
canonical path-repo block from `sugar-charts/composer.json` and
inserting it into the affected `composer.json` `repositories[]`.

## Files

**Modify:**
- `tools/check-path-repos.php` — add `--fix` arg parsing. When set,
  for each `<lib>` with a `sugarcraft/*` require missing a path-repo
  entry, insert:
  ```json
  {
    "type": "path",
    "url": "../<dep-slug>",
    "options": { "symlink": true }
  }
  ```
  Preserve existing JSON formatting (indent matches the surrounding
  file). Use `json_encode` with `JSON_PRETTY_PRINT |
  JSON_UNESCAPED_SLASHES`.

**Create:**
- `tools/tests/CheckPathReposTest.php` — fixture-based test:
  - Set up a temp dir with two libs (lib-a requires lib-b but missing
    the path-repo).
  - Run script with `--fix`.
  - Assert the composer.json was updated correctly.
  - Assert script with no args still detects the same issue without
    fixing.

## Acceptance

- `php tools/check-path-repos.php` (no args) still reports issues
  without modifying anything (idempotent on clean tree).
- `php tools/check-path-repos.php --fix` modifies composer.json files
  and exits 0 if every issue was fixable.
- `php tools/tests/CheckPathReposTest.php` runs cleanly (one-off
  PHPUnit script).
- A `--help` flag prints usage.

## Notes

- Do not run `--fix` on the live monorepo as part of this step; the
  goal is to add the capability, not change current state.
- After this step ships, the supervisor's between-step substeps
  may use `--fix` automatically when adding new deps.
- Be defensive about composer.json structure — `repositories` may be
  an object keyed by name (not an array). Both forms are valid; handle
  both.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
