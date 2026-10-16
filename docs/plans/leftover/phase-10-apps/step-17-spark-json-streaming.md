# Step 10.17 — candy-ansi-inspect reportAsJson + StreamingInspector

**Source:** `leftover_updates_later.md` candy-ansi-inspect §5 P4-P5
**Branch:** `ai/spark-json-streaming`

## Deliverable

- `Inspector::reportAsJson(): string` — machine-readable output.
- `StreamingInspector` — incremental parser for piped input.

## Files

**Modify:** `candy-ansi-inspect/src/Inspector.php` — add `reportAsJson()`.

**Create:** `candy-ansi-inspect/src/StreamingInspector.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-ansi-inspect && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
