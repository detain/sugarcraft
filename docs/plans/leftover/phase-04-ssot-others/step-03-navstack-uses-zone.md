# Step 04.03 — sugar-navstack click regions via candy-mouse

**Source:** `leftover_updates_later.md` SSOT-04 + sugar-navstack research P4
**Branch:** `ai/navstack-uses-zone`

## Deliverable

sugar-navstack research P4 calls for "Click-region rendering". `candy-mouse`
is canonical for zone-tracking (APC marker + scan algorithm). Wire
crumbs to emit zone markers via candy-mouse.

## Files

**Modify:**

- `sugar-navstack/src/Crumb.php` (or wherever the render method lives) —
  wrap each crumb's render output in `\SugarCraft\Mouse\Zones\Manager::mark(...)`
  emit/exit calls so click coordinates are mappable back to the crumb.
- `sugar-navstack/composer.json` — add `"sugarcraft/candy-mouse": "@dev"`
  + path-repo.
- `sugar-navstack/src/NavStack.php` — when a click within a crumb zone
  is reported, dispatch `pushDirectory()` / `view()` (handled in
  step 10.21).

## Acceptance

- `grep -n "APC\|zoneMark" sugar-navstack/src` shows calls into candy-mouse,
  not inline coordinate math.
- `cd sugar-navstack && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
