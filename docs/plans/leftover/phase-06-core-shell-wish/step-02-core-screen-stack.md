# Step 06.02 — sugar-core ScreenStack

**Source:** `leftover_updates_later.md` sugar-core §3.2
**Branch:** `ai/core-screen-stack`

## Deliverable

`Screen` + `ScreenStack` types for modal / sub-screen workflows. Today
every app hand-rolls modal management. Provide canonical push/pop with
breadcrumb tracking.

## Files

**Create:**

- `sugar-core/src/Screen.php` — value object: `Model`, optional title,
  `onEnter` / `onExit` lifecycle closures.
- `sugar-core/src/ScreenStack.php` — immutable stack with `push(Screen)`,
  `pop(): self`, `current(): Screen`, `breadcrumb(): array<string>`. <!-- @rename-keep -->
- `sugar-core/src/Cmd/PushScreenCmd.php` + `PopScreenCmd.php`.

**Modify:**

- `sugar-core/src/Program.php` — recognises ScreenStack-aware models
  by checking for a `screens(): ScreenStack` method; if present, drives
  the active screen's Model.

**Tests:**

- `sugar-core/tests/ScreenStackTest.php`.
- `sugar-core/tests/ProgramScreenStackIntegrationTest.php` — push three
  screens, pop two, verify state and breadcrumb.

## Acceptance

- `cd sugar-core && vendor/bin/phpunit --filter Screen` green.
- Example added: `sugar-core/examples/screen-stack.php` showing a 3-deep
  drill-down.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
