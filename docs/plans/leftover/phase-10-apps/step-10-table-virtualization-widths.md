# Step 10.10 — sugar-table viewport virtualization + dynamic / percentage / content-based column widths + wrapping

**Source:** `leftover_updates_later.md` sugar-table
**Branch:** `ai/table-virtualization-widths`

## Deliverable

- Viewport virtualization for large datasets (render only visible rows).
- Dynamic / percentage / content-based column widths.
- Text wrapping inside cells (single-line wrap initially).

## Files

**Modify:** `sugar-table/src/Table.php` + new `ColumnWidth.php` /
`WrapMode.php` enums.

## Tests

- One per feature.

## Acceptance

- `cd sugar-table && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
