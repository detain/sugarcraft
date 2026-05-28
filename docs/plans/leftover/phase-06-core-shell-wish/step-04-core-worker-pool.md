# Step 06.04 — candy-core Worker pool for CPU-bound tasks

**Source:** `leftover_updates_later.md` candy-core §3.4
**Branch:** `ai/core-worker-pool`

## Deliverable

Worker pool for offloading CPU-bound work off the UI thread. Models
dispatch a `WorkerCmd::run(callable): Cmd` and receive a Msg with the
result when the worker finishes. ReactPHP-friendly.

## Files

**Create:**
- `candy-core/src/WorkerPool.php` — bounded concurrency (default 4
  workers).
- `candy-core/src/Cmd/WorkerCmd.php`.
- `candy-core/src/Msg/WorkerResultMsg.php`.

**Implementation note:** PHP has no real threads; "workers" here are
`pcntl_fork`'d processes communicating over pipes, or `proc_open`'d
subprocesses, or async ReactPHP promises. Use whichever ReactPHP
already provides (`React\Promise\async`) — simplest path.

**Tests:**
- `candy-core/tests/WorkerPoolTest.php`.

## Acceptance

- `cd candy-core && vendor/bin/phpunit --filter Worker` green.
- Worker pool bounded at default 4; configurable via constructor.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
