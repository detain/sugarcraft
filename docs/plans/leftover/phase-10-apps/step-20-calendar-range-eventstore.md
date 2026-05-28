# Step 10.20 — sugar-calendar date range select + kb nav + EventStore

**Source:** `leftover_updates_later.md` sugar-calendar §3.1 + Phase 2
**Branch:** `ai/calendar-range-eventstore`

## Deliverable

- Date range selection (start + end).
- Focus-based keyboard navigation (arrows + Enter).
- `EventStore` architecture (DI-friendly event source).

## Files

**Create:** `sugar-calendar/src/DateRange.php`, `EventStore.php`,
`Navigation.php`.

**Modify:** `DatePicker.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-calendar && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
