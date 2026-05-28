# Step 04.03 — sugar-crumbs click regions via candy-zone

**Source:** `leftover_updates_later.md` SSOT-04 + sugar-crumbs research P4
**Branch:** `ai/crumbs-uses-zone`

## Deliverable

sugar-crumbs research P4 calls for "Click-region rendering". `candy-zone`
is canonical for zone-tracking (APC marker + scan algorithm). Wire
crumbs to emit zone markers via candy-zone.

## Files

**Modify:**
- `sugar-crumbs/src/Crumb.php` (or wherever the render method lives) —
  wrap each crumb's render output in `\SugarCraft\Zone\Manager::mark(...)`
  emit/exit calls so click coordinates are mappable back to the crumb.
- `sugar-crumbs/composer.json` — add `"sugarcraft/candy-zone": "@dev"`
  + path-repo.
- `sugar-crumbs/src/NavStack.php` — when a click within a crumb zone
  is reported, dispatch `pushDirectory()` / `view()` (handled in
  step 10.21).

## Acceptance

- `grep -n "APC\|zoneMark" sugar-crumbs/src` shows calls into candy-zone,
  not inline coordinate math.
- `cd sugar-crumbs && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
