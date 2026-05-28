# Step 03.18 — sugar-dash cleanup: delete one-shot migration scripts + rename misnamed example

**Source:** `leftover_updates_later.md` Dash-15
**Branch:** `ai/dash-cleanup-scripts`

## Deliverable

`sugar-dash/` root has stale one-shot migration scripts from the
stalled Phase-0 reorg. After step 03.03 finished the reorg, these are
dead code. Plus, `dashboard-interactive.php` is misnamed (it's static).

## Files

**Delete:**
- `sugar-dash/delete_grid_files.php`
- `sugar-dash/delete_grid_files.py`
- `sugar-dash/delete_grid.sh`
- `sugar-dash/delete-moved-files.sh`
- `sugar-dash/migrate.sh`
- `sugar-dash/update-example-namespaces.php`
- `sugar-dash/generate-tapes.php` (if also stale)

(Move to `sugar-dash/scripts/legacy/` first if the user might want them
for archival; otherwise just delete. Recommend delete — they're not
useful post-reorg.)

**Rename:**
- `sugar-dash/examples/dashboard-interactive.php` →
  `sugar-dash/examples/dashboard-accordion-timeline.php` (since it's a
  static accordion + timeline render, not interactive).
- Update any references in `README.md` and the homepage tile.

**Update:**
- `sugar-dash/README.md` "Examples" section — fix references.
- `sugar-dash/.gitignore` — if any of the deleted scripts created
  artifacts, ensure those artifacts are ignored.

## Acceptance

- `ls sugar-dash/*.{php,sh,py} 2>/dev/null` lists only legitimate
  files (composer.json, phpunit.xml, README.md — no migration
  scripts).
- `grep -rn "dashboard-interactive" sugar-dash` returns nothing.
- `php sugar-dash/examples/dashboard-accordion-timeline.php` runs.

## Notes

- This is the final sugar-dash cleanup. After this lands, the headline
  lib is in a coherent state for phase 04+ work.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
