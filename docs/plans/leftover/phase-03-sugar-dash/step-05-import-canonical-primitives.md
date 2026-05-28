# Step 03.05 — sugar-dash imports canonical Style/Color/Rect/Buffer/Cell/Theme/StyleParser

**Source:** `leftover_updates_later.md` Dash-03 + SSOT-01b..h
**Branch:** `ai/dash-canonical-primitives`
**Bundle hint:** depends on Phase 02 (Theme + StyleParser) and Phase 03 part 1 (Foundation move)

## Deliverable

Delete sugar-dash's `Foundation/Style.php`, `Foundation/Color.php`,
`Foundation/Rect.php`, `Foundation/Theme.php`, `Foundation/StyleParser.php`
and `Foundation/Buffer.php` + `Foundation/Cell.php`. Replace with imports
from canonical homes:

- `Style` → `\SugarCraft\Sprinkles\Style`
- `Color` → `\SugarCraft\Core\Util\Color`
- `Rect` → `\SugarCraft\Core\Rect`
- `Theme` → `\SugarCraft\Sprinkles\Theme` (from step 02.01)
- `StyleParser` → `\SugarCraft\Sprinkles\StyleParser` (from step 02.02)
- `Buffer` → `\SugarCraft\Vt\Buffer\Buffer`
- `Cell` → `\SugarCraft\Vt\Cell\Cell`

`Foundation/` shrinks to just `Drawable.php`, `Item.php`, `Sizer.php`
(sugar-dash-specific interfaces).

## Files

**Delete:** all six `Foundation/` duplicates.

**Modify:** every consumer in `sugar-dash/src/`, `tests/`, `examples/`
that imports `\SugarCraft\Dash\Foundation\Style` etc. — replace with
the canonical import.

**Modify:** `sugar-dash/composer.json`:
- `require` adds `"sugarcraft/candy-sprinkles": "@dev"` and
  `"sugarcraft/candy-vt": "@dev"`.
- `repositories[]` adds path-repos for both.
- `tools/check-path-repos.php` green.

## Tests

`cd sugar-dash && vendor/bin/phpunit` green. Behaviour should be
byte-identical — sugar-dash's Style/Color/etc were either thin wrappers
or duplicates.

## Acceptance

- `wc -l sugar-dash/src/Foundation/*.php` < 200 LOC total.
- `grep -rn "class Style\|class Color\|class Theme\|class Rect\|class Buffer\|class Cell\|class StyleParser" sugar-dash/src/Foundation`
  returns nothing.
- `composer validate` clean.

## Notes

- This is the biggest SSOT cleanup of the rollout. After this lands,
  sugar-dash has exactly one canonical type per primitive.
- The Phase 02 steps MUST be merged first or this step's imports break.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
