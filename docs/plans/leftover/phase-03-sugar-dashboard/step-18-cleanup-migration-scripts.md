# Step 03.18 — sugar-dashboard cleanup: delete one-shot migration scripts + rename misnamed example

**Source:** `leftover_updates_later.md` Dash-15
**Branch:** `ai/dash-cleanup-scripts`

## Deliverable

`sugar-dashboard/` root has stale one-shot migration scripts from the
stalled Phase-0 reorg. After step 03.03 finished the reorg, these are
dead code. Plus, `dashboard-interactive.php` is misnamed (it's static).

## Files

**Delete:**

- `sugar-dashboard/delete_grid_files.php`
- `sugar-dashboard/delete_grid_files.py`
- `sugar-dashboard/delete_grid.sh`
- `sugar-dashboard/delete-moved-files.sh`
- `sugar-dashboard/migrate.sh`
- `sugar-dashboard/update-example-namespaces.php`
- `sugar-dashboard/generate-tapes.php` (if also stale)

(Move to `sugar-dashboard/scripts/legacy/` first if the user might want them
for archival; otherwise just delete. Recommend delete — they're not
useful post-reorg.)

**Rename:**

- `sugar-dashboard/examples/dashboard-interactive.php` →
  `sugar-dashboard/examples/dashboard-accordion-timeline.php` (since it's a
  static accordion + timeline render, not interactive).
- Update any references in `README.md` and the homepage tile.

**Update:**

- `sugar-dashboard/README.md` "Examples" section — fix references.
- `sugar-dashboard/.gitignore` — if any of the deleted scripts created
  artifacts, ensure those artifacts are ignored.

## Acceptance

- `ls sugar-dashboard/*.{php,sh,py} 2>/dev/null` lists only legitimate
  files (composer.json, phpunit.xml, README.md — no migration
  scripts).
- `grep -rn "dashboard-interactive" sugar-dashboard` returns nothing.
- `php sugar-dashboard/examples/dashboard-accordion-timeline.php` runs.

## Notes

- This is the final sugar-dashboard cleanup. After this lands, the headline
  lib is in a coherent state for phase 04+ work.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
