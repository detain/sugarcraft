# Step 08.06 — sugar-bits Table sort (sortBy / thenSortBy / clearSort)

**Source:** `leftover_updates_later.md` sugar-bits Phase 2 HIGH
**Branch:** `ai/bits-table-sort`

## Deliverable

`Table::withSort(string $column, SortDirection $dir = Asc)` plus
`thenSortBy()` for multi-column. `clearSort()` resets.

## Files

**Modify:**
- `sugar-bits/src/Table/Table.php` — sort state, comparator chain.

**Create:**
- `sugar-bits/src/Table/SortDirection.php` (enum).
- `sugar-bits/src/Table/SortState.php` (DTO).

## Tests

- `sugar-bits/tests/Table/SortTest.php`.

## Acceptance

- `cd sugar-bits && vendor/bin/phpunit --filter Sort` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
