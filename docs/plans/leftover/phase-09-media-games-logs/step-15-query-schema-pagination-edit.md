# Step 09.15 — candy-sql schema browser + pagination + cell editing

**Source:** `leftover_updates_later.md` candy-sql P1-P3
**Branch:** `ai/query-schema-page-edit`

## Deliverable

Three TUI features:
- Schema browser via `PRAGMA table_info` / `index_list` / `foreign_key_list`.
- Result-set pagination.
- Cell-level editing.

## Files

**Modify / Create:**

- `candy-sql/src/SchemaBrowser.php`.
- `candy-sql/src/ResultPager.php`.
- `candy-sql/src/CellEditor.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-sql && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
