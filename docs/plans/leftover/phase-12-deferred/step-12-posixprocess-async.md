# Step 12.12 — PosixProcess async support (DEFERRED)

**Source:** `leftover_updates.md` deferred section
**Branch:** `ai/posixprocess-async`
**Deferred per user instruction.**

## Deliverable

Current `PosixProcess` is blocking-`wait()`-based. Add
`promise(): \React\Promise\PromiseInterface<int>` for ReactPHP / Amp
users. Layered on top of `proc_get_status` polling.

## Files

**Modify:**
- `candy-pty/src/Posix/PosixProcess.php`.
- `candy-pty/composer.json` — `require-dev` `react/promise`.

## Tests

- `candy-pty/tests/Posix/PosixProcessPromiseTest.php`.

## Acceptance

- `cd candy-pty && vendor/bin/phpunit --filter PosixProcessPromise` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
