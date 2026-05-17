# Step 07.11 — candy-mosaic Quarter-block Unicode renderer

**Source:** `leftover_updates_later.md` candy-mosaic P0 #3
**Branch:** `ai/mosaic-quarter-block`

## Deliverable

Add `QuarterBlockRenderer.php` — uses Unicode quarter-block characters
(`▘▝▖▗▀▄▌▐▙▟▛▜▞▚█ `) for 2x2 pixels-per-cell rendering. Higher
fidelity than HalfBlockRenderer for terminals without Sixel/Kitty.

## Files

**Create:**
- `candy-mosaic/src/Renderer/QuarterBlockRenderer.php` — implements
  `Renderer`. For each pair of cells (2 cols × 2 rows of pixels per
  cell), look up the corresponding glyph in a 16-entry table.

## Tests

- `candy-mosaic/tests/Renderer/QuarterBlockRendererTest.php` — render
  a fixture 4×4 image, assert exact output bytes.

## Acceptance

- `cd candy-mosaic && vendor/bin/phpunit --filter QuarterBlock` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
