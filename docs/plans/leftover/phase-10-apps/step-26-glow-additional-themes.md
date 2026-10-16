# Step 10.26 — candy-mdview additional themes (Solarized / Monokai / GitHub)

**Source:** `leftover_updates_later.md` candy-mdview §7.6 P2
**Branch:** `ai/glow-themes`

## Deliverable

Ship Solarized, Monokai, and GitHub stock themes alongside existing
ones. Consume candy-sprinkles' Theme palette (step 02.01) where
applicable.

## Files

**Create:** `candy-mdview/themes/{solarized,monokai,github}.json`.

**Modify:** README documents `--theme=<name>`.

## Tests

- `candy-mdview/tests/ThemeLoadTest.php` — each theme loads.

## Acceptance

- `cd candy-mdview && vendor/bin/phpunit --filter Theme` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
