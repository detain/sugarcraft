# Step 08.08 — sugar-bits Table pagination + Paginator nav

**Source:** `leftover_updates_later.md` sugar-bits Phase 3-4
**Branch:** `ai/bits-table-paginate`

## Deliverable

`Table::withPageSize(int)` plus a `Paginator` widget with
`pageFirst()`, `pageLast()`, `withPage(int)`, `nextPage()`,
`prevPage()`.

## Files

**Modify:**
- `sugar-bits/src/Table/Table.php` — page-size handling, current-page
  state.

**Create:**
- `sugar-bits/src/Paginator/Paginator.php`.

## Tests

- `sugar-bits/tests/Table/PaginationTest.php`.
- `sugar-bits/tests/Paginator/PaginatorTest.php`.

## Acceptance

- `cd sugar-bits && vendor/bin/phpunit --filter "Pagination|Paginator"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
