# Step 10.19 — candy-codetime SQLite backend + milestones + iCal export + auto-backups + theme system

**Source:** `leftover_updates_later.md` candy-codetime MEDIUM
**Branch:** `ai/tick-sqlite-ical-themes`

## Deliverable

- SQLite backend (replaces / supplements JSONL).
- Milestones (named time-points; "shipped v1.0 here").
- iCal export.
- Auto-backups (daily rotation).
- Theme system (consume candy-sprinkles Theme from step 02.01).

## Files

**Create:** `candy-codetime/src/Storage/SqliteBackend.php`,
`Milestone.php`, `Export/IcalExporter.php`, `Backup/AutoBackup.php`.

**Modify:** add candy-sprinkles dep.

## Tests

- One per feature.

## Acceptance

- `cd candy-codetime && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
