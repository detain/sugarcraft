# Step 10.18 — candy-codetime CSV/JSON export + tags + .sugartrackignore + gaps detection

**Source:** `leftover_updates_later.md` candy-codetime HIGH
**Branch:** `ai/tick-export-tags-ignore-gaps`

## Deliverable

- CSV / JSON export of tracked time.
- Tags on Heartbeat.
- `.sugartrackignore` file (ignore matching paths from tracking).
- Gaps detection / untracked-period reports.

## Files

**Modify:** `candy-codetime/src/Tracker.php`, `Heartbeat.php`.

**Create:** `candy-codetime/src/Export/{CsvExporter,JsonExporter}.php`,
`Ignore/SugarTrackIgnore.php`, `Report/GapsReport.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-codetime && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
