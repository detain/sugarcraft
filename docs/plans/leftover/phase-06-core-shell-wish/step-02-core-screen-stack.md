# Step 06.02 — candy-core ScreenStack

**Source:** `leftover_updates_later.md` candy-core §3.2
**Branch:** `ai/core-screen-stack`

## Deliverable

`Screen` + `ScreenStack` types for modal / sub-screen workflows. Today
every app hand-rolls modal management. Provide canonical push/pop with
breadcrumb tracking.

## Files

**Create:**
- `candy-core/src/Screen.php` — value object: `Model`, optional title,
  `onEnter` / `onExit` lifecycle closures.
- `candy-core/src/ScreenStack.php` — immutable stack with `push(Screen)`,
  `pop(): self`, `current(): Screen`, `breadcrumb(): array<string>`.
- `candy-core/src/Cmd/PushScreenCmd.php` + `PopScreenCmd.php`.

**Modify:**
- `candy-core/src/Program.php` — recognises ScreenStack-aware models
  by checking for a `screens(): ScreenStack` method; if present, drives
  the active screen's Model.

**Tests:**
- `candy-core/tests/ScreenStackTest.php`.
- `candy-core/tests/ProgramScreenStackIntegrationTest.php` — push three
  screens, pop two, verify state and breadcrumb.

## Acceptance

- `cd candy-core && vendor/bin/phpunit --filter Screen` green.
- Example added: `candy-core/examples/screen-stack.php` showing a 3-deep
  drill-down.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
