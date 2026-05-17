# Step 09.12 — candy-log CallerFormatter + slog/PSR-3 + hooks + PartsOrder

**Source:** `leftover_updates_later.md` candy-log §4.1 #7-10
**Branch:** `ai/log-caller-hooks-partsorder`

## Deliverable

Four enhancements:
- `CallerFormatter` — captures `file:line` of the caller.
- slog / PSR-3 handler bridge.
- Hook system (`onLevel(Level, callable)`).
- Configurable `PartsOrder` (timestamp / level / msg / fields).

## Files

**Create:**
- `candy-log/src/CallerFormatter.php`.
- `candy-log/src/PsrBridge.php`.
- `candy-log/src/Hook/Hook.php` (interface) + `HookRegistry.php`.
- `candy-log/src/PartsOrder.php` (config DTO).

## Tests

- One per feature.

## Acceptance

- `cd candy-log && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
