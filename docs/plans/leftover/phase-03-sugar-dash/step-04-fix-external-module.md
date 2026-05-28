# Step 03.04 — Fix sugar-dash Plugin/ExternalModule.php

**Source:** `leftover_updates_later.md` Dash-02 + sugar-dash agent audit
**Branch:** `ai/dash-fix-external-module`

## Deliverable

`sugar-dash/src/Plugin/ExternalModule.php` is **broken at the language
level**: it calls `proc_get_status($this->process)['pipes']`, a key
that does not exist on the return shape of `proc_get_status()`. Every
external-process plugin invocation fails on the first request.

Fix two ways:

1. Store the pipes array as `private array $pipes` in the class state.
2. Migrate the entire spawn to `\SugarCraft\Pty\Posix\PosixProcess`
   for zombie-reap safety, exit-code propagation, stderr capture.

## Files

**Modify:**
- `sugar-dash/src/Plugin/ExternalModule.php`:
  - Add `private array $pipes`.
  - Replace `proc_open(...)` with `new PosixProcess(...)`.
  - `sendRequest()` writes to `$this->pipes[0]` (stdin).
  - `readResponse()` reads from `$this->pipes[1]` (stdout).
- `sugar-dash/composer.json` — add `"sugarcraft/candy-pty": "@dev"`
  to `require` (was transitive; now direct). Add path-repo per
  `tools/check-path-repos.php`.

**Create:**
- `sugar-dash/tests/fixtures/echo-plugin.sh` — 20-line shell script
  reading line-delimited JSON from stdin, echoing back a valid
  `Response`.
- `sugar-dash/tests/Plugin/ExternalModuleRoundTripTest.php` — boots
  the fixture, sends `init` / `update` / `view` requests, asserts
  correct response shape on each. **No mocks** — real subprocess.

**Mark existing for review:**
- `sugar-dash/tests/Plugin/ExternalModuleTest.php` — if it exists and
  passed in spite of the bug, it must have been mocked. Either delete
  or rewrite as a unit test of pure DTO logic.

## Acceptance

- `grep -n "proc_get_status.*pipes" sugar-dash/src/Plugin/ExternalModule.php`
  returns nothing.
- `cd sugar-dash && vendor/bin/phpunit --filter ExternalModule` green
  (the integration test uses the fixture).
- `php tools/check-path-repos.php` green.

## Notes

- This is the single most critical sugar-dash bug found in the audit.
- After this step, plugin protocol works end-to-end for any executable
  language (sh, python, bash, php).

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
