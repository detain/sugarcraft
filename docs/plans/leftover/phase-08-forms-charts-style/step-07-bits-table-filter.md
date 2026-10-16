# Step 08.07 — sugar-widgets Table filtering (withFilterable)

**Source:** `leftover_updates_later.md` sugar-widgets Phase 2 HIGH
**Branch:** `ai/bits-table-filter`

## Deliverable

`Table::withFilterable(bool)` plus `withFilter(string $query)` and
optional `withFilterPredicate(callable)` for custom filtering.

## Files

**Modify:**

- `sugar-widgets/src/Table/Table.php` — filter state, indexed apply.

## Tests

- `sugar-widgets/tests/Table/FilterTest.php`.

## Acceptance

- `cd sugar-widgets && vendor/bin/phpunit --filter Filter` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
