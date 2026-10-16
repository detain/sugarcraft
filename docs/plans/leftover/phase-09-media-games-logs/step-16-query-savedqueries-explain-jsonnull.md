# Step 09.16 — candy-sql saved queries + explain plan + h-scroll + JSON/NULL

**Source:** `leftover_updates_later.md` candy-sql P4-P7
**Branch:** `ai/query-saved-explain-scroll-jsonnull`

## Deliverable

- Saved queries / snippets persistence.
- `EXPLAIN QUERY PLAN` display.
- Horizontal scrolling for wide rows.
- JSON / NULL formatting (today displays raw `NULL` strings).

## Files

**Create/Modify:**

- `candy-sql/src/SnippetStore.php`.
- `candy-sql/src/ExplainView.php`.
- `candy-sql/src/ResultTable.php` — h-scroll + JSON pretty print.

## Tests

- One per feature.

## Acceptance

- `cd candy-sql && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
