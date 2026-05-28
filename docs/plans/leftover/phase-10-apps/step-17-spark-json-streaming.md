# Step 10.17 — sugar-spark reportAsJson + StreamingInspector

**Source:** `leftover_updates_later.md` sugar-spark §5 P4-P5
**Branch:** `ai/spark-json-streaming`

## Deliverable

- `Inspector::reportAsJson(): string` — machine-readable output.
- `StreamingInspector` — incremental parser for piped input.

## Files

**Modify:** `sugar-spark/src/Inspector.php` — add `reportAsJson()`.

**Create:** `sugar-spark/src/StreamingInspector.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-spark && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
