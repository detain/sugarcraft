# Step 03.01 — sugar-dash Grid reorg part 1: Foundation primitives + Layout enums

**Source:** `leftover_updates_later.md` Dash-01 (part 1 of 3) + sugar-dash agent finding
**Branch:** `ai/dash-reorg-foundation`
**Bundle hint:** part 1 of 3 in the Phase-0 reorg

## Deliverable

`sugar-dash/src/Grid/` holds ~74 files from the stalled Phase-0 reorg
(plans/dash_update_claude.md). Move the foundation-tier files
(Item/Sizer/Options/ItemOptions/ItemWithOptions/StackedGrid/Buffer
+ layout enums) into their proper new homes. Update every consumer's
`use` lines. **Delete** the originals (no aliases — pre-1.0).

## Files

**Move (from `src/Grid/`):**
- `Item.php` → `src/Foundation/Item.php`
- `Sizer.php` → `src/Foundation/Sizer.php`
- `Options.php`, `ItemOptions.php`, `ItemWithOptions.php` →
  `src/Layout/Grid/`
- `StackedGrid.php` → `src/Layout/Grid/StackedGrid.php`
- `Buffer.php` → DELETE (duplicate of `Foundation/Buffer.php`; the
  Foundation version stays)
- Layout enums `JustifyContent.php`, `AlignItems.php`,
  `HAlign.php`, `VAlign.php`, `FlexDirection.php`, `FlexWrap.php`,
  `Center.php` → `src/Layout/`
- `Focus.php`, `KeyMap.php` → keep in `Events/` and `Keys/`
  respectively (consolidating in step 03.03).

**Update:** every `use SugarCraft\Dash\Grid\<Name>` import across:
- `src/` — every file that referenced the moved classes.
- `tests/` — every test that referenced them.
- `examples/` — every example.

## Tests

- `cd sugar-dash && vendor/bin/phpunit` green after the move.
- New files have tests under `tests/Foundation/`, `tests/Layout/Grid/`,
  `tests/Layout/`.

## Acceptance

- `find sugar-dash/src/Grid -name 'Item.php' -o -name 'Sizer.php' -o -name 'StackedGrid.php' -o -name 'Buffer.php' -o -name 'Options.php' -o -name 'ItemOptions.php' -o -name 'ItemWithOptions.php'` returns nothing.
- `find sugar-dash/src/Grid -name 'JustifyContent.php' …` etc returns nothing.
- All 16 example files run.
- PHPUnit green.

## Notes

- Pre-1.0 — no aliases needed. Atomic move + update consumers.
- This is part 1 of 3; parts 2 and 3 (charts, events/state) are
  separate steps. Do NOT try to bundle.
- `examples/dashboard-*.php` will likely need most updates; bulk
  search-and-replace.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
