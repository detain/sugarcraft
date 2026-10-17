# Step 03.05 — sugar-dashboard imports canonical Style/Color/Rect/Buffer/Cell/Theme/StyleParser

**Source:** `leftover_updates_later.md` Dash-03 + SSOT-01b..h
**Branch:** `ai/dash-canonical-primitives`
**Bundle hint:** depends on Phase 02 (Theme + StyleParser) and Phase 03 part 1 (Foundation move)

## Deliverable

Delete sugar-dashboard's `Foundation/Style.php`, `Foundation/Color.php`,
`Foundation/Rect.php`, `Foundation/Theme.php`, `Foundation/StyleParser.php`
and `Foundation/Buffer.php` + `Foundation/Cell.php`. Replace with imports
from canonical homes:

- `Style` → `\SugarCraft\Style\Style`
- `Color` → `\SugarCraft\Core\Util\Color`
- `Rect` → `\SugarCraft\Core\Rect`
- `Theme` → `\SugarCraft\Style\Theme` (from step 02.01)
- `StyleParser` → `\SugarCraft\Style\StyleParser` (from step 02.02)
- `Buffer` → `\SugarCraft\Vt\Buffer\Buffer`
- `Cell` → `\SugarCraft\Vt\Cell\Cell`

`Foundation/` shrinks to just `Drawable.php`, `Item.php`, `Sizer.php`
(sugar-dashboard-specific interfaces).

## Files

**Delete:** all six `Foundation/` duplicates.

**Modify:** every consumer in `sugar-dashboard/src/`, `tests/`, `examples/`
that imports `\SugarCraft\Dashboard\Foundation\Style` etc. — replace with
the canonical import.

**Modify:** `sugar-dashboard/composer.json`:
- `require` adds `"sugarcraft/sugar-style": "@dev"` and
  `"sugarcraft/sugar-vterm": "@dev"`.
- `repositories[]` adds path-repos for both.
- `tools/check-path-repos.php` green.

## Tests

`cd sugar-dashboard && vendor/bin/phpunit` green. Behaviour should be
byte-identical — sugar-dashboard's Style/Color/etc were either thin wrappers
or duplicates.

## Acceptance

- `wc -l sugar-dashboard/src/Foundation/*.php` < 200 LOC total.
- `grep -rn "class Style\|class Color\|class Theme\|class Rect\|class Buffer\|class Cell\|class StyleParser" sugar-dashboard/src/Foundation`
  returns nothing.
- `composer validate` clean.

## Notes

- This is the biggest SSOT cleanup of the rollout. After this lands,
  sugar-dashboard has exactly one canonical type per primitive.
- The Phase 02 steps MUST be merged first or this step's imports break.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
